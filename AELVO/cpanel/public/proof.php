<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
Aelvo\Security::start((bool)$config['secure_cookies']);$actor=Aelvo\Security::actor($pdo);
if(!$actor||!Aelvo\Security::can($pdo,$actor,'payments','view')){http_response_code(404);exit;}
$id=$_GET['id']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)){http_response_code(404);exit;}
$q=$pdo->prepare('SELECT disk_name,mime FROM aelvo_payment_proofs WHERE id=?');$q->execute([$id]);$proof=$q->fetch();
if(!$proof){http_response_code(404);exit;}$path=dirname(__DIR__).'/storage/proofs/'.basename($proof['disk_name']);if(!is_file($path)){http_response_code(404);exit;}
header('Cache-Control: private, no-store');header("Content-Security-Policy: default-src 'none'; sandbox");header('X-Content-Type-Options: nosniff');header('Content-Type: '.$proof['mime']);header('Content-Disposition: attachment; filename="payment-evidence.'.pathinfo($proof['disk_name'],PATHINFO_EXTENSION).'"');header('Content-Length: '.filesize($path));readfile($path);
