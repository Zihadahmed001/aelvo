<?php
declare(strict_types=1);
namespace Aelvo;
use InvalidArgumentException;
use RuntimeException;
final class Returns extends Service {
    private function requestKey(array $v,string $prefix): string {
        $request=Input::text($v,'request_key',64);
        if(!preg_match('/^[a-f0-9]{64}$/D',$request))throw new InvalidArgumentException('Reload the form.');
        return hash('sha256',$prefix.':'.$this->actor['id'].':'.$request);
    }
    private function lockOrder(int $id): array {
        $q=$this->db->prepare('SELECT * FROM aelvo_orders WHERE id=? FOR UPDATE');$q->execute([$id]);$order=$q->fetch();
        if(!$order)throw new InvalidArgumentException('Order not found.');return $order;
    }
    private function orderTax(int $order): int {
        $q=$this->db->prepare('SELECT tax_minor FROM aelvo_orders WHERE id=?');$q->execute([$order]);return (int)$q->fetchColumn();
    }
    public static function committed(\PDO $db,int $order): int {
        $q=$db->prepare("SELECT COALESCE(SUM(amount_minor),0) FROM aelvo_refund_requests WHERE order_id=? AND status IN ('Pending','Approved')");$q->execute([$order]);return (int)$q->fetchColumn();
    }
    public function receive(array $v): int {
        $this->allow('returns','edit');
        if(($v['confirmed']??'')!=='1')throw new InvalidArgumentException('Confirm physical receipt and inspection first.');
        $item=Input::integer($v['order_item_id']??null);$qty=Input::integer($v['qty']??null,1,100);
        $sellable=Input::integer($v['sellable_qty']??null,0,$qty);$receivedVariant=Input::integer($v['received_variant_id']??null);
        $reason=Input::text($v,'reason',2000);$inspection=Input::text($v,'inspection',2000);
        $request=($v['return_request_id']??'')===''?null:Input::integer($v['return_request_id']);
        $key=$this->requestKey($v,'return');$hash=hash('sha256',json_encode([$item,$qty,$sellable,$receivedVariant,$reason,$inspection,$request],JSON_THROW_ON_ERROR));
        return $this->transaction(function()use($item,$qty,$sellable,$receivedVariant,$reason,$inspection,$request,$key,$hash){
            $q=$this->db->prepare('SELECT order_id FROM aelvo_order_items WHERE id=? FOR UPDATE');$q->execute([$item]);$order=$q->fetchColumn();if(!$order)throw new InvalidArgumentException('Order item not found.');
            $o=$this->lockOrder((int)$order);
            $q=$this->db->prepare('SELECT id,request_hash FROM aelvo_returns WHERE request_key=?');$q->execute([$key]);$previous=$q->fetch();
            if($previous){if(!hash_equals($previous['request_hash'],$hash))throw new RuntimeException('Return request changed. Reload first.',409);return (int)$previous['id'];}
            if($o['status']!=='Delivered')throw new InvalidArgumentException('Only delivered items can enter this return workflow.');
            $q=$this->db->prepare('SELECT * FROM aelvo_order_items WHERE id=?');$q->execute([$item]);$line=$q->fetch();
            $q=$this->db->prepare('SELECT COALESCE(SUM(qty),0) FROM aelvo_returns WHERE order_item_id=?');$q->execute([$item]);
            if((int)$q->fetchColumn()+$qty>(int)$line['qty'])throw new InvalidArgumentException('Quantity exceeds delivered items remaining.');
            $pending=null;
            if($request!==null){
                $q=$this->db->prepare("SELECT * FROM aelvo_customer_return_requests WHERE id=? AND order_item_id=? AND status IN ('Accepted','Partial') FOR UPDATE");$q->execute([$request,$item]);$pending=$q->fetch();
                if(!$pending)throw new InvalidArgumentException('Accept the matching customer request before recording its receipt.');
                $q=$this->db->prepare('SELECT COALESCE(SUM(r.qty),0) FROM aelvo_return_request_receipts l JOIN aelvo_returns r ON r.id=l.return_id WHERE l.request_id=?');$q->execute([$request]);$requestReceived=(int)$q->fetchColumn();
                if($requestReceived+$qty>(int)$pending['qty'])throw new InvalidArgumentException('Receipt exceeds the accepted request quantity.');
            }else{
                $q=$this->db->prepare("SELECT id FROM aelvo_customer_return_requests WHERE order_item_id=? AND status IN ('Pending','Accepted','Partial') LIMIT 1");$q->execute([$item]);if($q->fetch())throw new InvalidArgumentException('Review and link the open customer request before receiving these goods.');
            }
            $q=$this->db->prepare('SELECT id FROM aelvo_variants WHERE id=?');$q->execute([$receivedVariant]);if(!$q->fetch())throw new InvalidArgumentException('Received variant not found.');
            if($receivedVariant!==(int)$line['variant_id']&&$sellable>0)throw new InvalidArgumentException('Wrong-variant goods must remain quarantined for reconciliation; do not restock them as the ordered variant.');
            $q=$this->db->prepare('INSERT INTO aelvo_returns(order_item_id,qty,restocked,request_key,request_hash,reason,actor_id) VALUES (?,?,0,?,?,?,?)');$q->execute([$item,$qty,$key,$hash,$reason,$this->actor['id']]);$id=(int)$this->db->lastInsertId();
            $q=$this->db->prepare('INSERT INTO aelvo_return_dispositions(return_id,variant_id,sellable_qty,damaged_qty,inspection,actor_id) VALUES (?,?,?,?,?,?)');$q->execute([$id,$receivedVariant,$sellable,$qty-$sellable,$inspection,$this->actor['id']]);
            if($request!==null){
                $q=$this->db->prepare('INSERT INTO aelvo_return_request_receipts(return_id,request_id) VALUES (?,?)');$q->execute([$id,$request]);
                $status=$requestReceived+$qty===(int)$pending['qty']?'Received':'Partial';
                $q=$this->db->prepare('UPDATE aelvo_customer_return_requests SET status=?,version=version+1 WHERE id=?');$q->execute([$status,$request]);
                ReturnRequests::event($this->db,$request,$status,'Physical receipt recorded: '.$qty.' unit(s).',(int)$this->actor['id']);
            }
            $this->audit('return.received:'.$id);return $id;
        });
    }
    public function restock(int $return): void {
        $this->allow('returns','edit');$this->allow('inventory','edit');
        $this->transaction(function()use($return){
            $q=$this->db->prepare('SELECT i.order_id FROM aelvo_returns r JOIN aelvo_order_items i ON i.id=r.order_item_id WHERE r.id=?');$q->execute([$return]);$order=$q->fetchColumn();if(!$order)throw new InvalidArgumentException('Return not found.');$this->lockOrder((int)$order);
            $q=$this->db->prepare('SELECT d.*,r.restocked FROM aelvo_return_dispositions d JOIN aelvo_returns r ON r.id=d.return_id WHERE d.return_id=? FOR UPDATE');$q->execute([$return]);$r=$q->fetch();
            if(!$r)throw new InvalidArgumentException('This return has no pending inspected disposition.');
            if($r['posted_at']!==null)return;
            if((int)$r['restocked']!==0)throw new InvalidArgumentException('This return is already restocked.');
            $qty=(int)$r['sellable_qty'];if($qty<1)throw new InvalidArgumentException('No sellable items were accepted.');
            $q=$this->db->prepare('SELECT stock FROM aelvo_variants WHERE id=? FOR UPDATE');$q->execute([$r['variant_id']]);$stock=$q->fetchColumn();if($stock===false||(int)$stock+$qty>100000000)throw new InvalidArgumentException('Cannot restock this variant.');
            $costing=new InventoryCosting($this->db);
            $costing->ensureCoverage((int)$r['variant_id'],(int)$stock);
            $q=$this->db->prepare('SELECT order_item_id FROM aelvo_returns WHERE id=?');$q->execute([$return]);
            $costing->restockReturn($return,(int)$q->fetchColumn(),(int)$r['variant_id'],$qty);
            $q=$this->db->prepare('UPDATE aelvo_variants SET stock=stock+? WHERE id=?');$q->execute([$qty,$r['variant_id']]);
            $q=$this->db->prepare("INSERT INTO aelvo_movements(variant_id,stock_delta,reserved_delta,source_type,source_id,explanation,actor_id) VALUES (?,?,0,'return-restocked',?,?,?)");$q->execute([$r['variant_id'],$qty,(string)$return,$r['inspection'],$this->actor['id']]);
            $q=$this->db->prepare('UPDATE aelvo_return_dispositions SET posted_at=UTC_TIMESTAMP(),posted_by=? WHERE return_id=?');$q->execute([$this->actor['id'],$return]);
            $q=$this->db->prepare('UPDATE aelvo_returns SET restocked=1 WHERE id=?');$q->execute([$return]);$this->audit('return.restocked:'.$return);
        });
    }
    public function proposeRefund(array $v): int {
        $this->allow('returns','edit');$this->allow('finance','edit');
        $order=Input::integer($v['order_id']??null);$account=Input::integer($v['account_id']??null);$amount=Input::money($v['amount']??null);
        $method=Input::text($v,'method',30);$destination=Input::text($v,'destination',180);$reason=Input::text($v,'reason',2000);
        // Tax share must be explicitly disclosed on taxable orders; it is included in amount.
        $taxInput=$v['tax_refund']??null;
        $taxRefund=$taxInput===null?null:Input::money($taxInput);
        $return=($v['return_id']??'')===''?null:Input::integer($v['return_id']);
        if(!in_array($method,['Cash','Bank','bKash','Nagad','Rocket'],true)||$amount<1)throw new InvalidArgumentException('Use a valid refund method and positive amount.');
        $key=$this->requestKey($v,'refund-proposal');$hash=hash('sha256',json_encode([$order,$return,$account,$amount,$method,$destination,$reason,$taxRefund],JSON_THROW_ON_ERROR));
        return $this->transaction(function()use($order,$return,$account,$amount,$method,$destination,$reason,$taxRefund,$key,$hash){
            $lockedOrder=$this->lockOrder($order);
            $q=$this->db->prepare('SELECT id,request_hash FROM aelvo_refund_requests WHERE request_key=?');$q->execute([$key]);$previous=$q->fetch();if($previous){if(!hash_equals($previous['request_hash'],$hash))throw new RuntimeException('Proposal changed. Reload first.',409);return (int)$previous['id'];}
            $chargedTax=(int)$lockedOrder['tax_minor'];
            if($chargedTax>0 && $taxRefund===null)throw new InvalidArgumentException('Enter the exact tax amount included in this refund (0.00 if none).');
            if($chargedTax>0)RefundTaxes::requireKnownHistory($this->db,$order);
            RefundTaxes::validate($taxRefund??0,$amount,$chargedTax,RefundTaxes::committed($this->db,$order));
            if($amount>Payments::summary($this->db,$order)['net_received_minor']-self::committed($this->db,$order))throw new InvalidArgumentException('Amount exceeds uncommitted verified receipts.');
            if($return!==null){$q=$this->db->prepare('SELECT r.id FROM aelvo_returns r JOIN aelvo_order_items i ON i.id=r.order_item_id WHERE r.id=? AND i.order_id=?');$q->execute([$return,$order]);if(!$q->fetch())throw new InvalidArgumentException('Return does not belong to this order.');}
            $q=$this->db->prepare('SELECT id FROM aelvo_accounts WHERE id=? AND active=1');$q->execute([$account]);if(!$q->fetch())throw new InvalidArgumentException('Source account is unavailable.');
            $q=$this->db->prepare('INSERT INTO aelvo_refund_requests(order_id,return_id,amount_minor,account_id,method,destination,reason,request_key,request_hash,actor_id) VALUES (?,?,?,?,?,?,?,?,?,?)');$q->execute([$order,$return,$amount,$account,$method,$destination,$reason,$key,$hash,$this->actor['id']]);$id=(int)$this->db->lastInsertId();
            $q=$this->db->prepare('INSERT INTO aelvo_refund_tax_allocations(refund_request_id,tax_minor) VALUES (?,?)');$q->execute([$id,$taxRefund??0]);
            $this->audit('refund.requested:'.$id);return $id;
        });
    }
    public function reviewRefund(array $v): void {
        $this->allow('returns','edit');$this->allow('finance','edit');
        $id=Input::integer($v['refund_request_id']??null);$decision=Input::text($v,'decision',20);$note=Input::text($v,'review_note',2000);
        if(!in_array($decision,['Approved','Rejected'],true))throw new InvalidArgumentException('Invalid decision.');
        if($decision==='Approved'&&($v['destination_verified']??'')!=='1')throw new InvalidArgumentException('Verify the destination against the original payment and payer before approval.');
        $this->transaction(function()use($id,$decision,$note){
            $q=$this->db->prepare('SELECT order_id FROM aelvo_refund_requests WHERE id=?');$q->execute([$id]);$order=$q->fetchColumn();if(!$order)throw new InvalidArgumentException('Refund request not found.');$this->lockOrder((int)$order);
            $q=$this->db->prepare('SELECT * FROM aelvo_refund_requests WHERE id=? FOR UPDATE');$q->execute([$id]);$r=$q->fetch();
            if($r['status']===$decision)return;
            if(!in_array($r['status'],['Pending','Approved'],true)||($r['status']==='Approved'&&$decision!=='Rejected'))throw new InvalidArgumentException('Refund review is no longer available.');
            if($decision==='Approved')RefundTaxes::allocation($this->db,$id,(int)$this->orderTax($order));
            if($decision==='Approved'&&self::committed($this->db,(int)$order)>Payments::summary($this->db,(int)$order)['net_received_minor'])throw new InvalidArgumentException('Refund commitments require reconciliation.');
            $q=$this->db->prepare('UPDATE aelvo_refund_requests SET status=?,reviewed_by=?,review_note=?,reviewed_at=UTC_TIMESTAMP() WHERE id=?');$q->execute([$decision,$this->actor['id'],$note,$id]);$this->audit('refund.'.strtolower($decision).':'.$id);
        });
    }
    public function refund(array $v): int {
        $this->allow('returns','edit');$this->allow('finance','edit');
        if(($v['confirmed']??'')!=='1')throw new InvalidArgumentException('Confirm the approved refund was actually paid to its approved destination.');
        $id=Input::integer($v['refund_request_id']??null);$reference=strtoupper(Input::text($v,'reference',100));$reason=Input::text($v,'reason',2000);$document=Input::text($v,'document_id',32);
        if(!preg_match('/^[a-f0-9]{32}$/D',$document))throw new InvalidArgumentException('Upload verified payout evidence.');
        $key=$this->requestKey($v,'refund');$hash=hash('sha256',json_encode([$id,$reference,$reason],JSON_THROW_ON_ERROR));
        return $this->transaction(function()use($id,$reference,$reason,$document,$key,$hash){
            $q=$this->db->prepare('SELECT order_id FROM aelvo_refund_requests WHERE id=?');$q->execute([$id]);$order=$q->fetchColumn();if(!$order)throw new InvalidArgumentException('Refund request not found.');$this->lockOrder((int)$order);
            $q=$this->db->prepare('SELECT * FROM aelvo_refund_requests WHERE id=? FOR UPDATE');$q->execute([$id]);$r=$q->fetch();
            if($r['status']==='Completed'){
                $q=$this->db->prepare('SELECT reference FROM aelvo_refunds WHERE id=?');$q->execute([$r['refund_id']]);if($q->fetchColumn()!==$reference)throw new RuntimeException('Refund already recorded with a different payout reference.',409);return (int)$r['refund_id'];
            }
            if($r['status']!=='Approved')throw new InvalidArgumentException('Review and approve this exact refund before recording payment.');
            RefundTaxes::allocation($this->db,$id,$this->orderTax((int)$order));
            $amount=(int)$r['amount_minor'];$account=(int)$r['account_id'];$summary=Payments::summary($this->db,(int)$order);
            if(self::committed($this->db,(int)$order)>$summary['net_received_minor'])throw new InvalidArgumentException('Refund commitments exceed verified funds. Reconcile first.');
            $q=$this->db->prepare('SELECT id FROM aelvo_media WHERE id=? AND owner_id=? AND is_public=0');$q->execute([$document,$this->actor['id']]);if(!$q->fetch())throw new InvalidArgumentException('Payout evidence is unavailable.');
            $q=$this->db->prepare('SELECT balance_minor FROM aelvo_accounts WHERE id=? AND active=1 FOR UPDATE');$q->execute([$account]);$balance=$q->fetchColumn();if($balance===false||(int)$balance<$amount)throw new InvalidArgumentException('Insufficient balance in refund account.');
            $event=bin2hex(random_bytes(16));$q=$this->db->prepare("INSERT INTO aelvo_finance_events(id,request_key,request_hash,kind,explanation,document_id,actor_id) VALUES (?,?,?,'order-refund',?,?,?)");$q->execute([$event,$key,$hash,$reason,$document,$this->actor['id']]);
            $q=$this->db->prepare('INSERT INTO aelvo_refunds(order_id,amount_minor,method,reference,event_id) VALUES (?,?,?,?,?)');$q->execute([$order,$amount,$r['method'],$reference,$event]);$refund=(int)$this->db->lastInsertId();
            $after=(int)$balance-$amount;$q=$this->db->prepare('UPDATE aelvo_accounts SET balance_minor=? WHERE id=?');$q->execute([$after,$account]);
            $q=$this->db->prepare('INSERT INTO aelvo_ledger(event_id,account_id,delta_minor,balance_after_minor) VALUES (?,?,?,?)');$q->execute([$event,$account,-$amount,$after]);
            $q=$this->db->prepare('UPDATE aelvo_orders SET payment_status=? WHERE id=?');$q->execute([$amount===$summary['net_received_minor']?'Refunded':'Partially refunded',$order]);
            $q=$this->db->prepare("UPDATE aelvo_refund_requests SET status='Completed',refund_id=? WHERE id=?");$q->execute([$refund,$id]);$this->audit('refund.recorded:'.$refund);return $refund;
        });
    }
}
