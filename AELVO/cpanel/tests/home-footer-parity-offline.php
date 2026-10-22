<?php
declare(strict_types=1);
// Standalone view-only tests. No database is connected and no live product is created.
namespace Aelvo {
    class PublicRoutes {
        public static function localUrl(string $path=''): string {return '/shop/'.ltrim($path,'/');}
    }
    class Content {
        public static array $enabled=['about'=>true,'delivery-returns'=>false,'privacy'=>true,'terms'=>false];
        public static function get($db,string $key): array {return ['value'=>['enabled'=>self::$enabled[$key]??false]];}
        public static function active(array $v): bool {return ($v['enabled']??false) === true;}
    }
    class CmsPages {
        public static function navigation($db,int $max): array {
            return [['slug'=>'size-guide','value'=>['heading'=>'Size <guide>','heading_bn'=>'সাইজ গাইড']]];
        }
        public static function path(string $slug): string {return 'pages/'.$slug;}
    }
}
namespace {
    function esc(mixed $s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    function tr(string $en,string $bn): string {global $lang;return $lang==='bn'?$bn:$en;}
    function card(array $row):void {echo '<article class="fixture-product">'.esc($row['name']).'</article>';}
    function check(bool $condition,string $why):void{if(!$condition)throw new \RuntimeException($why);}
    $pub=dirname(__DIR__).'/public';
    $pdo=new \stdClass();$lang='en';
    $navigation=['categories'=>[
        ['root_code'=>'Shoes','item'=>['path'=>'category/shoes','name'=>'Shoes','name_bn'=>'জুতা']],
        ['root_code'=>'Bags','item'=>['path'=>'category/bags','name'=>'Bags <limited>','name_bn'=>'ব্যাগ']],
    ],'brands'=>[]];
    $products=[['name'=>'Verified store item']];
    ob_start();require $pub.'/storefront-home-view.php';$home=ob_get_clean();
    check(str_contains($home,'assets/bag.png'),'Approved editorial asset missing');
    check(str_contains($home,'/shop/category/bags'),'Published bag collection link missing');
    check(str_contains($home,'Bags &lt;limited&gt;'),'Category label not safely escaped');
    check(!str_contains($home,'Bags <limited>'),'Raw HTML leaked in category label');
    check(str_contains($home,'Verified store item'),'Published product grid is not connected');
    check(str_contains($home,'store.php?page=info&amp;key=about'),'Published About not linked');
    ob_start();require $pub.'/storefront-footer-view.php';$footer=ob_get_clean();
    check(str_contains($footer,'store.php?page=info&amp;key=privacy'),'Published privacy missing');
    check(!str_contains($footer,'key=terms')&&!str_contains($footer,'key=delivery-returns'),'Unpublished policy disclosed');
    check(str_contains($footer,'Size &lt;guide&gt;'),'CMS footer label was not escaped');
    check(!str_contains($footer,'Size <guide>'),'Raw CMS heading leaked');
    check(str_contains($footer,'/shop/pages/size-guide'),'Published CMS route missing');
    \Aelvo\Content::$enabled['about']=false;
    ob_start();require $pub.'/storefront-home-view.php';$noAbout=ob_get_clean();
    check(!str_contains($noAbout,'store.php?page=info&amp;key=about'),'Unpublished About linked from homepage');
    $lang='bn';
    ob_start();require $pub.'/storefront-home-view.php';$bnHome=ob_get_clean();
    check(str_contains($bnHome,'ব্যাগ')&&str_contains($bnHome,'আমাদের বাছাই'),'Bengali homepage missing');
    ob_start();require $pub.'/storefront-footer-view.php';$bnFooter=ob_get_clean();
    check(str_contains($bnFooter,'গ্রাহক সেবা')&&str_contains($bnFooter,'সাইজ গাইড'),'Bengali footer missing');
    // Source wiring checks: PHP templates are only reachable through the actual store route.
    $store=file_get_contents($pub.'/store.php');
    check(str_contains($store,"require __DIR__.'/storefront-home-view.php'"),'Homepage route not wired');
    check(str_contains($store,"require __DIR__.'/storefront-footer-view.php'"),'Footer route not wired');
    check(!str_contains($store,'<footer class="store-footer">'),'Old parallel footer still in store route');
    check(is_file($pub.'/assets/bag.png') && filesize($pub.'/assets/bag.png')>100000,'Editorial design asset not copied');
    echo "Homepage & footer: published-only CMS/policy links, live taxonomy, escaping, bilingual output, approved asset and store wiring passed (offline).\n";
}
