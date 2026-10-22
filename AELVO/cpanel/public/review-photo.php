<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');header("Content-Security-Policy: default-src 'none'; sandbox");
$id=$_GET['id']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)){http_response_code(404);exit;}
$q=$pdo->prepare('SELECT p.*,r.status,r.customer_id,c.status AS product_status FROM aelvo_review_photos p JOIN aelvo_reviews r ON r.id=p.review_id JOIN aelvo_products c ON c.id=r.product_id WHERE p.id=?');$q->execute([$id]);$photo=$q->fetch();
if(!$photo){http_response_code(404);exit;}
if($photo['status']!=='Approved'||$photo['product_status']!=='Active'){
 Aelvo\Security::start((bool)$config['secure_cookies']);$customer=Aelvo\Customers::current($pdo);$actor=Aelvo\Security::actor($pdo);
 if(!($customer&&(int)$customer['id']===(int)$photo['customer_id'])&&!($actor&&Aelvo\Security::can($pdo,$actor,'reviews','view'))){http_response_code(404);exit;}
}
$file=dirname(__DIR__).'/storage/review-photos/'.basename($photo['disk_name']);if(!is_file($file)){http_response_code(404);exit;}
header('Content-Type: '.$photo['mime']);header('Content-Length: '.filesize($file));if($_SERVER['REQUEST_METHOD']!=='HEAD')readfile($file);
