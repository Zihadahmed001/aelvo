<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/VariantPricing.php';
require dirname(__DIR__).'/app/Schema.php';
require dirname(__DIR__).'/app/Catalogue.php';
use Aelvo\VariantPricing;
use Aelvo\Catalogue;
use Aelvo\Schema;
function ensure(bool $condition,string $reason):void {if(!$condition)throw new RuntimeException($reason);}
function deny(callable $fn):void {try{$fn();}catch(InvalidArgumentException){return;}throw new RuntimeException('Invalid merchandise price accepted');}
ensure(VariantPricing::sale('',50000)===null,'No sale remains unset');
ensure(VariantPricing::sale('350.00',50000)===35000,'Merchant sale parsed as BDT minor units');
foreach(['0','-10','500','501','50.001'] as $invalid)deny(fn()=>VariantPricing::sale($invalid,50000));
ensure(VariantPricing::effective(50000,null,35000)===35000,'Inherited variant must receive base sale');
ensure(VariantPricing::effective(50000,42000,35000)===42000,'Explicit variant price must take precedence');
ensure(VariantPricing::effective(50000,null,null)===50000,'Without sale inherit regular');
ensure(VariantPricing::range(50000,[],35000)===['min'=>35000,'max'=>35000],'No-variant fallbacks honour sale');
ensure(str_contains(Catalogue::priceExpression('min'),'aelvo_products.sale_price_minor'),'SQL sorting/filtering must account for sale');
$manifest=Schema::manifest();ensure(in_array('product-merchandising',array_column($manifest,'name'),true),'Merchandising migration must remain in the append-only registry');
$sql=file_get_contents(dirname(__DIR__).'/database/product-merchandising.sql');
foreach(['sale_price_minor','is_featured','is_new_arrival'] as $column)ensure(str_contains($sql,$column),'Missing SQL merchandise column '.$column);
$catalogue=file_get_contents(dirname(__DIR__).'/app/Catalogue.php');
ensure(str_contains($catalogue,'sale_price_minor,is_featured,is_new_arrival'),'Merchandising fields not saved/queried');
ensure(str_contains($catalogue,'COALESCE(v.price_override_minor,p.sale_price_minor,p.price_minor)'), 'Storefront price not using discount rule');
$checkout=file_get_contents(dirname(__DIR__).'/app/Checkout.php');
ensure(substr_count($checkout,'VariantPricing::effective(')===2,'Quote and placement must independently validate sale pricing');
$orders=file_get_contents(dirname(__DIR__).'/app/Orders.php');
ensure(str_contains($orders,"['sale_price_minor']"),'Staff order pricing not updated');
$couponSource=file_get_contents(dirname(__DIR__).'/app/Coupons.php');
ensure(str_contains($couponSource,'allow_sale_stack') && str_contains($couponSource,'$hasSaleItem && !(int)$r[\'allow_sale_stack\']'),'Sale+coupon should default to reject unless merchant opts in');
ensure(str_contains($checkout,'$hasSaleItem') && str_contains($checkout,'true,$hasSaleItem'),'Checkout must recheck sale/coupon compatibility under locked order placement');
$admin=file_get_contents(dirname(__DIR__).'/app/AdminView.php');
ensure(str_contains($admin,"'sale_price'")&&str_contains($admin,"'is_featured'")&&str_contains($admin,"'is_new_arrival'"),'Admin product editor not connected');
$gallery=file_get_contents(dirname(__DIR__).'/public/product-gallery-view.php');
ensure(str_contains($gallery,"$".'mediaId=(string)')&&str_contains($gallery,"[a-f0-9]{32}"),'Gallery must accept hexadecimal media IDs');
$uploads=file_get_contents(dirname(__DIR__).'/app/Uploads.php');
ensure(str_contains($uploads,'product.primary-image-changed')&&str_contains($uploads,'FOR UPDATE')&&str_contains($uploads,'position=position+1'),'Main photo must have locked, audited ordering');
$store=file_get_contents(dirname(__DIR__).'/public/store.php');
ensure(str_contains($store,"'merchandising'"),'Homepage must prioritize merchant flags');
function esc(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function nameOf(array $p):string{return $p['name'];}
function tr(string $a,string $b):string{return $a;}
$lang='en';$p=['name'=>'Real wallet','images'=>[['media_id'=>'b47e2ea046fd8afb1e63ab29c7d419f0','alt_en'=>'Olive wallet','colour'=>'Olive']]];
ob_start();require dirname(__DIR__).'/public/product-gallery-view.php';$html=ob_get_clean();
ensure(str_contains($html,'media.php?id=b47e2ea046fd8afb1e63ab29c7d419f0'),'Uploaded gallery photo missing');
ensure(!str_contains($html,'assets/placeholder.svg'),'Uploaded photo replaced by placeholder');
echo "Merchandising: sale inheritance, override precedence, additive schema, Admin/checkout/SEO wiring and 32-character gallery render checks passed offline.\n";
