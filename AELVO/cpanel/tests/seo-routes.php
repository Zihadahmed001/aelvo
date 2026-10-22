<?php
declare(strict_types=1);
// No database or HTTP request is used here. Live redirect/transaction/Apache
// behavior must be verified later against an isolated MySQL/XAMPP environment.
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/PublicRoutes.php';

use Aelvo\PublicRoutes;

function verifyRoute(bool $valid, string $case): void {
    if (!$valid) throw new RuntimeException('SEO route policy regression: '.$case);
}
foreach (['product/leather-wallet', '/product/leather-wallet/', 'classic-shoes', 'men/shoes'] as $input) {
    verifyRoute(PublicRoutes::path($input)===trim($input,'/'),'safe local path: '.$input);
}
foreach (['', '.', '../admin', 'product/../account', '//example.com', 'https://evil.example', 'product/bag?x=1', 'product/bag#top',
    'admin', 'account', 'api', 'assets/file', 'checkout', 'robots.txt', 'store.php', 'setup.php', 'sitemap.xml',
    'product//bag', 'product/<script>', 'Product/Bag', str_repeat('x',221)] as $input) {
    try { PublicRoutes::path($input); throw new RuntimeException('Accepted disallowed input: '.$input); }
    catch (InvalidArgumentException $e) { /* expected */ }
}
foreach (['leather-wallet', 'shoes-2026'] as $slug) verifyRoute(PublicRoutes::productPath($slug)==='product/'.$slug,'slug '.$slug);
foreach (['UPPER', '../bad', 'not_a_slug', 'bad--slug', 'bad/slug', str_repeat('b',181)] as $slug) {
    try { PublicRoutes::productPath($slug); throw new RuntimeException('Accepted disallowed slug: '.$slug); }
    catch (InvalidArgumentException $e) { /* expected */ }
}
foreach (['category'=>['shoes','formal-shoes'],'brand'=>['aelvo','fashion-2026']] as $kind=>$slugs)
    foreach ($slugs as $slug) verifyRoute(PublicRoutes::entityPath($kind,$slug)===$kind.'/'.$slug,'taxonomy path '.$kind.'/'.$slug);
foreach (['category','brand'] as $kind)foreach(['UPPER','../wrong','two--dashes','x_y',str_repeat('z',181)] as $slug){
    try { PublicRoutes::entityPath($kind,$slug);throw new RuntimeException('Accepted invalid taxonomy slug: '.$slug); }
    catch (InvalidArgumentException $e) { /* expected */ }
}
foreach (['payment','admin','unknown'] as $kind){
    try { PublicRoutes::entityPath($kind,'shoes');throw new RuntimeException('Accepted invalid entity type: '.$kind); }
    catch (InvalidArgumentException $e) { /* expected */ }
}
$old=$_SERVER['SCRIPT_NAME']??null;
$_SERVER['SCRIPT_NAME']='/aelvo/public/store.php';
verifyRoute(PublicRoutes::localUrl('product/leather-wallet')==='/aelvo/public/product/leather-wallet','install prefix');
verifyRoute(PublicRoutes::localUrl()==='/aelvo/public/','base href');
if ($old===null) unset($_SERVER['SCRIPT_NAME']); else $_SERVER['SCRIPT_NAME']=$old;
echo "SEO local path/slug safety checks passed. DB/Apache 301 verification remains pending.\n";
