<?php
declare(strict_types=1);
namespace Aelvo;
use PDO;
use InvalidArgumentException;
use RuntimeException;
final class Checkout {
    public function __construct(private PDO $db,private string $session) {}
    public static function lines(array $items): array {
        if(!$items || count($items)>30)throw new InvalidArgumentException('Choose 1–30 variants.');
        $lines=[];foreach($items as $id=>$qty)$lines[Input::integer($id)]=Input::integer($qty,1,100);
        ksort($lines,SORT_NUMERIC);return $lines;
    }
    public function quote(array $input,array $items,bool $direct): string {
        $settings=Settings::get($this->db,'delivery')['value'];
        if(($settings['cod_enabled']??false)!==true)throw new InvalidArgumentException('Online ordering is not available yet.');
        $name=Input::text($input,'customer_name',120);$phone=Input::phone(Input::text($input,'phone',60));$address=Input::text($input,'address',2000);
        $zone=Input::text($input,'zone',30);
        if(!in_array($zone,['dhaka','outside'],true))throw new InvalidArgumentException('Choose a delivery area.');
        $lines=self::lines($items);$rows=[];$subtotal=0;$hasSaleItem=false;
        foreach($lines as $id=>$qty){
            $q=$this->db->prepare('SELECT v.*,p.name,p.price_minor AS base_price_minor,p.sale_price_minor,p.status,t.category_id,t.brand_id,c.parent_id AS category_parent_id FROM aelvo_variants v JOIN aelvo_products p ON p.id=v.product_id LEFT JOIN aelvo_product_taxonomy t ON t.product_id=p.id LEFT JOIN aelvo_categories c ON c.id=t.category_id WHERE v.id=?');$q->execute([$id]);$r=$q->fetch();
            $q=$this->db->prepare('SELECT quantity FROM aelvo_variant_quarantine WHERE variant_id=?');$q->execute([$id]);$quarantined=(int)($q->fetchColumn()?:0);
            if(!$r || $r['status']!=='Active' || !(int)$r['active'] || (int)$r['stock']-(int)$r['reserved']-$quarantined<$qty)throw new InvalidArgumentException('An item is unavailable. Please update your bag.');
            $unit=VariantPricing::effective((int)$r['base_price_minor'],$r['price_override_minor'],$r['sale_price_minor']===null?null:(int)$r['sale_price_minor']);
            if($r['price_override_minor']===null && $r['sale_price_minor']!==null && $unit<(int)$r['base_price_minor'])$hasSaleItem=true;
            $rows[]=['variant_id'=>$id,'qty'=>$qty,'name'=>$r['name'],'label'=>$r['colour'].' / '.$r['size'],'price_minor'=>$unit,'product_id'=>(int)$r['product_id'],'category_id'=>(int)($r['category_id']??0),'category_parent_id'=>(int)($r['category_parent_id']??0),'brand_id'=>(int)($r['brand_id']??0)];$subtotal+=$unit*$qty;
        }
        $customerId=Customers::current($this->db)['id']??null;
        $coupon=Coupons::quote($this->db,Input::text($input,'coupon',40,false),$subtotal,false,$hasSaleItem,$rows,$customerId===null?null:(int)$customerId);$discount=$coupon['discount_minor']??0;
        $baseFee=DeliveryPricing::fee($settings,$zone,$subtotal-$discount);
        $shippingDiscount=($coupon['kind']??'')==='free_shipping'?$baseFee:0;
        $fee=$baseFee-$shippingDiscount;
        $tax=TaxPricing::calculate(Settings::get($this->db,'tax')['value'],$subtotal-$discount,$fee);
        $total=$subtotal+$fee-$discount+$tax['tax_minor'];
        $payload=['tax'=>$tax,'advance_minor'=>Payments::advance($settings,$total),'coupon'=>$coupon,'discount_minor'=>$discount,'shipping_discount_minor'=>$shippingDiscount,'customer_name'=>$name,'phone'=>$phone,'address'=>$address,'zone'=>$zone,'items'=>$rows,'delivery_minor'=>$fee,'subtotal_minor'=>$subtotal,'direct'=>$direct,'customer_id'=>$customerId];
        $id=bin2hex(random_bytes(32));
        $q=$this->db->prepare('INSERT INTO aelvo_checkouts(id,session_hash,payload,total_minor,expires_at) VALUES (?,?,?,?,?)');
        $q->execute([$id,hash('sha256',$this->session),json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$total,time()+900]);return $id;
    }
    public function get(string $id,bool $lock=false): array {
        if(!preg_match('/^[a-f0-9]{64}$/D',$id))throw new InvalidArgumentException('Checkout not found.');
        $q=$this->db->prepare('SELECT * FROM aelvo_checkouts WHERE id=? AND session_hash=?'.($lock?' FOR UPDATE':''));$q->execute([$id,hash('sha256',$this->session)]);$r=$q->fetch();
        if(!$r)throw new InvalidArgumentException('Checkout not found.');
        if(!$r['order_id'] && (int)$r['expires_at']<=time())throw new InvalidArgumentException('Checkout expired. Review your bag and try again.');
        $r['data']=json_decode($r['payload'],true,32,JSON_THROW_ON_ERROR);return $r;
    }
    public function sendCode(string $id,string $ip,callable $send): void {
        $r=$this->get($id);if($r['order_id'])throw new InvalidArgumentException('This order is already placed.');
        if((int)$r['otp_sent_at']>time()-60)throw new InvalidArgumentException('Wait 60 seconds before requesting another code.');
        $phone=$r['data']['phone'];
        $phoneAllowed=Security::throttle($this->db,'checkout-sms-phone:'.$phone,5);
        $ipAllowed=Security::throttle($this->db,'checkout-sms-ip:'.$ip,15);
        if(!$phoneAllowed||!$ipAllowed)throw new InvalidArgumentException('Too many code requests. Try again after 15 minutes.');
        $code=(string)random_int(100000,999999);$hash=password_hash($code,PASSWORD_DEFAULT);$now=time();
        // Invalidate the previous grant before network I/O. Failed/ambiguous delivery never unlocks checkout.
        $q=$this->db->prepare('UPDATE aelvo_checkouts SET otp_hash=NULL,verified_at=NULL,otp_attempts=0,otp_sent_at=?,otp_expires_at=0 WHERE id=? AND order_id IS NULL AND otp_sent_at<=?');
        $q->execute([$now,$id,$now-60]);if($q->rowCount()!==1)throw new InvalidArgumentException('Wait before requesting another code.');
        $send($phone,$code);
        $q=$this->db->prepare('UPDATE aelvo_checkouts SET otp_hash=?,otp_expires_at=? WHERE id=? AND otp_sent_at=? AND order_id IS NULL');$q->execute([$hash,time()+300,$id,$now]);
    }
    public function verify(string $id,string $code): void {
        $this->db->beginTransaction();$valid=false;
        try {
            $r=$this->get($id,true);
            if(!$r['order_id'] && $r['otp_hash'] && (int)$r['otp_expires_at']>time() && (int)$r['otp_attempts']<5){
                $valid=preg_match('/^[0-9]{6}$/D',$code)===1 && password_verify($code,$r['otp_hash']);
                $q=$this->db->prepare('UPDATE aelvo_checkouts SET otp_attempts=otp_attempts+1,verified_at=?,otp_hash=IF(?=1,NULL,otp_hash) WHERE id=?');$q->execute([$valid?time():null,$valid?1:0,$id]);
            }
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        // Wrong-code attempts must commit, not roll back with the validation exception.
        if(!$valid)throw new InvalidArgumentException('Invalid or expired verification code.');
    }
    public function verified(array $quote,bool $lock=false): bool {
        if($quote['verified_at'] && (int)$quote['verified_at']>time()-600)return true;
        $customer=Customers::current($this->db);
        if(!$customer || (int)($quote['data']['customer_id']??0)!==(int)$customer['id'])return false;
        $q=$this->db->prepare("SELECT phone,verified_at FROM aelvo_customers WHERE id=? AND status='Active'".($lock?' FOR UPDATE':''));$q->execute([$customer['id']]);$r=$q->fetch();
        return $r && (int)$r['verified_at']>0 && hash_equals($r['phone'],$quote['data']['phone']);
    }
    public function place(string $id): int {
        $this->db->beginTransaction();
        try{
            $r=$this->get($id,true);
            if($r['order_id']){$this->db->commit();return (int)$r['order_id'];}
            if(!$this->verified($r,true))throw new InvalidArgumentException('Verify your phone before placing the order.');
            $data=$r['data'];
            $customer=Customers::current($this->db);
            if(($data['customer_id']??null)!==null && (!$customer || (int)$customer['id']!==(int)$data['customer_id']))throw new InvalidArgumentException('Account session changed. Review a new checkout.');
            if($customer && ($data['customer_id']??null)!==null){
                // Serialize all account coupon checkouts, including OTP-verified alternate phones.
                // Otherwise two checkouts can both observe a remaining per-account use.
                $q=$this->db->prepare("SELECT id FROM aelvo_customers WHERE id=? AND status='Active' AND verified_at>0 FOR UPDATE");
                $q->execute([(int)$customer['id']]);
                if(!$q->fetchColumn())throw new InvalidArgumentException('Verified customer account is unavailable.');
            }
            if($customer)SavedBag::lock($this->db,(int)$customer['id']);
            $q=$this->db->prepare('SELECT payload FROM aelvo_settings WHERE setting_key=? FOR UPDATE');$q->execute(['delivery']);$raw=$q->fetchColumn();$settings=$raw?json_decode($raw,true,32,JSON_THROW_ON_ERROR):[];
            if(($settings['cod_enabled']??false)!==true)throw new InvalidArgumentException('Online ordering is currently closed.');
            $hasSaleItem=false;$freshItems=[];
            foreach($data['items'] as $item){
                $q=$this->db->prepare('SELECT v.*,p.status,p.price_minor AS base_price_minor,p.sale_price_minor,t.category_id,t.brand_id,c.parent_id AS category_parent_id FROM aelvo_variants v JOIN aelvo_products p ON p.id=v.product_id LEFT JOIN aelvo_product_taxonomy t ON t.product_id=p.id LEFT JOIN aelvo_categories c ON c.id=t.category_id WHERE v.id=? FOR UPDATE');$q->execute([$item['variant_id']]);$v=$q->fetch();
                $q=$this->db->prepare('SELECT quantity FROM aelvo_variant_quarantine WHERE variant_id=? FOR UPDATE');$q->execute([$item['variant_id']]);$quarantined=(int)($q->fetchColumn()?:0);
                if(!$v || $v['status']!=='Active' || !(int)$v['active'] || (int)$v['stock']-(int)$v['reserved']-$quarantined<$item['qty'] || VariantPricing::effective((int)$v['base_price_minor'],$v['price_override_minor'],$v['sale_price_minor']===null?null:(int)$v['sale_price_minor'])!==(int)$item['price_minor'])throw new InvalidArgumentException('Stock or price changed. Please review a new checkout.');
                if($v['price_override_minor']===null && $v['sale_price_minor']!==null && (int)$item['price_minor']<(int)$v['base_price_minor'])$hasSaleItem=true;
                $freshItems[]=['variant_id'=>(int)$item['variant_id'],'price_minor'=>(int)$item['price_minor'],'qty'=>(int)$item['qty'],
                    'product_id'=>(int)$v['product_id'],'category_id'=>(int)($v['category_id']??0),'category_parent_id'=>(int)($v['category_parent_id']??0),'brand_id'=>(int)($v['brand_id']??0)];
            }
            $coupon=null;
            if(!empty($data['coupon'])){
                $coupon=Coupons::quote($this->db,$data['coupon']['code'],$data['subtotal_minor'],true,$hasSaleItem,$freshItems,($data['customer_id']??null)===null?null:(int)$data['customer_id']);
                if($coupon!==$data['coupon'])throw new InvalidArgumentException('Coupon changed. Review a new checkout.');
            }
            $discount=(int)($coupon['discount_minor']??0);
            $baseFee=DeliveryPricing::fee($settings,(string)$data['zone'],(int)$data['subtotal_minor']-$discount);
            $shippingDiscount=($coupon['kind']??'')==='free_shipping'?$baseFee:0;
            if($shippingDiscount!==(int)($data['shipping_discount_minor']??0))throw new InvalidArgumentException('Shipping promotion changed. Review a new checkout.');
            $actualFee=$baseFee-$shippingDiscount;
            if($actualFee!==(int)$data['delivery_minor'])throw new InvalidArgumentException('Delivery rate or eligibility changed. Review a new checkout.');
            $q=$this->db->prepare('SELECT payload FROM aelvo_settings WHERE setting_key=? FOR UPDATE');$q->execute(['tax']);
            $taxRaw=$q->fetchColumn();$taxSettings=$taxRaw?json_decode($taxRaw,true,32,JSON_THROW_ON_ERROR):[];
            $actualTax=TaxPricing::calculate($taxSettings,(int)$data['subtotal_minor']-$discount,$actualFee);
            if(($data['tax']??null)!==$actualTax)throw new InvalidArgumentException('Tax settings changed. Review a new checkout.');
            $actualTotal=(int)$data['subtotal_minor']+$actualFee-$discount+$actualTax['tax_minor'];
            if($actualTotal!==(int)$r['total_minor'])throw new RuntimeException('Checkout amount reconciliation required.');
            if(Payments::advance($settings,$actualTotal)!==(int)($data['advance_minor']??0))throw new InvalidArgumentException('Advance requirement changed. Review a new checkout.');
            $reference='AEL-'.gmdate('ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
            $q=$this->db->prepare("INSERT INTO aelvo_orders(reference,request_key,request_hash,customer_name,phone,address,payment_method,subtotal_minor,delivery_minor,tax_minor,total_minor) VALUES (?,?,?,?,?,?,'COD',?,?,?,?)");
            $q->execute([$reference,hash('sha256','checkout:'.$id),hash('sha256',$r['payload']),$data['customer_name'],$data['phone'],$data['address'],$data['subtotal_minor'],$data['delivery_minor'],$actualTax['tax_minor'],$r['total_minor']]);$order=(int)$this->db->lastInsertId();
            OrderTaxes::record($this->db,$order,$actualTax);
            $event=$this->db->prepare("INSERT INTO aelvo_order_events(order_id,status) VALUES (?,'Pending')");$event->execute([$order]);
            $finance=$this->db->prepare('INSERT INTO aelvo_order_finance(order_id,advance_minor) VALUES (?,?)');$finance->execute([$order,$data['advance_minor']??0]);
            if($coupon)Coupons::redeem($this->db,$order,$coupon,$shippingDiscount);
            if(($data['customer_id']??null)!==null){$q=$this->db->prepare('INSERT INTO aelvo_customer_orders(order_id,customer_id) VALUES (?,?)');$q->execute([$order,$data['customer_id']]);}
            foreach($data['items'] as $item){
                $q=$this->db->prepare('UPDATE aelvo_variants SET reserved=reserved+? WHERE id=?');$q->execute([$item['qty'],$item['variant_id']]);
                $q=$this->db->prepare('INSERT INTO aelvo_order_items(order_id,variant_id,product_name,variant_label,qty,unit_price_minor,coupon_discount_minor) VALUES (?,?,?,?,?,?,?)');$q->execute([$order,$item['variant_id'],$item['name'],$item['label'],$item['qty'],$item['price_minor'],$coupon['allocations'][(int)$item['variant_id']]??0]);
                $q=$this->db->prepare("INSERT INTO aelvo_movements(variant_id,stock_delta,reserved_delta,source_type,source_id,explanation,actor_id) VALUES (?,0,?,'order-reserved',?,?,NULL)");$q->execute([$item['variant_id'],$item['qty'],(string)$order,'Online checkout '.$reference]);
            }
            if($customer&&!$data['direct'])SavedBag::purchased($this->db,(int)$customer['id'],$data['items']);
            $q=$this->db->prepare('UPDATE aelvo_checkouts SET order_id=?,otp_hash=NULL WHERE id=?');$q->execute([$order,$id]);
            $q=$this->db->prepare('INSERT INTO aelvo_audit(actor_id,event) VALUES (NULL,?)');$q->execute(['checkout.order-created:'.$order]);
            $this->db->commit();return $order;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
