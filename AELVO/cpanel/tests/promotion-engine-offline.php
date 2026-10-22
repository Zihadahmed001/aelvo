<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/Coupons.php';
require dirname(__DIR__).'/app/Schema.php';
use Aelvo\Coupons;
use Aelvo\Schema;
function ensure(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejects(callable $callback,string $reason):void{
    try{$callback();}catch(InvalidArgumentException){return;}
    throw new RuntimeException('Expected refusal: '.$reason);
}
$lines=[
    ['variant_id'=>11,'product_id'=>2,'category_id'=>21,'category_parent_id'=>10,'brand_id'=>3,'price_minor'=>10000,'qty'=>2],
    ['variant_id'=>14,'product_id'=>5,'category_id'=>30,'category_parent_id'=>0,'brand_id'=>4,'price_minor'=>8000,'qty'=>1],
    ['variant_id'=>18,'product_id'=>8,'category_id'=>21,'category_parent_id'=>10,'brand_id'=>7,'price_minor'=>5000,'qty'=>1],
];
$all=Coupons::eligibleLines(['scope_kind'=>'all'],$lines);
ensure(array_sum($all)===33000,'All merchandise subtotal incorrect.');
$cat=Coupons::eligibleLines(['scope_kind'=>'category','scope_id'=>10],$lines);
ensure($cat===[11=>20000,18=>5000],'Parent category must include child items only.');
ensure(Coupons::eligibleLines(['scope_kind'=>'category','scope_id'=>30],$lines)===[14=>8000],'Exact category should match.');
ensure(Coupons::eligibleLines(['scope_kind'=>'brand','scope_id'=>3],$lines)===[11=>20000],'Brand targeting ignored.');
ensure(Coupons::eligibleLines(['scope_kind'=>'product','scope_id'=>8],$lines)===[18=>5000],'Product targeting ignored.');
ensure(Coupons::eligibleLines(['scope_kind'=>'product','scope_id'=>999],$lines)===[],'Ineligible product received discount.');
$now=123456;
$percent=['active'=>1,'starts_at'=>$now,'ends_at'=>$now+100,'usage_limit'=>20,'used_count'=>19,'minimum_minor'=>20000,'kind'=>'percent','amount'=>10,'cap_minor'=>2000];
ensure(Coupons::discount($percent,25000,$now)===2000,'Discount cap not applied to eligible subtotal.');
ensure(Coupons::allocate($cat,2000)===[11=>1600,18=>400],'Mixed-cart allocation incorrect.');
ensure(array_sum(Coupons::allocate([11=>100,14=>101,18=>102],83))===83,'Integer allocation must preserve exact discount.');
ensure(Coupons::allocate([11=>1,14=>1,18=>1],2)===[11=>1,14=>1,18=>0],'Remainder allocation must be deterministic.');
foreach(Coupons::allocate([11=>100,14=>101,18=>102],83) as $v=>$discount)ensure($discount<=[11=>100,14=>101,18=>102][$v],'Negative item payable.');
rejects(fn()=>Coupons::discount($percent,19999,$now),'Below minimum eligible subtotal');
rejects(fn()=>Coupons::discount($percent,25000,$now+100),'End is exclusive');
rejects(fn()=>Coupons::discount($percent,25000,$now-1),'Start is inclusive');
rejects(fn()=>Coupons::discount([...$percent,'used_count'=>20],25000,$now),'Global redemption cap');
ensure(Coupons::discount([...$percent,'kind'=>'fixed','amount'=>50000,'cap_minor'=>null],25000,$now)===25000,'Fixed discount cannot exceed eligible subtotal.');
ensure(Coupons::discount([...$percent,'kind'=>'free_shipping'],25000,$now)===0,'Shipping discount must never subtract merchandise.');
rejects(fn()=>Coupons::allocate([11=>100],101),'Discount exceeds eligible merchandise');
rejects(fn()=>Coupons::eligibleLines(['scope_kind'=>'all'],[$lines[0],$lines[0]]),'Repeated variant should fail closed');
$manifest=Schema::manifest();
ensure(end($manifest)['name']==='coupon-eligibility','New eligibility schema not registered last.');
$sql=file_get_contents(dirname(__DIR__).'/database/coupon-eligibility.sql');
foreach(['scope_kind','per_customer_limit','first_order_only','shipping_discount_minor','coupon_discount_minor'] as $field)
    ensure(str_contains($sql,$field),'Missing schema property '.$field);
$checkout=file_get_contents(dirname(__DIR__).'/app/Checkout.php');
ensure(substr_count($checkout,'Coupons::quote(')===2,'Coupon must be independently checked at quote and placement.');
ensure(str_contains($checkout,'FOR UPDATE')&&str_contains($checkout,'shipping_discount_minor')&&str_contains($checkout,'coupon_discount_minor'),'Account lock, shipping promotion or order-item allocation missing.');
$admin=file_get_contents(dirname(__DIR__).'/app/AdminView.php');
foreach(['per_customer_limit','first_order_only','scope_kind','free_shipping','Asia/Dhaka'] as $field)
    ensure(str_contains($admin,$field),'Admin coupon field missing: '.$field);
echo "Promotion engine: eligibility scopes, exact item allocation, min spend, caps, UTC epoch boundaries, free-shipping isolation and Admin/checkout wiring passed offline.\n";
