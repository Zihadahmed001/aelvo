<?php
declare(strict_types=1);
namespace Aelvo;
use PDO;
use InvalidArgumentException;
use RuntimeException;
final class Payments extends Service {
    public const METHODS=['Bank','bKash','Nagad','Rocket','COD','Cash'];
    public static function summary(PDO $db,int $order): array {
        $q=$db->prepare('SELECT o.total_minor,COALESCE(f.advance_minor,0) AS advance_minor FROM aelvo_orders o LEFT JOIN aelvo_order_finance f ON f.order_id=o.id WHERE o.id=?');$q->execute([$order]);$r=$q->fetch();
        if(!$r)throw new InvalidArgumentException('Order not found.');
        $q=$db->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM aelvo_order_receipts WHERE order_id=?');$q->execute([$order]);$paid=(int)$q->fetchColumn();
        $q=$db->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM aelvo_gateway_receipts WHERE order_id=?');$q->execute([$order]);$paid+=(int)$q->fetchColumn();
        $q=$db->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM aelvo_refunds WHERE order_id=?');$q->execute([$order]);$refunded=(int)$q->fetchColumn();
        return ['refunded_minor'=>$refunded,'net_received_minor'=>max(0,$paid-$refunded),'total_minor'=>(int)$r['total_minor'],'paid_minor'=>$paid,'due_minor'=>max(0,(int)$r['total_minor']-$paid),'advance_due_minor'=>max(0,(int)$r['advance_minor']-$paid)];
    }
    public static function advance(array $settings,int $total): int {
        if($total<0)throw new InvalidArgumentException('Invalid order total.');
        $type=$settings['advance_type']??'none';$value=(int)($settings['advance_value']??0);
        // An explicit merchant value-band rule may override the general advance.
        // No inferred phone or customer-risk score is used here.
        $threshold=$settings['high_value_advance_min_minor']??0;
        if(is_int($threshold)&&$threshold>0&&$total>=$threshold){
            $type=$settings['high_value_advance_type']??'none';
            $value=(int)($settings['high_value_advance_value']??0);
        }
        if(!in_array($type,['none','fixed','percent'],true)||$value<0 || ($type==='percent'&&$value>100)){
            throw new InvalidArgumentException('Advance policy needs review.');
        }
        return match($type){'fixed'=>min($total,$value),'percent'=>min($total,intdiv($total*$value+99,100)),default=>0};
    }
    public static function proof(PDO $db,int $order,array $v,array $file): string {
        $method=Input::text($v,'method',30);$reference=strtoupper(Input::text($v,'reference',100));$amount=Input::money($v['amount']??null);
        if(!in_array($method,['Bank','bKash','Nagad','Rocket'],true)||$amount<1)throw new InvalidArgumentException('Choose a valid payment method and amount.');
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('Upload payment evidence.');
        $bytes=filesize($file['tmp_name']);$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'];
        if(!$bytes||$bytes>5*1024*1024||!isset($types[$mime])||($mime!=='application/pdf'&&!getimagesize($file['tmp_name'])))throw new InvalidArgumentException('Use a valid JPEG, PNG, WebP or PDF under 5 MB.');
        $id=bin2hex(random_bytes(16));$disk=$id.'.'.$types[$mime];$directory=dirname(__DIR__).'/storage/proofs';
        if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Proof storage unavailable.');
        $target=$directory.'/'.$disk;if(!move_uploaded_file($file['tmp_name'],$target))throw new RuntimeException('Could not save evidence.');chmod($target,0600);
        $db->beginTransaction();
        try{
            $q=$db->prepare('SELECT status FROM aelvo_orders WHERE id=? FOR UPDATE');$q->execute([$order]);$status=$q->fetchColumn();
            if(!$status||$status==='Cancelled')throw new InvalidArgumentException('Payment proof is unavailable for this order.');
            // Hold competing manual claims while a hosted payment might still be charged.
            GatewayPayments::assertNoUnresolved($db,$order);
            $q=$db->prepare("SELECT COUNT(*) FROM aelvo_payment_proofs WHERE order_id=? AND status='Pending'");$q->execute([$order]);if((int)$q->fetchColumn()>=3)throw new InvalidArgumentException('Please wait for the pending payment evidence to be reviewed.');
            if($amount>self::summary($db,$order)['due_minor'])throw new InvalidArgumentException('Claimed amount exceeds the outstanding order balance.');
            $q=$db->prepare("INSERT INTO aelvo_payment_proofs(id,order_id,method,reference,claimed_minor,disk_name,mime,bytes,note) VALUES (?,?,?,?,?,?,?,?,'')");$q->execute([$id,$order,$method,$reference,$amount,$disk,$mime,$bytes]);$db->commit();return $id;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();unlink($target);throw $e;}
    }
    public function receive(array $v): int {
        $this->allow('payments','edit');$this->allow('finance','edit');
        if(($v['confirmed']??'')!=='1')throw new InvalidArgumentException('Confirm actual receipt using your bank, wallet or cash records.');
        $order=Input::integer($v['order_id']??null);$account=Input::integer($v['account_id']??null);$amount=Input::money($v['amount']??null);$method=Input::text($v,'method',30);$reference=strtoupper(Input::text($v,'reference',100));$proof=Input::text($v,'proof_id',32,false);$note=Input::text($v,'note',2000);
        if(!in_array($method,self::METHODS,true)||$amount<1)throw new InvalidArgumentException('Choose a valid payment method and positive amount.');
        $request=Input::text($v,'request_key',64);if(!preg_match('/^[a-f0-9]{64}$/D',$request))throw new InvalidArgumentException('Reload the payment form.');
        $key=hash('sha256','receipt:'.$this->actor['id'].':'.$request);$hash=hash('sha256',json_encode([$order,$account,$amount,$method,$reference,$proof,$note],JSON_THROW_ON_ERROR));
        return $this->transaction(function()use($order,$account,$amount,$method,$reference,$proof,$note,$key,$hash){
            $q=$this->db->prepare('SELECT * FROM aelvo_orders WHERE id=? FOR UPDATE');$q->execute([$order]);$o=$q->fetch();if(!$o||$o['status']==='Cancelled')throw new InvalidArgumentException('Order cannot receive payment.');
            $q=$this->db->prepare('SELECT r.id,e.request_hash FROM aelvo_finance_events e JOIN aelvo_order_receipts r ON r.event_id=e.id WHERE e.request_key=?');$q->execute([$key]);$previous=$q->fetch();
            if($previous){if(!hash_equals($previous['request_hash'],$hash))throw new RuntimeException('Payment request changed. Reload first.',409);return (int)$previous['id'];}
            // A still-processing hosted charge can arrive after a manual receipt.
            // Hold further collection until the provider's outcome is reconciled.
            GatewayPayments::assertNoUnresolved($this->db,$order);
            if(self::summary($this->db,$order)['refunded_minor']>0)throw new InvalidArgumentException('This order has a recorded refund. Resolve its reconciliation before accepting more receipts.');
            $courier=$this->db->prepare('SELECT id FROM aelvo_courier_shipments WHERE order_id=? FOR UPDATE');$courier->execute([$order]);
            if($courier->fetchColumn()!==false)throw new InvalidArgumentException('Courier order receipts require dedicated collection/remittance reconciliation. Do not post courier COD into bank cash.');
            if($method==='COD'&&$o['status']!=='Delivered')throw new InvalidArgumentException('COD settlement requires a delivered order.');
            if($amount>self::summary($this->db,$order)['due_minor'])throw new InvalidArgumentException('Receipt exceeds the outstanding order balance.');
            if($proof!==''){$q=$this->db->prepare('SELECT * FROM aelvo_payment_proofs WHERE id=? AND order_id=? FOR UPDATE');$q->execute([$proof,$order]);$p=$q->fetch();if(!$p||$p['status']!=='Pending'||$p['method']!==$method||$p['reference']!==$reference)throw new InvalidArgumentException('Evidence is unavailable or does not match the verified transaction.');}
            $q=$this->db->prepare('SELECT balance_minor FROM aelvo_accounts WHERE id=? AND active=1 FOR UPDATE');$q->execute([$account]);$balance=$q->fetchColumn();if($balance===false||(int)$balance+$amount>1000000000000)throw new InvalidArgumentException('Account is unavailable or balance limit exceeded.');
            $event=bin2hex(random_bytes(16));$q=$this->db->prepare("INSERT INTO aelvo_finance_events(id,request_key,request_hash,kind,explanation,actor_id) VALUES (?,?,?,'order-receipt',?,?)");$q->execute([$event,$key,$hash,$note,$this->actor['id']]);
            $q=$this->db->prepare('INSERT INTO aelvo_order_receipts(order_id,method,reference,amount_minor,event_id,proof_id) VALUES (?,?,?,?,?,?)');$q->execute([$order,$method,$reference,$amount,$event,$proof?:null]);$receipt=(int)$this->db->lastInsertId();
            $after=(int)$balance+$amount;$q=$this->db->prepare('UPDATE aelvo_accounts SET balance_minor=? WHERE id=?');$q->execute([$after,$account]);
            $q=$this->db->prepare('INSERT INTO aelvo_ledger(event_id,account_id,delta_minor,balance_after_minor) VALUES (?,?,?,?)');$q->execute([$event,$account,$amount,$after]);
            $summary=self::summary($this->db,$order);$q=$this->db->prepare('UPDATE aelvo_orders SET payment_status=? WHERE id=?');$q->execute([$summary['due_minor']===0?'Paid':'Partial',$order]);
            if($proof!==''){$q=$this->db->prepare("UPDATE aelvo_payment_proofs SET status='Verified',note=? WHERE id=?");$q->execute([$note,$proof]);}
            $this->audit('payment.receipt-recorded:'.$receipt);return $receipt;
        });
    }
    public function reject(array $v): void {
        $this->allow('payments','edit');$id=Input::text($v,'proof_id',32);$note=Input::text($v,'note',2000);
        $this->transaction(function()use($id,$note){$q=$this->db->prepare("UPDATE aelvo_payment_proofs SET status='Rejected',note=? WHERE id=? AND status='Pending'");$q->execute([$note,$id]);if($q->rowCount()!==1)throw new InvalidArgumentException('Evidence is no longer pending.');$this->audit('payment.proof-rejected:'.$id);});
    }
}
