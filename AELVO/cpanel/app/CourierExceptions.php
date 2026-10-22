<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Evidence-only courier exceptions. Does NOT request courier cancellation, refund or stock movement. */
final class CourierExceptions extends Service {
    public const TYPES = [
        'cancellation_requested' => 'Cancellation requested in merchant portal (not confirmed)',
        'provider_cancelled' => 'Provider cancellation confirmed',
        'delivery_failed' => 'Failed delivery / attempted delivery',
        'return_to_sender_reported' => 'Return-to-sender reported (physical receipt NOT confirmed)',
    ];

    public static function fields(array $input): array {
        $type=Input::text($input,'exception_type',40);
        if(!array_key_exists($type,self::TYPES))throw new InvalidArgumentException('Unknown courier exception type.');
        $reference=Input::text($input,'portal_reference',120);
        $reason=Input::text($input,'exception_notes',1500);
        if(preg_match('/[\x00-\x1f\x7f]/',$reference) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$reason))throw new InvalidArgumentException('Control characters are not permitted.');
        if(($input['portal_checked']??'')!=='1'||($input['no_financial_action']??'')!=='1')
            throw new InvalidArgumentException('Confirm the portal evidence and that no payment, order or inventory operation is performed.');
        if($type==='provider_cancelled'&&($input['final_status_checked']??'')!=='1')
            throw new InvalidArgumentException('Verify final provider cancellation, not a pending approval.');
        return [$type,$reference,$reason];
    }

    private function evidence(string $document): void {
        if(!preg_match('/^[a-f0-9]{32}$/D',$document))throw new InvalidArgumentException('Private evidence is required.');
        $q=$this->db->prepare('SELECT id FROM aelvo_media WHERE id=? AND owner_id=? AND is_public=0');
        $q->execute([$document,$this->actor['id']]);
        if(!$q->fetchColumn())throw new InvalidArgumentException('Evidence must be the proposing staff member’s private upload.');
    }

    /** Prepare a review; never contact the provider or change operational state. */
    public function propose(array $input): int {
        $this->allow('courier','edit');
        $shipment=Input::integer($input['shipment_id']??null);
        [$type,$reference,$notes]=self::fields($input);
        $document=Input::text($input,'document_id',32);
        $requestKey=Input::text($input,'request_key',64);
        if(!preg_match('/^[a-f0-9]{64}$/D',$requestKey))throw new InvalidArgumentException('Reload the courier exception form.');
        $key=hash('sha256','courier-exception:'.$this->actor['id'].':'.$requestKey);
        $hash=hash('sha256',json_encode([$shipment,$type,$reference,$notes],JSON_THROW_ON_ERROR));
        return $this->transaction(function()use($shipment,$type,$reference,$notes,$document,$key,$hash): int {
            $q=$this->db->prepare('SELECT id,booking_state,invoice,remote_status FROM aelvo_courier_shipments WHERE id=? FOR UPDATE');
            $q->execute([$shipment]);$ship=$q->fetch();
            if(!$ship||$ship['booking_state']!=='Booked')throw new InvalidArgumentException('Reconcile an uncertain booking first. Only booked shipments can report exceptions.');
            $q=$this->db->prepare('SELECT id,request_hash FROM aelvo_courier_exception_cases WHERE request_key=?');
            $q->execute([$key]);$existing=$q->fetch();
            if($existing){if(!hash_equals((string)$existing['request_hash'],$hash))throw new RuntimeException('Exception request changed; reload the form.',409);return (int)$existing['id'];}
            $q=$this->db->prepare("SELECT id FROM aelvo_courier_exception_cases WHERE shipment_id=? AND state='Pending' LIMIT 1 FOR UPDATE");
            $q->execute([$shipment]);if($q->fetchColumn()!==false)throw new RuntimeException('A courier exception is already waiting for independent review.',409);
            $q=$this->db->prepare("SELECT id FROM aelvo_courier_exception_cases WHERE shipment_id=? AND exception_type=? AND state='Approved' LIMIT 1");
            $q->execute([$shipment,$type]);if($q->fetchColumn()!==false)throw new RuntimeException('That courier exception is already approved. It cannot be silently recorded twice.',409);
            // Final cancellation must be visible as FINAL in the provider snapshot.
            if($type==='provider_cancelled'&&$ship['remote_status']!=='cancelled')throw new InvalidArgumentException('Sync a final Steadfast cancelled status first; pending cancellation is not confirmation.');
            if($type==='delivery_failed'&&$ship['remote_status']==='delivered')throw new InvalidArgumentException('A delivered provider snapshot conflicts with a failed-delivery claim. Review the status discrepancy separately.');
            $this->evidence($document);
            $q=$this->db->prepare('INSERT INTO aelvo_courier_exception_cases(shipment_id,exception_type,portal_reference,notes,evidence_id,request_key,request_hash,proposal_actor_id) VALUES (?,?,?,?,?,?,?,?)');
            $q->execute([$shipment,$type,$reference,$notes,$document,$key,$hash,$this->actor['id']]);
            $id=(int)$this->db->lastInsertId();
            $q=$this->db->prepare("INSERT INTO aelvo_courier_events(shipment_id,event_type,detail,actor_id) VALUES (?,'ExceptionFiled',?,?)");
            $q->execute([$shipment,'Courier exception #'.$id.' awaiting independent review. No cancellation/payment/stock action.',$this->actor['id']]);
            $this->audit('courier.exception-proposed:'.$id);
            return $id;
        });
    }

    public function review(array $input): void {
        $this->allow('courier','approve');
        $id=Input::integer($input['case_id']??null);
        $decision=Input::text($input,'decision',12);
        if(!in_array($decision,['approve','reject'],true))throw new InvalidArgumentException('Invalid review decision.');
        $notes=Input::text($input,'review_notes',1500);
        if(($input['evidence_reviewed']??'')!=='1')throw new InvalidArgumentException('An independent review of merchant evidence is required.');
        $this->transaction(function()use($id,$decision,$notes): void {
            $q=$this->db->prepare('SELECT shipment_id FROM aelvo_courier_exception_cases WHERE id=?');
            $q->execute([$id]);$shipment=$q->fetchColumn();if($shipment===false)throw new InvalidArgumentException('Case not found.');
            $q=$this->db->prepare('SELECT booking_state,remote_status FROM aelvo_courier_shipments WHERE id=? FOR UPDATE');
            $q->execute([(int)$shipment]);$ship=$q->fetch();
            $q=$this->db->prepare('SELECT * FROM aelvo_courier_exception_cases WHERE id=? FOR UPDATE');
            $q->execute([$id]);$case=$q->fetch();
            if(!$ship||!$case||$case['state']!=='Pending'||$ship['booking_state']!=='Booked')throw new RuntimeException('Courier exception changed; reload before reviewing.',409);
            if((int)$case['proposal_actor_id']===(int)$this->actor['id'])throw new RuntimeException('A second authorized staff member must review.',403);
            if($decision==='approve'){
                if($case['exception_type']==='provider_cancelled'&&$ship['remote_status']!=='cancelled')throw new RuntimeException('Final provider cancellation is no longer confirmed. Refresh and investigate.',409);
                if($case['exception_type']==='delivery_failed'&&$ship['remote_status']==='delivered')throw new RuntimeException('Provider now reports delivered; investigate before approving failure.',409);
            }
            $state=$decision==='approve'?'Approved':'Rejected';
            $q=$this->db->prepare('UPDATE aelvo_courier_exception_cases SET state=?,review_actor_id=?,review_notes=?,reviewed_at=UTC_TIMESTAMP() WHERE id=? AND state=?');
            $q->execute([$state,$this->actor['id'],$notes,$id,'Pending']);
            if($q->rowCount()!==1)throw new RuntimeException('Review already processed.',409);
            $q=$this->db->prepare('INSERT INTO aelvo_courier_events(shipment_id,event_type,detail,actor_id) VALUES (?,?,?,?)');
            $q->execute([(int)$shipment,'Exception'.$state,'Evidence review #'.$id.'; separate order, courier, payment and stock actions remain required.',$this->actor['id']]);
            $this->audit('courier.exception-'.strtolower($state).':'.$id);
        });
    }

    /** Provider later confirms delivery: two independent staff document resolution of an earlier failure. */
    public function proposeClearance(array $input): int {
        $this->allow('courier','edit');
        $caseId=Input::integer($input['case_id']??null);
        $ref=Input::text($input,'portal_reference',120);
        $notes=Input::text($input,'clearance_notes',1500);
        $document=Input::text($input,'document_id',32);
        $requestKey=Input::text($input,'request_key',64);
        if(!preg_match('/^[a-f0-9]{64}$/D',$requestKey)||preg_match('/[\x00-\x1f\x7f]/',$ref)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$notes))throw new InvalidArgumentException('Invalid clearance request.');
        if(($input['delivery_verified']??'')!=='1')throw new InvalidArgumentException('Verify Steadfast now reports delivered and supply independent evidence.');
        $key=hash('sha256','courier-exception-clearance:'.$this->actor['id'].':'.$requestKey);
        $hash=hash('sha256',json_encode([$caseId,$ref,$notes],JSON_THROW_ON_ERROR));
        return $this->transaction(function()use($caseId,$ref,$notes,$document,$key,$hash):int {
            $q=$this->db->prepare('SELECT shipment_id FROM aelvo_courier_exception_cases WHERE id=?');$q->execute([$caseId]);$shipment=$q->fetchColumn();
            if($shipment===false)throw new InvalidArgumentException('Exception case not found.');
            $q=$this->db->prepare('SELECT booking_state,remote_status FROM aelvo_courier_shipments WHERE id=? FOR UPDATE');$q->execute([$shipment]);$ship=$q->fetch();
            $q=$this->db->prepare('SELECT state,exception_type FROM aelvo_courier_exception_cases WHERE id=? FOR UPDATE');$q->execute([$caseId]);$case=$q->fetch();
            $q=$this->db->prepare('SELECT id,request_hash FROM aelvo_courier_exception_clearances WHERE request_key=?');$q->execute([$key]);$existing=$q->fetch();
            if($existing){if(!hash_equals((string)$existing['request_hash'],$hash))throw new RuntimeException('Clearance request changed.',409);return (int)$existing['id'];}
            if(!$ship||!$case||$case['state']!=='Approved'||!in_array($case['exception_type'],['delivery_failed','return_to_sender_reported'],true)||$ship['booking_state']!=='Booked'||$ship['remote_status']!=='delivered')throw new InvalidArgumentException('Only an approved failed/returned report with a newer delivered provider status may be cleared. Cancellation cannot be cleared here.');
            $q=$this->db->prepare("SELECT id FROM aelvo_courier_exception_clearances WHERE case_id=? AND state='Pending' LIMIT 1 FOR UPDATE");$q->execute([$caseId]);
            if($q->fetchColumn()!==false)throw new RuntimeException('A clearance is already pending independent review.',409);
            $this->evidence($document);
            $q=$this->db->prepare('INSERT INTO aelvo_courier_exception_clearances(case_id,portal_reference,notes,evidence_id,request_key,request_hash,proposal_actor_id) VALUES (?,?,?,?,?,?,?)');
            $q->execute([$caseId,$ref,$notes,$document,$key,$hash,$this->actor['id']]);$id=(int)$this->db->lastInsertId();
            $this->audit('courier.exception-clearance-proposed:'.$id);return $id;
        });
    }

    public function reviewClearance(array $input): void {
        $this->allow('courier','approve');
        $id=Input::integer($input['clearance_id']??null);
        $decision=Input::text($input,'decision',12);
        $notes=Input::text($input,'review_notes',1500);
        if(!in_array($decision,['approve','reject'],true)||($input['evidence_reviewed']??'')!=='1')throw new InvalidArgumentException('Independent review and valid decision required.');
        $this->transaction(function()use($id,$decision,$notes):void {
            $q=$this->db->prepare('SELECT c.id AS case_id,c.shipment_id FROM aelvo_courier_exception_clearances x JOIN aelvo_courier_exception_cases c ON c.id=x.case_id WHERE x.id=?');$q->execute([$id]);$link=$q->fetch();
            if(!$link)throw new InvalidArgumentException('Clearance not found.');
            $q=$this->db->prepare('SELECT booking_state,remote_status FROM aelvo_courier_shipments WHERE id=? FOR UPDATE');$q->execute([$link['shipment_id']]);$ship=$q->fetch();
            $q=$this->db->prepare('SELECT exception_type,state FROM aelvo_courier_exception_cases WHERE id=? FOR UPDATE');$q->execute([$link['case_id']]);$case=$q->fetch();
            $q=$this->db->prepare('SELECT * FROM aelvo_courier_exception_clearances WHERE id=? FOR UPDATE');$q->execute([$id]);$clear=$q->fetch();
            if(!$ship||!$case||!$clear||$clear['state']!=='Pending'||$case['state']!=='Approved')throw new RuntimeException('Clearance status changed; reload.',409);
            if((int)$clear['proposal_actor_id']===(int)$this->actor['id'])throw new RuntimeException('A second authorized reviewer is required.',403);
            if($decision==='approve'&&($ship['booking_state']!=='Booked'||$ship['remote_status']!=='delivered'||!in_array($case['exception_type'],['delivery_failed','return_to_sender_reported'],true)))throw new RuntimeException('Provider recovery is not currently confirmed; cannot clear the hold.',409);
            $state=$decision==='approve'?'Approved':'Rejected';
            $q=$this->db->prepare("UPDATE aelvo_courier_exception_clearances SET state=?,review_actor_id=?,review_notes=?,reviewed_at=UTC_TIMESTAMP() WHERE id=? AND state='Pending'");$q->execute([$state,$this->actor['id'],$notes,$id]);
            if($q->rowCount()!==1)throw new RuntimeException('Clearance already reviewed.',409);
            if($state==='Approved'){
                $q=$this->db->prepare("UPDATE aelvo_courier_exception_cases SET state='Cleared' WHERE id=? AND state='Approved'");$q->execute([$link['case_id']]);
                if($q->rowCount()!==1)throw new RuntimeException('Exception cannot be cleared.',409);
            }
            $q=$this->db->prepare('INSERT INTO aelvo_courier_events(shipment_id,event_type,detail,actor_id) VALUES (?,?,?,?)');
            $q->execute([$link['shipment_id'],'Clearance'.$state,'Recovery evidence review #'.$id.'; no stock, payment or fulfillment posted.',$this->actor['id']]);
            $this->audit('courier.exception-clearance-'.strtolower($state).':'.$id);
        });
    }

    /** Fail-closed when fulfillment conflicts with a booked provider shipment or its exception evidence. */
    public static function permitted(string $target,string $booking,string $remote,bool $hasHold): bool {
        if(!in_array($target,['Dispatched','Delivered'],true))return true;
        if($booking!=='Booked'||$hasHold)return false;
        if(in_array($remote,['cancelled','cancelled_approval_pending','unknown','unknown_approval_pending','hold','partial_delivered','partial_delivered_approval_pending'],true))return false;
        if($target==='Delivered'&&$remote!=='delivered')return false;
        return true;
    }

    public static function assertFulfillment(PDO $db,int $order,string $target): void {
        $q=$db->prepare('SELECT id,booking_state,remote_status FROM aelvo_courier_shipments WHERE order_id=? FOR UPDATE');
        $q->execute([$order]);$shipment=$q->fetch();
        if(!$shipment)return; // Unbooked/manual orders retain their own explicit fulfillment checks.
        $q=$db->prepare("SELECT id FROM aelvo_courier_exception_cases WHERE shipment_id=? AND state IN ('Pending','Approved') LIMIT 1 FOR UPDATE");
        $q->execute([$shipment['id']]);$hasHold=$q->fetchColumn()!==false;
        if(!self::permitted($target,(string)$shipment['booking_state'],(string)$shipment['remote_status'],$hasHold))
            throw new InvalidArgumentException('Courier has an uncertain, adverse or unreviewed status. Investigate in the courier queue; no automatic cancellation, delivery or restock.');
    }
}
