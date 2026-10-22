<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** One verified bank/wallet credit matched to one or more live validated gateway receipts.
 * Recording is manual; no gateway transfer is initiated and no payment state is modified.
 */
final class GatewayPayoutBatches extends Service {
    /** Form input: one 'transaction ID | credited BDT | withheld fee BDT' per line. */
    public static function parseAllocations(string $input): array {
        if(strlen($input)>5000)throw new InvalidArgumentException('Too many payout lines.');
        $lines=preg_split('/\r\n|\r|\n/',trim($input));
        if(!$lines||count($lines)>40)throw new InvalidArgumentException('Provide 1–40 matched charges per payout.');
        $rows=[];
        foreach($lines as $line){
            $pieces=explode('|',$line);
            if(count($pieces)!==3)throw new InvalidArgumentException('Each payout line needs transaction ID | credited BDT | fee BDT.');
            [$tran,$credited,$fee]=array_map('trim',$pieces);
            if(!preg_match('/^[A-Za-z0-9_-]{6,30}$/D',$tran))throw new InvalidArgumentException('Invalid gateway transaction identifier.');
            $transactionKey=strtoupper($tran);
            if(isset($rows[$transactionKey]))throw new InvalidArgumentException('The same gateway transaction appears twice.');
            $creditMinor=Input::money($credited);$feeMinor=Input::money($fee);
            if($creditMinor+$feeMinor<1)throw new InvalidArgumentException('Each allocation must have a positive total.');
            $rows[$transactionKey]=['tran_id'=>$tran,'credited_minor'=>$creditMinor,'fee_minor'=>$feeMinor];
        }
        ksort($rows,SORT_STRING);
        return array_values($rows);
    }
    public static function referenceKey(string $reference): string {
        $reference=trim($reference);
        if(strlen($reference)<6||strlen($reference)>120||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.: \-\/]{5,119}$/D',$reference))
            throw new InvalidArgumentException('Enter the exact bank statement transaction reference (6–120 characters).');
        return hash('sha256',strtolower(preg_replace('/ +/',' ',$reference)));
    }
    public static function conservation(array $rows,int $credited,int $fee): bool {
        $sumCredited=0;$sumFee=0;
        foreach($rows as $row){$sumCredited+=(int)$row['credited_minor'];$sumFee+=(int)$row['fee_minor'];}
        return $credited>0 && $sumCredited===$credited && $sumFee===$fee;
    }
    public function record(array $v): int {
        $this->allow('payments','edit');$this->allow('finance','edit');
        if(($v['confirmed']??'')!=='1')throw new InvalidArgumentException('Confirm the actual bank/wallet credit and the provider payout statement independently.');
        $account=Input::integer($v['account_id']??null);
        $reference=Input::text($v,'bank_reference',120);$refKey=self::referenceKey($reference);
        $credited=Input::money($v['received']??null);$fee=Input::money($v['fee']??null);
        $allocations=self::parseAllocations(Input::text($v,'allocations',5000));
        if(!self::conservation($allocations,$credited,$fee))
            throw new InvalidArgumentException('Matched charge credits and fees must exactly equal the bank credit and withheld fees.');
        $document=Input::text($v,'document_id',32);$raw=Input::text($v,'request_key',64);
        if(!preg_match('/^[a-f0-9]{32}$/D',$document)||!preg_match('/^[a-f0-9]{64}$/D',$raw))
            throw new InvalidArgumentException('Reload the payout form and attach private statement evidence.');
        $key=hash('sha256','gateway-payout-batch:'.$this->actor['id'].':'.$raw);
        $hash=hash('sha256',json_encode([$account,$refKey,$credited,$fee,$allocations,$document],JSON_THROW_ON_ERROR));
        return $this->transaction(function()use($account,$reference,$refKey,$credited,$fee,$allocations,$document,$key,$hash):int {
            $q=$this->db->prepare('SELECT id,request_hash FROM aelvo_finance_events WHERE request_key=?');$q->execute([$key]);$previous=$q->fetch();
            if($previous){
                if(!hash_equals((string)$previous['request_hash'],$hash))throw new RuntimeException('The payout request was changed after submission.',409);
                $q=$this->db->prepare('SELECT id FROM aelvo_gateway_payout_batches WHERE event_id=?');$q->execute([$previous['id']]);
                $id=$q->fetchColumn();if($id===false)throw new RuntimeException('Payout posting incomplete; manual investigation required.',409);
                return (int)$id;
            }
            // All charge locks use a deterministic ID order, to serialize competing payout writers.
            $lookup=$this->db->prepare('SELECT id,order_id,state FROM aelvo_gateway_attempts WHERE tran_id=?');
            $byId=[];
            foreach($allocations as $row){
                $lookup->execute([$row['tran_id']]);$attempt=$lookup->fetch(PDO::FETCH_ASSOC);
                if(!$attempt||$attempt['state']!=='Verified')throw new InvalidArgumentException('Every matched charge must be independently validated live.');
                if(isset($byId[$attempt['id']]))throw new InvalidArgumentException('The same verified charge cannot be allocated twice.');
                $byId[$attempt['id']]=['attempt'=>$attempt,'allocation'=>$row];
            }
            ksort($byId,SORT_STRING);
            $lock=$this->db->prepare('SELECT amount_minor FROM aelvo_gateway_receipts WHERE attempt_id=? FOR UPDATE');
            $old=$this->db->prepare('SELECT COALESCE(SUM(received_minor+fee_minor),0) FROM aelvo_gateway_settlements WHERE attempt_id=?');
            $batched=$this->db->prepare('SELECT COALESCE(SUM(credited_minor+fee_minor),0) FROM aelvo_gateway_payout_allocations WHERE attempt_id=?');
            $cases=$this->db->prepare("SELECT id FROM aelvo_gateway_exception_cases WHERE attempt_id=? AND state='Pending' LIMIT 1");
            foreach($byId as $id=>$data){
                $lock->execute([$id]);$gross=$lock->fetchColumn();
                if($gross===false)throw new RuntimeException('Missing verified gateway receipt.',409);
                $cases->execute([$id]);if($cases->fetchColumn()!==false)throw new RuntimeException('An unresolved gateway investigation blocks this payout.',409);
                $old->execute([$id]);$batched->execute([$id]);
                $remaining=(int)$gross-(int)$old->fetchColumn()-(int)$batched->fetchColumn();
                if((int)$data['allocation']['credited_minor']+(int)$data['allocation']['fee_minor']>$remaining)
                    throw new RuntimeException('A selected charge has already been partially/fully settled. Reload the payout statement.',409);
            }
            $q=$this->db->prepare('SELECT id FROM aelvo_media WHERE id=? AND owner_id=? AND is_public=0');$q->execute([$document,$this->actor['id']]);
            if(!$q->fetchColumn())throw new InvalidArgumentException('Private statement evidence must belong to the recording staff.');
            $q=$this->db->prepare("SELECT balance_minor FROM aelvo_accounts WHERE id=? AND active=1 AND kind IN ('Bank','Mobile wallet') FOR UPDATE");
            $q->execute([$account]);$balance=$q->fetchColumn();
            if($balance===false||(int)$balance+$credited>1000000000000)throw new InvalidArgumentException('Destination account unavailable or credit limit exceeded.');
            $q=$this->db->prepare('SELECT id FROM aelvo_gateway_payout_batches WHERE account_id=? AND bank_reference_key=?');$q->execute([$account,$refKey]);
            if($q->fetchColumn()!==false)throw new RuntimeException('This bank statement line was already recorded; do not post cash again.',409);
            // Legacy single-charge postings have a statement reference too. Reject reuse across both paths.
            $q=$this->db->prepare('SELECT id FROM aelvo_gateway_settlements WHERE account_id=? AND LOWER(TRIM(reference))=? LIMIT 1');
            $q->execute([$account,strtolower(preg_replace('/ +/',' ',$reference))]);
            if($q->fetchColumn()!==false)throw new RuntimeException('This bank reference was already used in a legacy settlement.',409);
            $event=bin2hex(random_bytes(16));
            $q=$this->db->prepare("INSERT INTO aelvo_finance_events(id,request_key,request_hash,kind,explanation,document_id,actor_id) VALUES (?,?,?,'gateway-settlement',?,?,?)");
            $q->execute([$event,$key,$hash,'Actual grouped SSLCommerz bank credit '.$reference.'; withheld fee '.SslCommerz::money($fee).' BDT',$document,$this->actor['id']]);
            $after=(int)$balance+$credited;
            $q=$this->db->prepare('UPDATE aelvo_accounts SET balance_minor=? WHERE id=?');$q->execute([$after,$account]);
            $q=$this->db->prepare('INSERT INTO aelvo_ledger(event_id,account_id,delta_minor,balance_after_minor) VALUES (?,?,?,?)');$q->execute([$event,$account,$credited,$after]);
            $q=$this->db->prepare('INSERT INTO aelvo_gateway_payout_batches(account_id,event_id,bank_reference,bank_reference_key,credit_minor,fee_minor,document_id,actor_id) VALUES (?,?,?,?,?,?,?,?)');
            $q->execute([$account,$event,$reference,$refKey,$credited,$fee,$document,$this->actor['id']]);$payout=(int)$this->db->lastInsertId();
            $q=$this->db->prepare('INSERT INTO aelvo_gateway_payout_allocations(payout_id,attempt_id,credited_minor,fee_minor) VALUES (?,?,?,?)');
            foreach($byId as $id=>$data){$r=$data['allocation'];$q->execute([$payout,$id,$r['credited_minor'],$r['fee_minor']]);}
            $this->audit('gateway.payout-batch-recorded:'.$payout);
            return $payout;
        });
    }
}
