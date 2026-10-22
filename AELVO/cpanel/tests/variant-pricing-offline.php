<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/VariantPricing.php';
require dirname(__DIR__).'/app/PublicRoutes.php';
require dirname(__DIR__).'/app/Catalogue.php';
require dirname(__DIR__).'/app/Seo.php';

use Aelvo\VariantPricing;
use Aelvo\Catalogue;
use Aelvo\Seo;
function ok(bool $result,string $description):void {if(!$result)throw new RuntimeException($description);}
function bad(callable $fn):void {
    try{$fn();}catch(InvalidArgumentException){return;}
    throw new RuntimeException('Invalid price accepted');
}
ok(VariantPricing::override('')===null,'Blank does not inherit');
ok(VariantPricing::override('1250.75')===125075,'Override parser');
ok(VariantPricing::effective(40000,null)===40000,'Original base price compatibility');
ok(VariantPricing::effective(40000,'28999')===28999,'Overridden price');
bad(fn()=>VariantPricing::override('0.00'));
bad(fn()=>VariantPricing::override('1.234'));
bad(fn()=>VariantPricing::override('-1'));
bad(fn()=>VariantPricing::effective(40000,'-3'));
$variants=[['sku'=>'A-1','price_minor'=>15000,'available'=>0],['sku'=>'A-2','price_minor'=>27500,'available'=>2],['sku'=>'A-3','price_minor'=>31000,'available'=>1]];
ok(VariantPricing::range(30000,$variants)===['min'=>27500,'max'=>31000],'Exclude unavailable variant from price range');
ok(VariantPricing::range(30000,[])===['min'=>30000,'max'=>30000],'No variants inherit base price');
[$where,$args]=Catalogue::queryParts(['min'=>'250.00','max'=>'300.00']);
ok(count($args)===2 && substr_count($where,'?')===2,'Price filter placeholders');
ok(str_contains($where,'MAX(COALESCE(vp.price_override_minor')&&str_contains($where,'MIN(COALESCE(vp.price_override_minor'), 'Variants not used for filters');
ok(str_contains(Catalogue::priceExpression('min'),'MIN(COALESCE'),'Variant price sort expression');
bad(fn()=>Catalogue::priceExpression('DROP TABLE'));
function tr(string $a,string $b):string{return $a;}
function esc(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function photo(array $p,string $colour=''):string{return 'assets/placeholder.svg';}
function nameOf(array $p):string{return $p['name'];}
function photoAlt(array $p,string $colour=''):string{return $p['name'];}
$p=['id'=>5,'slug'=>'test-bag','name'=>'Test bag','description'=>'Test product','category'=>'Bags','price_minor'=>30000,'variant_price_range'=>['min'=>27500,'max'=>31000],'variants'=>$variants];
$pdo=true;
function wishlistButton(int $id):void {}
ob_start();require dirname(__DIR__).'/public/product-card-view.php';$html=ob_get_clean();
ok(str_contains($html,'From') && str_contains($html,'275.00'),'Product card should show from cheapest sellable variant');
$p['variants']=[['id'=>11,'colour'=>'Olive','size'=>'M','colour_hex'=>'#aabbcc','price_minor'=>27500,'available'=>2]];
ob_start();require dirname(__DIR__).'/public/variant-picker-view.php';$picker=ob_get_clean();
ok(str_contains($picker,'data-price-minor="27500"')&&str_contains($picker,'৳275.00'),'Native picker must expose true price without JS');
$meta=['canonical'=>'https://shop.example/product/test-bag','og_type'=>'product','og_image'=>''];
$p['variants']=$variants;
$schema=json_decode(Seo::productStructuredData($p,$meta,'https://shop.example'),true,512,JSON_THROW_ON_ERROR);
ok($schema['offers']['@type']==='AggregateOffer' && $schema['offers']['lowPrice']==='275.00' && $schema['offers']['highPrice']==='310.00','Structured data not using visible price range');
foreach(['Checkout.php'=>'VariantPricing::effective(', 'Orders.php'=>'VariantPricing::effective('] as $service=>$needle)
    ok(str_contains(file_get_contents(dirname(__DIR__).'/app/'.$service),$needle),$service.' missing transactional price resolver');
$checkout=file_get_contents(dirname(__DIR__).'/app/Checkout.php');
ok(substr_count($checkout,'VariantPricing::effective(')===2,'Quote and placement must both reprice selected variants');
$admin=file_get_contents(dirname(__DIR__).'/app/AdminView.php');
ok(str_contains($admin,"'variant-price'") && str_contains($admin,"'Update price'"),'Price editor not wired');
echo "Variant pricing: override inheritance, filters, truthful variant display, JSON-LD and server-side quote/placement integration checked offline.\n";
