<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Content-Type: application/xml; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
$seo=Aelvo\Seo::config($pdo);
$xml=static fn(string $s):string=>htmlspecialchars($s,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>';
if(empty($seo['base_url']) || ($seo['indexing']??false)!==true){
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>';exit;
}
$base=rtrim((string)$seo['base_url'],'/');
$static=['','store.php?page=shop'];
foreach(['category'=>'aelvo_categories','brand'=>'aelvo_brands'] as $kind=>$table){
    // Only current, self-canonical, indexable entities belong in the sitemap.
    $sql="SELECT e.slug FROM $table e JOIN aelvo_public_routes r ON r.path=CONCAT('$kind/',e.slug) AND r.route_kind='current' AND r.entity_type='$kind' AND r.entity_id=e.id AND r.enabled=1 WHERE e.status='Active' AND e.index_mode<>'noindex' AND (e.canonical_path='' OR e.canonical_path=CONCAT('$kind/',e.slug)) ORDER BY e.id";
    foreach($pdo->query($sql) as $entity)$static[]=Aelvo\PublicRoutes::entityPath($kind,$entity['slug']);
}
foreach(['about','delivery-returns','privacy','terms'] as $key){
    $record=Aelvo\Content::get($pdo,$key)['value'];
    if(Aelvo\Content::active($record) && ($record['index_mode']??'index')!=='noindex')$static[]='store.php?page=info&key='.$key;
}
foreach(Aelvo\CmsPages::publishedPages($pdo) as $cms){
    if(($cms['value']['index_mode']??'index')==='noindex')continue;
    $path=Aelvo\CmsPages::path($cms['slug']);
    $route=Aelvo\PublicRoutes::resolve($pdo,$path);
    if($route && $route['type']==='cms' && $route['id']===$cms['id'])$static[]=$path;
}
$perChunk=1000;
$staticChunks=max(1,(int)ceil(count($static)/$perChunk));
$countSql="SELECT COUNT(*) FROM aelvo_products p JOIN aelvo_public_routes r ON r.current_owner=CONCAT('product:',p.id) AND r.route_kind='current' AND r.path=CONCAT('product/',p.slug) AND r.enabled=1 LEFT JOIN aelvo_product_seo ps ON ps.product_id=p.id WHERE p.status='Active' AND (ps.product_id IS NULL OR ps.index_mode<>'noindex') AND (ps.product_id IS NULL OR ps.canonical_path='' OR ps.canonical_path=CONCAT('product/',p.slug))";
$productCount=(int)$pdo->query($countSql)->fetchColumn();
$productChunks=(int)ceil($productCount/$perChunk);
$type=$_GET['type']??null;
if($type===null && isset($_GET['part']))$type='product'; // Older sitemap pagination URLs.
if($type===null){
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach(['static'=>$staticChunks,'product'=>$productChunks] as $section=>$chunks)
        for($part=1;$part<=$chunks;$part++)echo '<sitemap><loc>'.$xml($base.'/sitemap.xml?type='.$section.'&amp;part='.$part).'</loc></sitemap>';
    echo '</sitemapindex>';exit;
}
$part=filter_var($_GET['part']??null,FILTER_VALIDATE_INT);
if(!in_array($type,['static','product'],true) || !$part || $part<1 || $part>($type==='static'?$staticChunks:$productChunks)){
    http_response_code(404);header('X-Robots-Tag: noindex, nofollow');
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>';exit;
}
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
$emit=static function(string $path)use($xml,$base):void{echo '<url><loc>'.$xml($base.'/'.ltrim($path,'/')).'</loc></url>';};
if($type==='static'){
    foreach(array_slice($static,($part-1)*$perChunk,$perChunk) as $path)$emit($path);
}else{
    $sql="SELECT p.slug FROM aelvo_products p JOIN aelvo_public_routes r ON r.current_owner=CONCAT('product:',p.id) AND r.route_kind='current' AND r.path=CONCAT('product/',p.slug) AND r.enabled=1 LEFT JOIN aelvo_product_seo ps ON ps.product_id=p.id WHERE p.status='Active' AND (ps.product_id IS NULL OR ps.index_mode<>'noindex') AND (ps.product_id IS NULL OR ps.canonical_path='' OR ps.canonical_path=CONCAT('product/',p.slug)) ORDER BY p.id LIMIT $perChunk OFFSET ".(($part-1)*$perChunk);
    foreach($pdo->query($sql) as $product)$emit(Aelvo\PublicRoutes::productPath($product['slug']));
}
echo '</urlset>';
