<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
Aelvo\Security::start((bool)$config['secure_cookies']);
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; sandbox");
$id=$_GET['id']??'';
if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)){http_response_code(404);exit;}
$actor=Aelvo\Security::actor($pdo);
// Guest and account holders cannot retrieve private bug-report evidence via guesses.
if(!$actor||!Aelvo\Security::can($pdo,$actor,'support','view')){http_response_code(404);exit;}
$q=$pdo->prepare('SELECT disk_name,mime,bytes FROM aelvo_support_attachments WHERE id=?');$q->execute([$id]);$file=$q->fetch();
if(!$file){http_response_code(404);exit;}
$path=dirname(__DIR__).'/storage/support-evidence/'.basename($file['disk_name']);
if(!is_file($path)){http_response_code(404);exit;}
header('Content-Type: '.$file['mime']);header('Content-Disposition: attachment; filename="support-image-'.$id.'"');header('Content-Length: '.filesize($path));
if($_SERVER['REQUEST_METHOD']!=='HEAD')readfile($path);
