<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/PublicRoutes.php';
require dirname(__DIR__).'/app/StorefrontNavigation.php';
require dirname(__DIR__).'/app/Catalogue.php';

function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function esc(mixed $s):string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function tr(string $en,string $bn):string {global $lang;return $lang==='bn'?$bn:$en;}

$categories = [
    ['id'=>2,'parent_id'=>null,'status'=>'Active','slug'=>'bags','name'=>'Bags','name_bn'=>'ব্যাগ','root_code'=>'Bags'],
    ['id'=>3,'parent_id'=>1,'status'=>'Active','slug'=>'sneakers','name'=>'Sneakers','name_bn'=>'স্নিকার্স','root_code'=>'Shoes'],
    ['id'=>1,'parent_id'=>null,'status'=>'Active','slug'=>'shoes','name'=>'Shoes','name_bn'=>'জুতা','root_code'=>'Shoes'],
    ['id'=>4,'parent_id'=>1,'status'=>'Hidden','slug'=>'private','name'=>'Private','name_bn'=>'গোপন','root_code'=>'Shoes'],
    ['id'=>5,'parent_id'=>999,'status'=>'Active','slug'=>'orphan','name'=>'Orphan','name_bn'=>'','root_code'=>'Shoes'],
    ['id'=>6,'parent_id'=>2,'status'=>'Active','slug'=>'wrong-family','name'=>'Wrong family','name_bn'=>'','root_code'=>'Shoes'],
    ['id'=>7,'parent_id'=>null,'status'=>'Active','slug'=>'javascript:evil','name'=>'Bad slug','name_bn'=>'','root_code'=>'Wallets'],
];
$brands = [
    ['id'=>10,'status'=>'Active','slug'=>'aelvo-studio','name'=>'AELVO Studio','name_bn'=>'এলভো স্টুডিও'],
    ['id'=>11,'status'=>'Hidden','slug'=>'hidden-brand','name'=>'Private brand','name_bn'=>''],
    ['id'=>12,'status'=>'Active','slug'=>'xss-brand','name'=>'<script>alert(1)</script>','name_bn'=>''],
];
$nav=Aelvo\StorefrontNavigation::build($categories,$brands);
check(count($nav['categories'])===2,'Unexpected count of public categories');
check($nav['categories'][0]['item']['path']==='category/shoes','Approved order not preserved');
check($nav['categories'][0]['children'][0]['path']==='category/sneakers','Valid child missing');
check(count($nav['categories'][0]['children'])===1,'Hidden/orphan children leaked');
check(count($nav['brands'])===2,'Hidden brand leaked');

$lang='en';$page='shop';$pdo=true;$navigation=$nav;$taxonomyPage=null;$languageHref='store.php?page=shop&lang=bn';
$_SESSION=['bag'=>[12=>2]];$_SERVER['SCRIPT_NAME']='/shop/store.php';
ob_start();require dirname(__DIR__).'/public/storefront-header-view.php';$html=ob_get_clean();
ob_start();require dirname(__DIR__).'/public/storefront-collections-view.php';$html.=ob_get_clean();
foreach(['category/shoes','category/sneakers','category/bags','brand/aelvo-studio','Explore collections & brands','2)'] as $part)check(str_contains($html,$part),'Missing header navigation: '.$part);
foreach(['private','orphan','wrong-family','hidden-brand','<script>'] as $bad)check(!str_contains($html,$bad),'Unpublished/unsafe navigation leaked: '.$bad);
check(str_contains($html,'&lt;script&gt;alert(1)&lt;/script&gt;'),'Brand text was not escaped');
$lang='bn';ob_start();require dirname(__DIR__).'/public/storefront-collections-view.php';$bnHtml=ob_get_clean();
check(str_contains($bnHtml,'জুতা')&&str_contains($bnHtml,'ব্যাগ'),'Bengali collection labels absent');
check(str_contains($bnHtml,'ব্র্যান্ড অনুযায়ী শপ'),'Bengali brand menu absent');

$filters=['category'=>'Shoes','category_id'=>'3','brand_id'=>'10','sort'=>'price-low'];$productCount=3;
ob_start();require dirname(__DIR__).'/public/filters-view.php';$filterHtml=ob_get_clean();
foreach (['name="category_id"','name="brand_id"','value="3" selected','value="10" selected','value="Shoes" selected'] as $part) {
    check(str_contains($filterHtml,$part),'Published catalogue filter missing: '.$part);
}
check(!str_contains($filterHtml,'Private brand')&&!str_contains($filterHtml,'Orphan'),'Unpublished filter leaked');
[$where,$args]=Aelvo\Catalogue::queryParts($filters);
check(count($args)===6 && substr_count($where,'?')===6,'Published filter placeholder count mismatch');
check(str_contains($where,'parent.status=?'),'Hidden parent category guard absent');
$lang='bn';ob_start();require dirname(__DIR__).'/public/filters-view.php';$bnFilter=ob_get_clean();
check(str_contains($bnFilter,'সাবক্যাটাগরি')&&str_contains($bnFilter,'ব্র্যান্ড'),'Bengali filter labels absent');

$source=file_get_contents(dirname(__DIR__).'/public/store.php');
$catalogue=file_get_contents(dirname(__DIR__).'/app/Catalogue.php');
check(str_contains($source,'Catalogue::relatedProducts('),'Related product route not wired');
check(str_contains($catalogue,"v.stock-v.reserved-COALESCE(qu.quantity,0)>0"),'Unavailable inventory included in related recommendations');
check(str_contains($source,'<main id="store-main"'),'Accessible skip target missing');
echo "Storefront navigation: public taxonomy, orphan/hidden isolation, escaped bilingual markup, stock-safe related wiring passed (offline).\n";
