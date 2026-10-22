<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/ProductDetails.php';
require dirname(__DIR__).'/app/Schema.php';
use Aelvo\ProductDetails;
use Aelvo\Schema;
function yes(bool $condition,string $label):void {if(!$condition)throw new RuntimeException($label);}
function rejects(callable $call):void {
    try{$call();}catch(InvalidArgumentException){return;}
    throw new RuntimeException('Expected invalid product metadata rejection.');
}
$valid=ProductDetails::parse([
 'parent_sku'=>'PARENT-22','barcode'=>'8901234567890','short_en'=>'Actual wallet',
 'short_bn'=>'চামড়ার ওয়ালেট','material_en'=>'Genuine leather',
 'weight_grams'=>'450','length_mm'=>'200','width_mm'=>'100','height_mm'=>'35',
 'tags'=>'Wallet, Everyday','low_stock_alert'=>'8'
]);
yes($valid['parent_sku']==='PARENT-22','Parent SKU missing');
yes($valid['weight_grams']===450 && $valid['low_stock_alert']===8,'Numerical metadata not preserved');
yes($valid['short_bn']==='চামড়ার ওয়ালেট','Bengali text lost');
yes($valid['tags_csv']==='Wallet, Everyday','Tags not canonical');
$legacy=ProductDetails::parse([]);
yes($legacy['parent_sku']===null&&$legacy['barcode']===null&&$legacy['weight_grams']===null,'Optional existing fields not compatible');
yes($legacy['low_stock_alert']===5,'Missing alert must retain old default');
$attrs=ProductDetails::variantAttrs(['material'=>'Canvas','style'=>'Crossbody']);
yes($attrs===['Canvas','Crossbody'],'Variant attributes parsed incorrectly');
foreach([
 ['parent_sku'=>'<script>'],['barcode'=>'12 345'],['weight_grams'=>'-1'],
 ['length_mm'=>'1'],['length_mm'=>'1','width_mm'=>'1'],
 ['height_mm'=>'100','width_mm'=>'100'],['height_mm'=>'1','width_mm'=>'1','length_mm'=>'0'],
 ['tags'=>'Wallet, wallet'],['tags'=>'good, '],['tags'=>'<svg>'],['low_stock_alert'=>'-1'],
] as $invalid)rejects(fn()=>ProductDetails::parse($invalid));
rejects(fn()=>ProductDetails::variantAttrs(['style'=>str_repeat('a',101)]));
$manifest=Schema::manifest();
yes(in_array('product-details',array_column($manifest,'name'),true),'Product-details migration missing');
yes(in_array('product-merchandising',array_column($manifest,'name'),true),'Merchandising migration missing from the append-only registry');
$sql=file_get_contents(dirname(__DIR__).'/database/product-details.sql');
yes(str_contains($sql,'aelvo_product_details')&&str_contains($sql,'attributes_version'),'Product details migration incomplete');
$php=file_get_contents(dirname(__DIR__).'/app/ProductDetails.php');
yes(str_contains($php,'SELECT product_id FROM aelvo_product_details WHERE product_id=? FOR UPDATE') && str_contains($php,'UPDATE aelvo_product_details SET'), 'Unique parent SKU/barcode update must be scoped to its own product ID');
$catalogue=file_get_contents(dirname(__DIR__).'/app/Catalogue.php');
yes(str_contains($catalogue,'ProductDetails::persist(')&&str_contains($catalogue,'ProductDetails::variantAttrs('),'Catalogue not wired to metadata');
$index=file_get_contents(dirname(__DIR__).'/public/index.php');
yes(str_contains($index,"case 'variant-attributes':"),'Variant editor route missing');
$admin=file_get_contents(dirname(__DIR__).'/app/AdminView.php');
yes(str_contains($admin,'low_stock_alert') && str_contains($admin,"'variant-attributes'"),'Admin editor not wired');
$store=file_get_contents(dirname(__DIR__).'/public/store.php');
yes(str_contains($store,'product-facts-view.php'),'Product information not rendered');
$workspace=file_get_contents(dirname(__DIR__).'/app/AdminWorkspace.php');
yes(str_contains($workspace,'COALESCE(d.low_stock_alert,5)'),'Inventory thresholds not connected');
// Rendering must escape merchant-provided metadata rather than trusting it as HTML.
function tr(string $en,string $bn):string{return $en;}
function esc(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$lang='en';$p=['product_details'=>['short_en'=>'<script>test</script>','material_en'=>'<b>Material</b>','barcode'=>'A-11']];
ob_start();require dirname(__DIR__).'/public/product-facts-view.php';$html=ob_get_clean();
yes(!str_contains($html,'<script>')&&!str_contains($html,'<b>'),'Rendered product info contains unescaped input');
yes(str_contains($html,'&lt;script&gt;'),'Rendered product details missing escaped text');
echo "Product details: strict validation, legacy defaults, merchant facts rendering, unique-key protection, variant/admin/stock links checked offline.\n";
