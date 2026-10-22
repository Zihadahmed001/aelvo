<?php
declare(strict_types=1);
// Pure input/routing invariants only: no PDO connection, publication or 301 execution.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/PublicRoutes.php';
require dirname(__DIR__).'/app/CmsBlocks.php';
require dirname(__DIR__).'/app/CmsPages.php';
require dirname(__DIR__).'/app/Seo.php';
use Aelvo\CmsPages;
use Aelvo\PublicRoutes;
use Aelvo\Seo;
function cmsCheck(bool $condition,string $message):void {if(!$condition)throw new RuntimeException('CMS regression: '.$message);}
foreach (['shipping-help','size-guide','care-tips-2026'] as $slug){
    cmsCheck(CmsPages::path($slug)==='pages/'.$slug,'clean URL '.$slug);
    cmsCheck(PublicRoutes::entityPath('cms',$slug)===CmsPages::path($slug),'route consistency '.$slug);
}
foreach (['../admin','UPPER','a//b','bad--dash','space here','bangla-জুতা',str_repeat('x',181)] as $slug){
    try {CmsPages::path($slug);throw new RuntimeException('Accepted unsafe slug: '.$slug);}catch(InvalidArgumentException $e){}
}
$payload=['slug'=>'size-guide','heading'=>'Size guide','heading_bn'=>'সাইজ গাইড','body'=>'Choose your fit.','body_bn'=>'মাপ বাছুন।','enabled'=>'1','show_footer'=>'1','index_mode'=>'noindex','follow_mode'=>'follow'];
$method=(new ReflectionClass(CmsPages::class))->getMethod('payload');
$data=$method->invoke(null,$payload);
cmsCheck($data['enabled']===true && $data['show_footer']===true,'explicit release/footer booleans');
cmsCheck($data['index_mode']==='noindex','explicit noindex');
try {$method->invoke(null,[...$payload,'index_mode'=>'invalid']);throw new RuntimeException('Accepted invalid robots');}
catch (InvalidArgumentException $e) {}
cmsCheck(Seo::summary(str_repeat('জ',330))===str_repeat('জ',300),'UTF-8 snippet boundary');
echo "CMS slug/input/summary offline safety checks passed. No DB/publication/browser verification performed.\n";
