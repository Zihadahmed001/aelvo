<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Evidence-only exception workflow. Review never verifies charges, releases orders, or posts ledger entries. */
final class GatewayExceptions extends Service {
    public const TYPES = [
        'uncertain_outcome'=>'Gateway session / outcome uncertain',
        'validation_discrepancy'=>'Provider validation or callback mismatch',
        'risk_review'=>'Provider risk hold',
        'settlement_variance'=>'Settlement amount or fee mismatch',
        'provider_dispute'=>'Provider dispute or chargeback notification',
    ];

    /** No generic "resolved" outcome: only actual provider-validated workflows can change gateway state. */
    public static function applicable(string $issue,string $state): bool {
        return match($issue) {
            'uncertain_outcome'=>in_array($state,['Initiating','Ready','Uncertain','Review'],true),
            'validation_discrepancy'=>in_array($state,['Review','FailedVerified','CancelledVerified','Verified'],true),
            'risk_review'=>$state==='RiskHold',
            'settlement_variance'=>$state==='Verified',
            'provider_dispute'=>in_array($state,['Verified','RiskHold','Review'],true),
            default=>false,
        };
    }
    public static function fields(array $input): array {
        $issue=Input::text($input,'issue_type',40);
        if(!isset(self::TYPES[$issue]))throw new InvalidArgumentException('Choose a recognized gateway exception.');
        $reference=Input::text($input,'provider_reference',120);
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.,:\/\-]{2,119}$/D',$reference))throw new InvalidArgumentException('Enter a non-sensitive provider/statement case reference.');
        $details=Input::text($input,'details',1500);
        if(strlen($details)<20 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$details))throw new InvalidArgumentException('Explain the exception without secrets, card numbers or customer credentials.');
        if(($input['evidence_checked']??'')!=='1'||($input['no_financial_action']??'')!=='1')throw new InvalidArgumentException('Confirm independent provider evidence and the no-financial-action rule.');
        return [$issue,$reference,$details];
    }
    private function evidence(string $id): void {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Upload private supporting evidence.');
        $q=$this->db->prepare('SELECT id FROM aelvo_media WHERE id=? AND owner_id=? AND is_public=0');
        $q->execute([$id,$this->actor['id']]);
        if(!$q->fetchColumn())throw new InvalidArgumentException('Private evidence must belong to the submitting staff member.');
    }
    private function unsettled(string $attempt): ?int {
        $q=$this->db->prepare('SELECT amount_minor FROM aelvo_gateway_receipts WHERE attempt_id=? FOR UPDATE');
        $q->execute([$attempt]);$gross=$q->fetchColumn();
        if($gross===false)return null;
        $q=$this->db->prepare('SELECT COALESCE(SUM(received_minor+fee_minor),0) FROM aelvo_gateway_settlements WHERE attempt_id=?');
        $q->execute([$attempt]);$legacy=(int)$q->fetchColumn();
        $q=$this->db->prepare('SELECT COALESCE(SUM(credited_minor+fee_minor),0) FROM aelvo_gateway_payout_allocations WHERE attempt_id=?');
        $q->execute([$attempt]);
        return max(0,(int)$gross-$legacy-(int)$q->fetchColumn());
    }
    public function propose(array $input): int {
        $this->allow('payments','edit');$this->allow('finance','edit');
        $attempt=Input::text($input,'attempt_id',32);
        if(!preg_match('/^[a-f0-9]{32}$/D',$attempt))throw new InvalidArgumentException('Invalid gateway attempt.');
        [$type,$reference,$details]=self::fields($input);
        $document=Input::text($input,'document_id',32);
        $raw=Input::text($input,'request_key',64);
        if(!preg_match('/^[a-f0-9]{64}$/D',$raw))throw new InvalidArgumentException('Reload the payment review form.');
        $key=hash('sha256','gateway-exception:'.$this->actor['id'].':'.$raw);
        $hash=hash('sha256',json_encode([$attempt,$type,$reference,$details,$document],JSON_THROW_ON_ERROR));
        return $this->transaction(function()use($attempt,$type,$reference,$details,$document,$key,$hash):int {
            // Lock in the common order -> attempt sequence used by payment writers.
            $q=$this->db->prepare('SELECT order_id FROM aelvo_gateway_attempts WHERE id=?');$q->execute([$attempt]);$order=$q->fetchColumn();
            if($order===false)throw new InvalidArgumentException('Gateway attempt not found.');
            $q=$this->db->prepare('SELECT id FROM aelvo_orders WHERE id=? FOR UPDATE');$q->execute([$order]);
            if(!$q->fetchColumn())throw new RuntimeException('Order no longer exists.',409);
            $q=$this->db->prepare('SELECT id,state FROM aelvo_gateway_attempts WHERE id=? FOR UPDATE');$q->execute([$attempt]);$a=$q->fetch(PDO::FETCH_ASSOC);
            if(!$a)throw new InvalidArgumentException('Gateway attempt missing.');
            $q=$this->db->prepare('SELECT id,request_hash FROM aelvo_gateway_exception_cases WHERE request_key=?');$q->execute([$key]);$old=$q->fetch();
            if($old){if(!hash_equals((string)$old['request_hash'],$hash))throw new RuntimeException('The submitted case changed; reload.',409);return (int)$old['id'];}
            if(!self::applicable($type,(string)$a['state']))throw new InvalidArgumentException('The selected issue does not apply to the current gateway state.');
            $q=$this->db->prepare("SELECT id FROM aelvo_gateway_exception_cases WHERE attempt_id=? AND state='Pending' LIMIT 1 FOR UPDATE");$q->execute([$attempt]);
            if($q->fetchColumn()!==false)throw new RuntimeException('An investigation is already awaiting independent review.',409);
            $unsettled=$this->unsettled($attempt);
            if($type==='settlement_variance'&&$unsettled===null)throw new InvalidArgumentException('Provider-validated charge required before investigating settlement.');
            $this->evidence($document);
            $q=$this->db->prepare('INSERT INTO aelvo_gateway_exception_cases(attempt_id,issue_type,observed_state,observed_unsettled_minor,provider_reference,details,evidence_id,request_key,request_hash,proposed_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$attempt,$type,$a['state'],$unsettled,$reference,$details,$document,$key,$hash,$this->actor['id']]);
            $id=(int)$this->db->lastInsertId();$this->audit('gateway.exception-filed:'.$id);return $id;
        });
    }
    public function review(array $input): void {
        $this->allow('payments','approve');$this->allow('finance','view');
        $id=Input::integer($input['case_id']??null);
        $decision=Input::text($input,'decision',10);
        if(!in_array($decision,['review','reject'],true)||($input['evidence_reviewed']??'')!=='1')throw new InvalidArgumentException('Independent evidence review required.');
        $notes=Input::text($input,'review_notes',1500);
        if(strlen($notes)<15||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$notes))throw new InvalidArgumentException('Explain the review decision.');
        $this->transaction(function()use($id,$decision,$notes):void {
            $q=$this->db->prepare('SELECT a.order_id FROM aelvo_gateway_exception_cases c JOIN aelvo_gateway_attempts a ON a.id=c.attempt_id WHERE c.id=?');$q->execute([$id]);$order=$q->fetchColumn();
            if($order===false)throw new InvalidArgumentException('Investigation case missing.');
            $q=$this->db->prepare('SELECT id FROM aelvo_orders WHERE id=? FOR UPDATE');$q->execute([$order]);if(!$q->fetchColumn())throw new RuntimeException('Order changed.',409);
            $q=$this->db->prepare('SELECT a.id,a.state FROM aelvo_gateway_exception_cases c JOIN aelvo_gateway_attempts a ON a.id=c.attempt_id WHERE c.id=? FOR UPDATE');$q->execute([$id]);$attempt=$q->fetch();
            $q=$this->db->prepare('SELECT * FROM aelvo_gateway_exception_cases WHERE id=? FOR UPDATE');$q->execute([$id]);$case=$q->fetch();
            if(!$case||!$attempt||$case['state']!=='Pending')throw new RuntimeException('Case already reviewed; refresh the queue.',409);
            if((int)$case['proposed_by']===(int)$this->actor['id'])throw new RuntimeException('An independent second staff reviewer is required.',403);
            if($attempt['state']!==$case['observed_state'])throw new RuntimeException('Gateway state changed since filing. Reconcile the new state before reviewing.',409);
            if($case['observed_unsettled_minor']!==null && $this->unsettled((string)$case['attempt_id'])!==(int)$case['observed_unsettled_minor'])throw new RuntimeException('Settlement changed since filing; open a new case with fresh evidence.',409);
            $q=$this->db->prepare("UPDATE aelvo_gateway_exception_cases SET state=?,reviewed_by=?,review_notes=?,reviewed_at=UTC_TIMESTAMP() WHERE id=? AND state='Pending'");
            $q->execute([$decision==='review'?'Reviewed':'Rejected',$this->actor['id'],$notes,$id]);
            if($q->rowCount()!==1)throw new RuntimeException('Review conflict; reload.',409);
            $this->audit('gateway.exception-'.($decision==='review'?'reviewed':'rejected').':'.$id);
            // Intentionally NO UPDATE to aelvo_gateway_attempts, receipts, orders, accounts, stock or ledger.
        });
    }
    public function listCases(int $limit=70): array {
        $this->allow('payments','view');$this->allow('finance','view');
        $limit=max(1,min(100,$limit));
        $q=$this->db->prepare('SELECT c.*,a.tran_id,a.order_id,a.state AS current_gateway_state,p.name AS proposer_name,r.name AS reviewer_name FROM aelvo_gateway_exception_cases c JOIN aelvo_gateway_attempts a ON a.id=c.attempt_id JOIN aelvo_users p ON p.id=c.proposed_by LEFT JOIN aelvo_users r ON r.id=c.reviewed_by ORDER BY (c.state=\'Pending\') DESC,c.created_at DESC,c.id DESC LIMIT ?');
        $q->bindValue(1,$limit,PDO::PARAM_INT);$q->execute();return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
