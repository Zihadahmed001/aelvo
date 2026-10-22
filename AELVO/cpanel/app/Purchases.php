<?php
declare(strict_types=1);
namespace Aelvo;
use InvalidArgumentException;
final class Purchases extends Service {
    /** One line per purchased variant: variant_id,quantity,unit_cost_BDT. */
    public static function parsePurchaseLines(string $raw): array {
        if(strlen($raw)>3000)throw new InvalidArgumentException('Purchase lines are too long.');
        $rows=[];
        foreach(preg_split('/\r\n|\r|\n/',trim($raw)) as $line) {
            $line=trim($line);if($line==='')continue;
            if(!preg_match('/^([1-9][0-9]{0,7})\s*[,;:]\s*([1-9][0-9]{0,6})\s*[,;:]\s*(\d{1,8}(?:\.\d{1,2})?)$/D',$line,$m))
                throw new InvalidArgumentException('Use variant ID,quantity,cost per line (for example 42,5,1200.00).');
            $rows[]=['variant_id'=>$m[1],'qty'=>$m[2],'unit_cost'=>$m[3]];
            if(count($rows)>30)throw new InvalidArgumentException('Keep up to 30 purchase lines.');
        }
        if(!$rows)throw new InvalidArgumentException('Enter at least one purchase line.');
        return $rows;
    }
    /**
     * Purchase draft: supplier, all lines, overheads and per-line landed totals
     * are saved atomically. Never moves stock or account funds until separately
     * received/paid. Existing text-supplier callers remain supported.
     */
    public function create(array $v): int {
        $this->allow('purchases','create');
        $reference=Input::text($v,'reference',80);
        $supplierId=isset($v['supplier_id'])&&$v['supplier_id']!==''?Input::integer($v['supplier_id']):null;
        $supplier=$supplierId===null?Input::text($v,'supplier',180):'';
        $receipt=Input::text($v,'receipt_id',32);
        if(!preg_match('/^[a-f0-9]{32}$/D',$receipt))throw new InvalidArgumentException('Invalid purchase slip ID.');
        $reason=Input::text($v,'explanation',2000,false);
        $rows=$v['items']??[['variant_id'=>$v['variant_id']??null,'qty'=>$v['qty']??null,'unit_cost'=>$v['unit_cost']??null]];
        if(!is_array($rows)||!$rows||count($rows)>30)throw new InvalidArgumentException('Choose 1–30 purchase lines.');
        $lines=[];
        foreach($rows as $row){
            if(!is_array($row))throw new InvalidArgumentException('Invalid purchase line.');
            $id=Input::integer($row['variant_id']??null);
            $qty=Input::integer($row['qty']??null,1,1000000);
            $cost=Input::money($row['unit_cost']??null);
            if(isset($lines[$id])&&$lines[$id]['cost']!==$cost)throw new InvalidArgumentException('One variant cannot have two costs in the same purchase.');
            if(!isset($lines[$id]))$lines[$id]=['qty'=>0,'cost'=>$cost];
            $lines[$id]['qty']+=$qty;
            if($lines[$id]['qty']>1000000)throw new InvalidArgumentException('Too many units of one variant.');
        }
        ksort($lines,SORT_NUMERIC);
        $shipping=Input::money((string)($v['shipping']??'0'));
        $other=Input::money((string)($v['other_cost']??'0'));
        $discount=Input::money((string)($v['discount']??'0'));
        return $this->transaction(function() use($reference,$supplierId,$supplier,$receipt,$reason,$lines,$shipping,$other,$discount): int {
            $q=$this->db->prepare('SELECT id FROM aelvo_media WHERE id=? AND owner_id=? AND is_public=0');
            $q->execute([$receipt,$this->actor['id']]);
            if(!$q->fetchColumn())throw new InvalidArgumentException('Upload the required private purchase slip first.');
            $snapshot=$supplier;
            if($supplierId!==null){
                $q=$this->db->prepare('SELECT name FROM aelvo_suppliers WHERE id=? AND active=1 FOR UPDATE');
                $q->execute([$supplierId]);$snapshot=$q->fetchColumn();
                if($snapshot===false)throw new InvalidArgumentException('Selected supplier is unavailable.');
            }
            $subtotal=0;
            foreach($lines as $variant=>$line){
                $q=$this->db->prepare('SELECT id FROM aelvo_variants WHERE id=? AND active=1');
                $q->execute([$variant]);if(!$q->fetchColumn())throw new InvalidArgumentException('Variant unavailable: '.$variant);
                $subtotal+=$line['qty']*$line['cost'];
            }
            $total=$subtotal+$shipping+$other-$discount;
            if($discount>$subtotal+$shipping+$other || $total>1000000000000)
                throw new InvalidArgumentException('Check total purchase cost and discount.');
            $q=$this->db->prepare('INSERT INTO aelvo_purchases(reference,supplier,receipt_id,explanation,total_minor,actor_id) VALUES (?,?,?,?,?,?)');
            $q->execute([$reference,$snapshot,$receipt,$reason,$total,$this->actor['id']]);
            $purchase=(int)$this->db->lastInsertId();
            if($supplierId!==null){
                $q=$this->db->prepare('INSERT INTO aelvo_purchase_suppliers(purchase_id,supplier_id) VALUES (?,?)');
                $q->execute([$purchase,$supplierId]);
            }
            $q=$this->db->prepare('INSERT INTO aelvo_purchase_charges(purchase_id,items_minor,shipping_minor,other_minor,discount_minor) VALUES (?,?,?,?,?)');
            $q->execute([$purchase,$subtotal,$shipping,$other,$discount]);
            $ins=$this->db->prepare('INSERT INTO aelvo_purchase_items(purchase_id,variant_id,qty,unit_cost_minor) VALUES (?,?,?,?)');
            $landed=$this->db->prepare('INSERT INTO aelvo_purchase_landed_items(purchase_item_id,landed_total_minor) VALUES (?,?)');
            $weights=array_map(fn($line)=>$subtotal>0?$line['qty']*$line['cost']:$line['qty'],$lines);
            $weightTotal=$subtotal>0?$subtotal:array_sum($weights);
            $allocated=0;$remaining=count($lines);
            foreach($lines as $variant=>$line){
                $ins->execute([$purchase,$variant,$line['qty'],$line['cost']]);
                $itemId=(int)$this->db->lastInsertId();
                $remaining--;
                // The final line receives the rounding remainder: exact conservation.
                $share=$remaining===0?$total:min($total-$allocated,(int)floor($total*((float)$weights[$variant]/$weightTotal)));
                $allocated+=$share;
                $landed->execute([$itemId,$share]);
            }
            $this->audit('purchase.drafted:'.$purchase);
            return $purchase;
        });
    }
    /** Record an independently verified supplier payout; NEVER initiates a bank transfer. */
    public function recordPayment(array $v): int {
        $this->allow('purchases','edit');$this->allow('finance','edit');
        if(($v['confirmed']??'')!=='1')throw new InvalidArgumentException('Confirm that the supplier payment was actually made.');
        $purchaseId=Input::integer($v['purchase_id']??null);
        $purchaseReference=Input::text($v,'purchase_reference',80);
        $accountId=Input::integer($v['account_id']??null);
        $amount=Input::money($v['amount']??null);
        if($amount<1)throw new InvalidArgumentException('Enter a positive paid amount.');
        $reference=Input::text($v,'reference',120);
        $document=Input::text($v,'document_id',32);
        if(!preg_match('/^[a-f0-9]{32}$/D',$document))throw new InvalidArgumentException('Attach a private payment receipt.');
        $note=Input::text($v,'note',2000);
        $request=Input::text($v,'request_key',64);
        if(!preg_match('/^[a-f0-9]{64}$/D',$request))throw new InvalidArgumentException('Reload the payment form.');
        $key=hash('sha256','supplier-payment:'.$this->actor['id'].':'.$request);
        // The proof is checked against the actor on the first post; a replay
        // with identical commercial fields returns the original event.
        $hash=hash('sha256',json_encode([$purchaseId,$purchaseReference,$accountId,$amount,$reference,$note],JSON_THROW_ON_ERROR));
        return $this->transaction(function() use($purchaseId,$purchaseReference,$accountId,$amount,$reference,$document,$note,$key,$hash): int {
            $q=$this->db->prepare('SELECT id,reference,total_minor FROM aelvo_purchases WHERE id=? FOR UPDATE');
            $q->execute([$purchaseId]);$purchase=$q->fetch();
            if(!$purchase)throw new InvalidArgumentException('Purchase not found.');
            if(!hash_equals((string)$purchase['reference'],$purchaseReference))throw new InvalidArgumentException('Purchase reference does not match the selected invoice.');
            $q=$this->db->prepare('SELECT id,request_hash FROM aelvo_finance_events WHERE request_key=?');
            $q->execute([$key]);$prior=$q->fetch();
            if($prior){
                if(!hash_equals($prior['request_hash'],$hash))throw new \RuntimeException('Payment request changed. Reload the form.',409);
                $q=$this->db->prepare('SELECT id FROM aelvo_supplier_payments WHERE finance_event_id=?');
                $q->execute([$prior['id']]);return (int)$q->fetchColumn();
            }
            $q=$this->db->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM aelvo_supplier_payments WHERE purchase_id=?');
            $q->execute([$purchaseId]);$already=(int)$q->fetchColumn();
            if($already+$amount>(int)$purchase['total_minor'])throw new InvalidArgumentException('Payment would exceed the purchase amount.');
            $q=$this->db->prepare('SELECT id FROM aelvo_media WHERE id=? AND is_public=0 AND owner_id=?');
            $q->execute([$document,$this->actor['id']]);if(!$q->fetchColumn())throw new InvalidArgumentException('Your private supplier payment receipt is missing.');
            $q=$this->db->prepare('SELECT id,balance_minor FROM aelvo_accounts WHERE id=? AND active=1 FOR UPDATE');
            $q->execute([$accountId]);$account=$q->fetch();
            if(!$account||(int)$account['balance_minor']<$amount)throw new InvalidArgumentException('Insufficient recorded funds in this account.');
            $event=bin2hex(random_bytes(16));
            $q=$this->db->prepare("INSERT INTO aelvo_finance_events(id,request_key,request_hash,kind,explanation,document_id,actor_id) VALUES (?,?,?,'supplier-payment',?,?,?)");
            $q->execute([$event,$key,$hash,$note,$document,$this->actor['id']]);
            $after=(int)$account['balance_minor']-$amount;
            $q=$this->db->prepare('UPDATE aelvo_accounts SET balance_minor=? WHERE id=?');$q->execute([$after,$accountId]);
            $q=$this->db->prepare('INSERT INTO aelvo_ledger(event_id,account_id,delta_minor,balance_after_minor) VALUES (?,?,?,?)');
            $q->execute([$event,$accountId,-$amount,$after]);
            $q=$this->db->prepare('INSERT INTO aelvo_supplier_payments(purchase_id,account_id,finance_event_id,amount_minor,reference,document_id,actor_id) VALUES (?,?,?,?,?,?,?)');
            $q->execute([$purchaseId,$accountId,$event,$amount,$reference,$document,$this->actor['id']]);
            $id=(int)$this->db->lastInsertId();
            $this->audit('supplier.payment-recorded:'.$id);
            return $id;
        });
    }
    public function receive(int $id): void {
        $this->allow('purchases','edit');
        $this->transaction(function() use($id) {
            $q=$this->db->prepare('SELECT * FROM aelvo_purchases WHERE id=? FOR UPDATE');$q->execute([$id]);$purchase=$q->fetch();if(!$purchase)throw new InvalidArgumentException('Purchase not found');
            if($purchase['status']==='Received')return;if($purchase['status']!=='Draft')throw new InvalidArgumentException('Only draft purchases can be received');
            $q=$this->db->prepare('SELECT * FROM aelvo_purchase_items WHERE purchase_id=? ORDER BY variant_id');$q->execute([$id]);$items=$q->fetchAll();if(!$items)throw new InvalidArgumentException('Purchase has no items');
            $costing=new InventoryCosting($this->db);
            $landed=$this->db->prepare('SELECT landed_total_minor FROM aelvo_purchase_landed_items WHERE purchase_item_id=?');
            foreach($items as $item){$q=$this->db->prepare('SELECT stock FROM aelvo_variants WHERE id=? FOR UPDATE');$q->execute([$item['variant_id']]);$stock=$q->fetchColumn();if($stock===false || (int)$stock+(int)$item['qty']>100000000)throw new InvalidArgumentException('Stock limit exceeded');
                $costing->ensureCoverage((int)$item['variant_id'],(int)$stock);
                $landed->execute([$item['id']]);$landedCost=$landed->fetchColumn();
                // Historical purchase lines without a landed snapshot stay unpriced.
                $costing->receivePurchaseItem((int)$item['variant_id'],(int)$item['id'],(int)$item['qty'],$landedCost===false?null:(int)$landedCost);
                $q=$this->db->prepare('UPDATE aelvo_variants SET stock=stock+? WHERE id=?');$q->execute([$item['qty'],$item['variant_id']]);
                $q=$this->db->prepare("INSERT INTO aelvo_movements(variant_id,stock_delta,reserved_delta,source_type,source_id,explanation,actor_id) VALUES (?,?,0,'purchase',?,?,?)");$q->execute([$item['variant_id'],$item['qty'],(string)$id,'Received '.$purchase['reference'],$this->actor['id']]);}
            $q=$this->db->prepare("UPDATE aelvo_purchases SET status='Received' WHERE id=?");$q->execute([$id]);$this->audit('purchase.received:'.$id);
        });
    }
}
