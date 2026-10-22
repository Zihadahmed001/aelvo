<?php
declare(strict_types=1);
namespace Aelvo;
use PDO;
use InvalidArgumentException;
use RuntimeException;

/** Server-authoritative, account-scoped coupons. No guest identity is inferred from a phone. */
final class Coupons extends Service {
    private const STORE_TIMEZONE='Asia/Dhaka';
    public static function code(string $value): string {
        $code=strtoupper(trim($value));
        if(!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/D',$code))throw new InvalidArgumentException('Coupon codes use 3–40 letters, numbers, hyphens or underscores.');
        return $code;
    }
    private static function date(array $v,string $key): ?int {
        $value=Input::text($v,$key,16,false);
        if($value==='')return null;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new \DateTimeZone(self::STORE_TIMEZONE));
        if(!$date||$date->format('Y-m-d\TH:i')!==$value)throw new InvalidArgumentException('Use a valid Asia/Dhaka date.');
        return $date->getTimestamp();
    }
    public function save(array $v): int {
        $id=($v['id']??'')!==''?Input::integer($v['id']):null;
        $this->allow('coupons',$id?'edit':'create');
        $code=self::code(Input::text($v,'code',40));
        $kind=Input::text($v,'kind',20);
        if(!in_array($kind,['fixed','percent','free_shipping'],true))throw new InvalidArgumentException('Choose fixed, percentage or free shipping.');
        $amount=$kind==='percent'?Input::integer($v['amount']??null,1,100):($kind==='fixed'?Input::money($v['amount']??null):0);
        if($kind!=='free_shipping'&&$amount<1)throw new InvalidArgumentException('Discount must be positive.');
        $minimum=Input::money($v['minimum']??'0');
        $cap=($kind==='free_shipping'||($v['cap']??'')==='')?null:Input::money($v['cap']);
        $limit=($v['usage_limit']??'')!==''?Input::integer($v['usage_limit'],1,100000000):null;
        $perCustomer=($v['per_customer_limit']??'')!==''?Input::integer($v['per_customer_limit'],1,100000):null;
        $start=self::date($v,'starts_at');$end=self::date($v,'ends_at');
        if($start!==null&&$end!==null&&$end<=$start)throw new InvalidArgumentException('End must be after start.');
        $saleStack=(string)($v['allow_sale_stack']??'0');
        if(!in_array($saleStack,['0','1'],true))throw new InvalidArgumentException('Invalid sale/coupon combination policy.');
        $firstOrder=(string)($v['first_order_only']??'0');
        if(!in_array($firstOrder,['0','1'],true))throw new InvalidArgumentException('Invalid first-order policy.');
        $scope=Input::text($v,'scope_kind',20,false)?:'all';
        if(!in_array($scope,['all','product','category','brand'],true))throw new InvalidArgumentException('Unknown coupon product eligibility.');
        $scopeId=$scope==='all'?null:Input::integer($v['scope_id']??null);
        $customer=($v['customer_id']??'')!==''?Input::integer($v['customer_id']):null;
        if($scope==='product'){
            $q=$this->db->prepare("SELECT 1 FROM aelvo_products WHERE id=? AND status='Active'");$q->execute([$scopeId]);
            if(!$q->fetchColumn())throw new InvalidArgumentException('Choose an active product.');
        }elseif($scope==='category'||$scope==='brand'){
            $table=$scope==='category'?'aelvo_categories':'aelvo_brands';
            $q=$this->db->prepare('SELECT 1 FROM '.$table.' WHERE id=? AND status=?');$q->execute([$scopeId,'Active']);
            if(!$q->fetchColumn())throw new InvalidArgumentException('Choose an active category or brand.');
        }
        if($customer!==null){
            $this->allow('customers','view');
            $q=$this->db->prepare("SELECT 1 FROM aelvo_customers WHERE id=? AND status='Active' AND verified_at>0");$q->execute([$customer]);
            if(!$q->fetchColumn())throw new InvalidArgumentException('Customer-specific coupon needs a verified active customer.');
        }
        $fields=[$code,$kind,$amount,$minimum,$cap,$limit,$start,$end,($v['active']??'0')==='1'?1:0,(int)$saleStack,$scope,$scopeId,$perCustomer,(int)$firstOrder,$customer];
        return $this->transaction(function()use($id,$v,$fields,$limit,$perCustomer){
            if($id){
                $q=$this->db->prepare('SELECT used_count,per_customer_limit FROM aelvo_coupons WHERE id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch();
                if(!$old||($limit!==null&&$limit<(int)$old['used_count']))throw new InvalidArgumentException('Usage limit cannot be below existing uses.');
                // Shrinking limits retroactively requires a separate audit/reconciliation workflow.
                if($perCustomer!==null){
                    $q=$this->db->prepare('SELECT COUNT(*) AS n FROM aelvo_coupon_redemptions r JOIN aelvo_customer_orders co ON co.order_id=r.order_id WHERE r.coupon_id=? AND r.released_at IS NULL GROUP BY co.customer_id ORDER BY n DESC LIMIT 1');
                    $q->execute([$id]);$maxUsed=(int)($q->fetchColumn()?:0);
                    if($perCustomer<$maxUsed)throw new InvalidArgumentException('Per-customer limit cannot be below redeemed uses.');
                }
                $q=$this->db->prepare('UPDATE aelvo_coupons SET code=?,kind=?,amount=?,minimum_minor=?,cap_minor=?,usage_limit=?,starts_at=?,ends_at=?,active=?,allow_sale_stack=?,scope_kind=?,scope_id=?,per_customer_limit=?,first_order_only=?,customer_id=?,version=version+1 WHERE id=? AND version=?');
                $q->execute([...$fields,$id,Input::integer($v['version']??null)]);
                if($q->rowCount()!==1)throw new RuntimeException('Coupon changed. Reload before saving.',409);
            }else{
                $q=$this->db->prepare('INSERT INTO aelvo_coupons(code,kind,amount,minimum_minor,cap_minor,usage_limit,starts_at,ends_at,active,allow_sale_stack,scope_kind,scope_id,per_customer_limit,first_order_only,customer_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $q->execute($fields);$id=(int)$this->db->lastInsertId();
            }
            $this->audit('coupon.saved:'.$id);return $id;
        });
    }
    public static function eligibleLines(array $coupon,array $lines): array {
        $eligible=[];$scope=(string)($coupon['scope_kind']??'all');$id=(int)($coupon['scope_id']??0);
        foreach($lines as $line){
            $product=(int)($line['product_id']??0);
            $category=(int)($line['category_id']??0);
            $parent=(int)($line['category_parent_id']??0);
            $brand=(int)($line['brand_id']??0);
            $matches=match($scope){
                'all'=>true,
                'product'=>$id>0&&$product===$id,
                'category'=>$id>0&&($category===$id||$parent===$id),
                'brand'=>$id>0&&$brand===$id,
                default=>false
            };
            if($matches){
                $variant=(int)($line['variant_id']??0);
                $minor=(int)($line['price_minor']??0);$qty=(int)($line['qty']??0);
                if($variant<=0||$minor<0||$qty<=0||isset($eligible[$variant])||$minor>intdiv(PHP_INT_MAX,$qty))throw new InvalidArgumentException('Invalid promotion cart lines.');
                $eligible[$variant]=$minor*$qty;
            }
        }
        ksort($eligible,SORT_NUMERIC);
        $sum=0;foreach($eligible as $value){if($value>PHP_INT_MAX-$sum)throw new InvalidArgumentException('Promotion amount is too large.');$sum+=$value;}
        return $eligible;
    }
    /** Allocate exact integer minor units only to eligible order items. Stable on retries. */
    public static function allocate(array $eligible,int $discount): array {
        $total=array_sum($eligible);
        if($discount<0||$discount>$total)throw new InvalidArgumentException('Invalid promotion amount.');
        if($total===0)return [];
        $alloc=[];$remainder=[];$assigned=0;
        foreach($eligible as $variant=>$value){
            if($value!==0 && $discount>intdiv(PHP_INT_MAX,$value))throw new InvalidArgumentException('Promotion amount is too large.');
            $numerator=$discount*$value;
            $alloc[(int)$variant]=intdiv($numerator,$total);
            $remainder[(int)$variant]=$numerator%$total;
            $assigned+=$alloc[(int)$variant];
        }
        $keys=array_keys($alloc);
        usort($keys,static fn($a,$b)=>($remainder[$b]<=>$remainder[$a])?:($a<=>$b));
        for($i=0;$i<$discount-$assigned;$i++)$alloc[$keys[$i]]++;
        ksort($alloc,SORT_NUMERIC);return $alloc;
    }
    public static function discount(array $coupon,int $eligibleSubtotal,int $now): int {
        if(!(int)$coupon['active']||($coupon['starts_at']!==null&&(int)$coupon['starts_at']>$now)||($coupon['ends_at']!==null&&(int)$coupon['ends_at']<=$now)||($coupon['usage_limit']!==null&&(int)$coupon['used_count']>=(int)$coupon['usage_limit'])||$eligibleSubtotal<(int)$coupon['minimum_minor']||$eligibleSubtotal<=0)
            throw new InvalidArgumentException('Coupon is unavailable or the minimum eligible spend is not met.');
        $discount=match($coupon['kind']){
            'percent'=>intdiv($eligibleSubtotal,100)*(int)$coupon['amount'] + intdiv(($eligibleSubtotal%100)*(int)$coupon['amount'],100),
            'fixed'=>(int)$coupon['amount'],
            'free_shipping'=>0,
            default=>throw new InvalidArgumentException('Unsupported promotion benefit.')
        };
        if($coupon['cap_minor']!==null)$discount=min($discount,(int)$coupon['cap_minor']);
        return min($eligibleSubtotal,$discount);
    }
    private static function accountEligibility(PDO $db,array $coupon,?int $customerId): void {
        if($coupon['per_customer_limit']===null&&!(int)$coupon['first_order_only']&&$coupon['customer_id']===null)return;
        if(!$customerId||($coupon['customer_id']!==null&&(int)$coupon['customer_id']!==$customerId))
            throw new InvalidArgumentException('This offer requires an eligible signed-in verified customer.');
        if((int)$coupon['first_order_only']){
            $q=$db->prepare('SELECT 1 FROM aelvo_customer_orders WHERE customer_id=? LIMIT 1');$q->execute([$customerId]);
            if($q->fetchColumn())throw new InvalidArgumentException('This offer is reserved for a customer’s first order.');
        }
        if($coupon['per_customer_limit']!==null){
            $q=$db->prepare('SELECT COUNT(*) FROM aelvo_coupon_redemptions r JOIN aelvo_customer_orders co ON co.order_id=r.order_id WHERE r.coupon_id=? AND co.customer_id=? AND r.released_at IS NULL');
            $q->execute([$coupon['id'],$customerId]);
            if((int)$q->fetchColumn()>=(int)$coupon['per_customer_limit'])throw new InvalidArgumentException('This offer has reached your account usage limit.');
        }
    }
    public static function quote(PDO $db,string $code,int $subtotal,bool $lock=false,bool $hasSaleItem=false,array $lines=[],?int $customerId=null): ?array {
        if(trim($code)==='')return null;
        if($lock&&!$db->inTransaction())throw new RuntimeException('Coupon lock requires a transaction.');
        $q=$db->prepare('SELECT * FROM aelvo_coupons WHERE code=?'.($lock?' FOR UPDATE':''));$q->execute([self::code($code)]);$r=$q->fetch();
        if(!$r)throw new InvalidArgumentException('Coupon is unavailable or the minimum spend is not met.');
        if($hasSaleItem && !(int)$r['allow_sale_stack'])throw new InvalidArgumentException('This coupon cannot be combined with sale-priced products. Remove the code or review your bag.');
        $eligible=self::eligibleLines($r,$lines);
        $eligibleSubtotal=array_sum($eligible);
        if($eligibleSubtotal>$subtotal)throw new InvalidArgumentException('Promotion cart totals do not match.');
        self::accountEligibility($db,$r,$customerId);
        $discount=self::discount($r,$eligibleSubtotal,time());
        return ['id'=>(int)$r['id'],'code'=>$r['code'],'version'=>(int)$r['version'],'kind'=>$r['kind'],
            'discount_minor'=>$discount,'allocations'=>self::allocate($eligible,$discount)];
    }
    public static function redeem(PDO $db,int $order,array $coupon,int $shippingDiscount=0): void {
        if(!$db->inTransaction())throw new RuntimeException('Coupon redemption requires the order transaction.');
        $q=$db->prepare('UPDATE aelvo_coupons SET used_count=used_count+1 WHERE id=? AND version=? AND active=1 AND (usage_limit IS NULL OR used_count<usage_limit)');
        $q->execute([$coupon['id'],$coupon['version']]);
        if($q->rowCount()!==1)throw new InvalidArgumentException('Coupon availability changed. Review a new checkout.');
        $q=$db->prepare('INSERT INTO aelvo_coupon_redemptions(order_id,coupon_id,code,discount_minor,shipping_discount_minor) VALUES (?,?,?,?,?)');
        $q->execute([$order,$coupon['id'],$coupon['code'],$coupon['discount_minor'],$shippingDiscount]);
    }
    public static function release(PDO $db,int $order): void {
        if(!$db->inTransaction())throw new RuntimeException('Coupon release requires the order transaction.');
        $q=$db->prepare('SELECT * FROM aelvo_coupon_redemptions WHERE order_id=? FOR UPDATE');$q->execute([$order]);$r=$q->fetch();
        if(!$r||$r['released_at']!==null)return;
        $q=$db->prepare('UPDATE aelvo_coupons SET used_count=used_count-1 WHERE id=? AND used_count>0');$q->execute([$r['coupon_id']]);
        if($q->rowCount()!==1)throw new RuntimeException('Coupon usage reconciliation required.');
        $q=$db->prepare('UPDATE aelvo_coupon_redemptions SET released_at=? WHERE order_id=?');$q->execute([time(),$order]);
    }
}
