<?php
declare(strict_types=1);
namespace Aelvo;
use InvalidArgumentException;
use RuntimeException;
final class Orders extends Service {
    public const TRANSITIONS=[
        'Pending'=>['Confirmed','Cancelled'],
        'Confirmed'=>['Dispatched','Cancelled'],
        'Dispatched'=>['Delivered'],
        'Delivered'=>[],
        'Cancelled'=>[],
    ];
    public static function nextStatuses(string $current): array {
        return self::TRANSITIONS[$current]??[];
    }
    /** Read up to 30 newline-separated variant,quantity rows from the staff form. */
    public static function parseOrderLines(string $raw): array {
        if(strlen($raw)>2500)throw new InvalidArgumentException('Order lines are too long.');
        $result=[];
        foreach(preg_split('/\r\n|\r|\n/',trim($raw)) as $line){
            $line=trim($line);if($line==='')continue;
            if(!preg_match('/^([1-9][0-9]{0,7})\s*[,;:]\s*([1-9][0-9]{0,2})$/D',$line,$matches))
                throw new InvalidArgumentException('Use one variant ID and quantity per line, e.g. 42,2.');
            $result[]=['variant_id'=>$matches[1],'qty'=>$matches[2]];
            if(count($result)>30)throw new InvalidArgumentException('Keep up to 30 order lines.');
        }
        if(!$result)throw new InvalidArgumentException('Enter at least one order line.');
        return $result;
    }
    // Staff-created orders only. Public checkout must not call this before OTP integration.
    public function create(array $v): int {
        $this->allow('orders','create');
        $name=Input::text($v,'customer_name',120);$phone=Input::phone(Input::text($v,'phone',60));$address=Input::text($v,'address',2000);
        $request=Input::text($v,'request_key',64);
        if (!preg_match('/^[a-f0-9]{64}$/D',$request)) throw new InvalidArgumentException('Reload the order form.');
        $items=$v['items']??null;
        if (!is_array($items) || !$items || count($items)>30) throw new InvalidArgumentException('Choose 1–30 order lines.');
        $lines=[];
        foreach($items as $item) { if(!is_array($item)) throw new InvalidArgumentException('Invalid order line');$id=Input::integer($item['variant_id']??null);$qty=Input::integer($item['qty']??null,1,100);$lines[$id]=($lines[$id]??0)+$qty;if($lines[$id]>100) throw new InvalidArgumentException('Maximum 100 units per variant.'); }
        ksort($lines,SORT_NUMERIC);
        $delivery=Input::money($v['delivery']??'0');
        $key=hash('sha256',$this->actor['id'].':'.$request);
        $hash=hash('sha256',json_encode([$name,$phone,$address,$lines,$delivery],JSON_THROW_ON_ERROR));
        return $this->transaction(function() use($key,$hash,$lines,$name,$phone,$address,$delivery) {
            $q=$this->db->prepare('SELECT id,request_hash FROM aelvo_orders WHERE request_key=?');$q->execute([$key]);$existing=$q->fetch();
            if($existing) { if(!hash_equals($existing['request_hash'],$hash)) throw new RuntimeException('Order request changed. Reload the form.',409);return (int)$existing['id']; }
            $loaded=[];$subtotal=0;
            foreach($lines as $id=>$qty) {
                $q=$this->db->prepare("SELECT v.*,p.name,p.price_minor AS base_price_minor,p.sale_price_minor,p.status AS product_status FROM aelvo_variants v JOIN aelvo_products p ON p.id=v.product_id WHERE v.id=? FOR UPDATE");$q->execute([$id]);$row=$q->fetch();
                $q=$this->db->prepare('SELECT quantity FROM aelvo_variant_quarantine WHERE variant_id=? FOR UPDATE');$q->execute([$id]);$quarantined=(int)($q->fetchColumn()?:0);
                if(!$row || $row['product_status']!=='Active' || !(int)$row['active'] || (int)$row['stock']-(int)$row['reserved']-$quarantined<$qty) throw new InvalidArgumentException('A selected variant is unavailable.');
                $row['price_minor']=VariantPricing::effective((int)$row['base_price_minor'],$row['price_override_minor'],$row['sale_price_minor']===null?null:(int)$row['sale_price_minor']);
                $subtotal+=(int)$row['price_minor']*$qty;$loaded[$id]=$row;
            }
            $taxQuery=$this->db->prepare('SELECT payload FROM aelvo_settings WHERE setting_key=? FOR UPDATE');$taxQuery->execute(['tax']);
            $taxRaw=$taxQuery->fetchColumn();$taxSettings=$taxRaw?json_decode($taxRaw,true,32,JSON_THROW_ON_ERROR):[];
            $tax=TaxPricing::calculate($taxSettings,$subtotal,$delivery);
            $total=$subtotal+$delivery+$tax['tax_minor'];
            $reference='AEL-'.gmdate('ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
            $q=$this->db->prepare("INSERT INTO aelvo_orders(reference,request_key,request_hash,customer_name,phone,address,payment_method,subtotal_minor,delivery_minor,tax_minor,total_minor) VALUES (?,?,?,?,?,?,'COD',?,?,?,?)");
            $q->execute([$reference,$key,$hash,$name,$phone,$address,$subtotal,$delivery,$tax['tax_minor'],$total]);$order=(int)$this->db->lastInsertId();
            OrderTaxes::record($this->db,$order,$tax);
            $event=$this->db->prepare("INSERT INTO aelvo_order_events(order_id,status) VALUES (?,'Pending')");$event->execute([$order]);
            $advance=Payments::advance(Settings::get($this->db,'delivery')['value'],$total);
            $finance=$this->db->prepare('INSERT INTO aelvo_order_finance(order_id,advance_minor) VALUES (?,?)');$finance->execute([$order,$advance]);
            foreach($lines as $id=>$qty) {
                $row=$loaded[$id];$q=$this->db->prepare('UPDATE aelvo_variants SET reserved=reserved+? WHERE id=?');$q->execute([$qty,$id]);
                $q=$this->db->prepare('INSERT INTO aelvo_order_items(order_id,variant_id,product_name,variant_label,qty,unit_price_minor) VALUES (?,?,?,?,?,?)');$q->execute([$order,$id,$row['name'],$row['colour'].' / '.$row['size'],$qty,$row['price_minor']]);
                $this->movement($id,0,$qty,'order-reserved',$order,'Stock reserved for '.$reference);
            }
            $this->audit('order.created:'.$order);return $order;
        });
    }
    public function transition(int $id,string $status,?string $expectedStatus=null): void {
        $this->allow('orders','edit');
        if($status==='Cancelled')$this->allow('orders','cancel');
        $this->transaction(function() use($id,$status,$expectedStatus) {
            $q=$this->db->prepare('SELECT * FROM aelvo_orders WHERE id=? FOR UPDATE');$q->execute([$id]);$order=$q->fetch();
            if(!$order) throw new InvalidArgumentException('Order not found');
            if($expectedStatus!==null && $order['status']!==$expectedStatus) throw new RuntimeException('Order status changed since you opened this page. Reload before taking action.',409);
            if($order['status']===$status) return;
            if(!in_array($status,self::nextStatuses($order['status']),true)) throw new InvalidArgumentException('This order transition is not allowed.');
            // Order row is locked; a parallel gateway initiation must finish creating its attempt
            // before we may release reservations or dispatch/fulfil this order.
            if(in_array($status,['Cancelled','Dispatched','Delivered'],true))GatewayPayments::assertNoUnresolved($this->db,$id);
            if($status==='Dispatched')OrderCancellationRequests::assertNoPending($this->db,$id);
            if(in_array($status,['Dispatched','Delivered'],true))CourierExceptions::assertFulfillment($this->db,$id,$status);
            if($status==='Dispatched'&&(Payments::summary($this->db,$id)['refunded_minor']>0||Returns::committed($this->db,$id)>0))throw new InvalidArgumentException('Resolve this order refund review before dispatch.');
            if($status==='Dispatched'&&Payments::summary($this->db,$id)['advance_due_minor']>0)throw new InvalidArgumentException('Verify the required advance payment before dispatch.');
            if($status==='Cancelled'){
                $courier=$this->db->prepare('SELECT booking_state FROM aelvo_courier_shipments WHERE order_id=? FOR UPDATE');$courier->execute([$id]);
                if($courier->fetchColumn()!==false)throw new InvalidArgumentException('Courier booking exists. Reconcile and cancel with the courier before changing the order.');
            }
            if($status==='Cancelled' && Payments::summary($this->db,$id)['net_received_minor']>0) throw new InvalidArgumentException('Paid orders require a separate refund workflow.');
            if(in_array($status,['Cancelled','Delivered'],true)) {
                $q=$this->db->prepare('SELECT * FROM aelvo_order_items WHERE order_id=? ORDER BY variant_id');$q->execute([$id]);$items=$q->fetchAll();
                $costing=$status==='Delivered'?new InventoryCosting($this->db):null;
                foreach($items as $item) {
                    $qty=(int)$item['qty'];$variant=(int)$item['variant_id'];
                    $q=$this->db->prepare('SELECT stock,reserved FROM aelvo_variants WHERE id=? FOR UPDATE');$q->execute([$variant]);$stock=$q->fetch();
                    if(!$stock || (int)$stock['reserved']<$qty || (int)$stock['stock']<$qty) throw new RuntimeException('Inventory reconciliation required');
                    if($costing!==null){
                        $costing->ensureCoverage($variant,(int)$stock['stock']);
                        $costing->deliverItem((int)$item['id'],$variant,$qty);
                    }
                    $delta=$status==='Delivered'?-$qty:0;
                    $q=$this->db->prepare('UPDATE aelvo_variants SET stock=stock+?,reserved=reserved-? WHERE id=?');$q->execute([$delta,$qty,$variant]);
                    $this->movement($variant,$delta,-$qty,$status==='Delivered'?'order-delivered':'order-cancelled',$id,$status.' '.$order['reference']);
                }
            }
            if($status==='Cancelled')Coupons::release($this->db,$id);
            $q=$this->db->prepare('UPDATE aelvo_orders SET status=? WHERE id=?');$q->execute([$status,$id]);$event=$this->db->prepare('INSERT INTO aelvo_order_events(order_id,status) VALUES (?,?)');$event->execute([$id,$status]);$this->audit('order.'.strtolower($status).':'.$id);
        });
    }
    private function movement(int $variant,int $stock,int $reserved,string $type,int $order,string $reason): void {
        $q=$this->db->prepare('INSERT INTO aelvo_movements(variant_id,stock_delta,reserved_delta,source_type,source_id,explanation,actor_id) VALUES (?,?,?,?,?,?,?)');$q->execute([$variant,$stock,$reserved,$type,(string)$order,$reason,$this->actor['id']]);
    }
}
