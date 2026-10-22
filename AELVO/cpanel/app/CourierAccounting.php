<?php
declare(strict_types=1);
namespace Aelvo;
use InvalidArgumentException;
use RuntimeException;

/** External COD collection and actual remittance must never be treated as the same event. */
final class CourierAccounting extends Service {
    public static function outstanding(int $collected,int $remitted,int $fees): int {
        if(min($collected,$remitted,$fees)<0||$remitted+$fees>$collected)throw new InvalidArgumentException('Courier reconciliation is inconsistent.');
        return $collected-$remitted-$fees;
    }
    private function request(array $v,string $prefix): string {
        $raw=Input::text($v,'request_key',64);
        if(!preg_match('/^[a-f0-9]{64}$/D',$raw))throw new InvalidArgumentException('Reload the courier accounting form.');
        return hash('sha256',$prefix.':'.$this->actor['id'].':'.$raw);
    }
    private function document(string $id): void {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Attach verified payment evidence.');
        $q=$this->db->prepare('SELECT id FROM aelvo_media WHERE id=? AND owner_id=? AND is_public=0');
        $q->execute([$id,$this->actor['id']]);
        if(!$q->fetchColumn())throw new InvalidArgumentException('Private evidence not found.');
    }
    /** Courier-reported collection: never credits any internal account. */
    public function collect(array $v): void {
        $this->allow('courier','edit');$this->allow('finance','edit');
        if(($v['confirmed']??'')!=='1')throw new InvalidArgumentException('Verify actual courier collection evidence in the merchant portal first.');
        $id=Input::integer($v['shipment_id']??null);$amount=Input::money($v['collected']??null);
        $reference=Input::text($v,'reference',120);$doc=Input::text($v,'document_id',32);$key=$this->request($v,'courier-collection');
        $hash=hash('sha256',json_encode([$id,$amount,$reference],JSON_THROW_ON_ERROR));
        $this->transaction(function()use($id,$amount,$reference,$doc,$key,$hash){
            $q=$this->db->prepare('SELECT s.*,o.status AS order_status FROM aelvo_courier_shipments s JOIN aelvo_orders o ON o.id=s.order_id WHERE s.id=? FOR UPDATE');
            $q->execute([$id]);$s=$q->fetch();if(!$s)throw new InvalidArgumentException('Courier shipment not found.');
            $q=$this->db->prepare('SELECT request_key,request_hash FROM aelvo_courier_collections WHERE shipment_id=?');$q->execute([$id]);$old=$q->fetch();
            if($old){if(!hash_equals($old['request_key'],$key)||!hash_equals($old['request_hash'],$hash))throw new RuntimeException('Collection already recorded. Corrections require an audited reconciliation process.',409);return;}
            if($s['booking_state']!=='Booked'||$s['remote_status']!=='delivered'||$s['order_status']!=='Delivered')throw new InvalidArgumentException('Both courier and AELVO must confirm delivery before recording a COD collection.');
            $q=$this->db->prepare("SELECT id FROM aelvo_courier_exception_cases WHERE shipment_id=? AND state IN ('Pending','Approved') LIMIT 1 FOR UPDATE");
            $q->execute([$id]);if($q->fetchColumn()!==false)throw new InvalidArgumentException('Active courier failure/cancellation exception: reconcile before recording collected COD.');
            if($amount<1||$amount>(int)$s['cod_minor'])throw new InvalidArgumentException('Collected COD must be positive and must not exceed booked COD.');
            $this->document($doc);
            $q=$this->db->prepare('INSERT INTO aelvo_courier_collections(shipment_id,collected_minor,reference,document_id,request_key,request_hash,actor_id) VALUES (?,?,?,?,?,?,?)');
            $q->execute([$id,$amount,$reference,$doc,$key,$hash,$this->actor['id']]);
            $this->audit('courier.collection-recorded:'.$id);
        });
    }
    /** Verified partial payout (including withheld fee): only the cash actually credited is a ledger increase. */
    public function remit(array $v): int {
        $this->allow('courier','edit');$this->allow('finance','edit');
        if(($v['confirmed']??'')!=='1')throw new InvalidArgumentException('Confirm an actual bank/wallet credit from independent evidence.');
        $shipment=Input::integer($v['shipment_id']??null);$account=Input::integer($v['account_id']??null);
        $amount=Input::money($v['remitted']??null);$fee=Input::money($v['fee']??'0');
        $reference=Input::text($v,'reference',120);$doc=Input::text($v,'document_id',32);
        if($amount<1)throw new InvalidArgumentException('An actual credited amount is required.');
        $key=$this->request($v,'courier-remittance');$hash=hash('sha256',json_encode([$shipment,$account,$amount,$fee,$reference],JSON_THROW_ON_ERROR));
        return $this->transaction(function()use($shipment,$account,$amount,$fee,$reference,$doc,$key,$hash){
            $q=$this->db->prepare('SELECT id FROM aelvo_courier_shipments WHERE id=? FOR UPDATE');$q->execute([$shipment]);if(!$q->fetchColumn())throw new InvalidArgumentException('Shipment not found.');
            $q=$this->db->prepare('SELECT id,request_hash FROM aelvo_finance_events WHERE request_key=?');$q->execute([$key]);$old=$q->fetch();
            if($old){if(!hash_equals($old['request_hash'],$hash))throw new RuntimeException('Payment request was changed.',409);$q=$this->db->prepare('SELECT id FROM aelvo_courier_remittances WHERE finance_event_id=?');$q->execute([$old['id']]);return (int)$q->fetchColumn();}
            $q=$this->db->prepare('SELECT collected_minor FROM aelvo_courier_collections WHERE shipment_id=?');$q->execute([$shipment]);$collected=$q->fetchColumn();
            if($collected===false)throw new InvalidArgumentException('Verify courier collection before recording remittance.');
            $q=$this->db->prepare('SELECT COALESCE(SUM(remitted_minor),0) AS remitted,COALESCE(SUM(fee_minor),0) AS fees FROM aelvo_courier_remittances WHERE shipment_id=?');$q->execute([$shipment]);$totals=$q->fetch();
            $remaining=self::outstanding((int)$collected,(int)$totals['remitted'],(int)$totals['fees']);
            if($amount+$fee>$remaining)throw new InvalidArgumentException('Remittance and withholding exceed courier receivable.');
            $this->document($doc);
            $q=$this->db->prepare('SELECT balance_minor FROM aelvo_accounts WHERE id=? AND active=1 FOR UPDATE');$q->execute([$account]);$balance=$q->fetchColumn();
            if($balance===false||(int)$balance+$amount>1000000000000)throw new InvalidArgumentException('Destination account unavailable or account limit exceeded.');
            $event=bin2hex(random_bytes(16));
            $q=$this->db->prepare("INSERT INTO aelvo_finance_events(id,request_key,request_hash,kind,explanation,document_id,actor_id) VALUES (?,?,?,'courier-remittance',?,?,?)");
            $q->execute([$event,$key,$hash,'Independently verified courier payout '.$reference.'; withheld fee '.number_format($fee/100,2,'.','').' BDT',$doc,$this->actor['id']]);
            $after=(int)$balance+$amount;$q=$this->db->prepare('UPDATE aelvo_accounts SET balance_minor=? WHERE id=?');$q->execute([$after,$account]);
            $q=$this->db->prepare('INSERT INTO aelvo_ledger(event_id,account_id,delta_minor,balance_after_minor) VALUES (?,?,?,?)');$q->execute([$event,$account,$amount,$after]);
            $q=$this->db->prepare('INSERT INTO aelvo_courier_remittances(shipment_id,account_id,finance_event_id,remitted_minor,fee_minor,reference,document_id,actor_id) VALUES (?,?,?,?,?,?,?,?)');
            $q->execute([$shipment,$account,$event,$amount,$fee,$reference,$doc,$this->actor['id']]);$id=(int)$this->db->lastInsertId();
            $this->audit('courier.remittance-recorded:'.$id);return $id;
        });
    }
}
