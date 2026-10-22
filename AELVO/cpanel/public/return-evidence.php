<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
Aelvo\Security::start((bool)$config['secure_cookies']);
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');header("Content-Security-Policy: default-src 'none'; sandbox");
$id=$_GET['id']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)){http_response_code(404);exit;}
$customer=Aelvo\Customers::current($pdo);$actor=Aelvo\Security::actor($pdo);
$q=$pdo->prepare('SELECT p.*,r.customer_id FROM aelvo_return_request_photos p JOIN aelvo_customer_return_requests r ON r.id=p.request_id WHERE p.id=?');$q->execute([$id]);$photo=$q->fetch();
$allowed=$photo&&(($customer&&(int)$customer['id']===(int)$photo['customer_id'])||($actor&&Aelvo\Security::can($pdo,$actor,'returns','view')));
if(!$allowed){http_response_code(404);exit;}
$file=dirname(__DIR__).'/storage/return-evidence/'.basename($photo['disk_name']);if(!is_file($file)){http_response_code(404);exit;}
header('Content-Type: '.$photo['mime']);header('Content-Disposition: attachment; filename="'.basename($photo['disk_name']).'"');header('Content-Length: '.filesize($file));readfile($file);
