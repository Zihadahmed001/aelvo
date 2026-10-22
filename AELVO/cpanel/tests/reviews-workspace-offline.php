<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/Reviews.php';
use Aelvo\Reviews;
$assert=static function(bool $valid,string $name):void {if(!$valid)throw new RuntimeException('FAIL: '.$name);echo "PASS: $name\n";};
$reject=static function(array $input) use($assert):void {
    try { Reviews::moderationFilters($input); } catch(InvalidArgumentException) { $assert(true,'invalid input rejected');return; }
    throw new RuntimeException('Unsafe review filter accepted');
};
$f=Reviews::moderationFilters([]);
$assert($f===['q'=>'','status'=>'Pending','rating'=>'','p'=>1],'pending queue is initial view');
$assert(Reviews::moderationFilters(['q'=>' Shoes ','status'=>'Approved','rating'=>'5','p'=>'3'])===['q'=>'Shoes','status'=>'Approved','rating'=>'5','p'=>3],'search, moderation status, rating and page normalized');
$assert(Reviews::likeLiteral('a%_!b')==='a!%!_!!b','LIKE wildcard and escape treated as literal');
foreach([['status'=>'Deleted'],['status'=>[]],['rating'=>'6'],['rating'=>1],['p'=>'0'],['p'=>'-1'],['p'=>'1 OR 1=1'],['p'=>'10001'],['q'=>str_repeat('a',101)],['q'=>"one\ntwo"]] as $bad)$reject($bad);
$assert(Reviews::PUBLIC_PAGE_SIZE===10 && Reviews::MODERATION_PAGE_SIZE===20,'bounded page sizes');
$source=file_get_contents(dirname(__DIR__).'/app/Reviews.php');
$admin=file_get_contents(dirname(__DIR__).'/app/AdminView.php');
$public=file_get_contents(dirname(__DIR__).'/public/reviews-view.php');
$assert(str_contains($source,"r.status='Approved'") && str_contains($source,'r.status=?'),'public approved filter and admin status filter separated');
$assert(str_contains($source,"\$this->allow('reviews','view')") && str_contains($source,"\$this->allow('reviews','edit')"),'moderation view and edit require separate server permission checks');
$assert(str_contains($source,'ORDER BY r.id DESC LIMIT ? OFFSET ?'),'server-side pagination with parameters');
$assert(str_contains($admin,"\$start('review-moderate'") && str_contains($admin,"\$e(\$r['body'])"),'moderation form retains CSRF helper and escapes customer text');
$assert(str_contains($public,'Review a delivered order from your account') && str_contains($public,'Review pages'),'review pagination and verified-order request visible');
echo "Review workspace offline regression checks completed. No MySQL or browser test performed.\n";
