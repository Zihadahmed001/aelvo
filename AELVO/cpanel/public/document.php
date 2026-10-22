<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
Aelvo\Security::start((bool)$config['secure_cookies']);$actor=Aelvo\Security::actor($pdo);
$id=$_GET['id']??'';
if(!$actor || !is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)){http_response_code(404);exit;}
$allowed=false;
if(Aelvo\Security::can($pdo,$actor,'finance','view')){$q=$pdo->prepare('SELECT id FROM aelvo_finance_events WHERE document_id=? LIMIT 1');$q->execute([$id]);$allowed=(bool)$q->fetch();}
if(!$allowed && Aelvo\Security::can($pdo,$actor,'purchases','view')){$q=$pdo->prepare('SELECT id FROM aelvo_purchases WHERE receipt_id=? LIMIT 1');$q->execute([$id]);$allowed=(bool)$q->fetch();}
if(!$allowed && Aelvo\Security::can($pdo,$actor,'purchases','view')){
 $q=$pdo->prepare('SELECT id FROM aelvo_supplier_payments WHERE document_id=? LIMIT 1');$q->execute([$id]);$allowed=(bool)$q->fetchColumn();
}
if(!$allowed && Aelvo\Security::can($pdo,$actor,'courier','view') && Aelvo\Security::can($pdo,$actor,'finance','view')){
 $q=$pdo->prepare('SELECT shipment_id FROM aelvo_courier_collections WHERE document_id=? LIMIT 1');$q->execute([$id]);$allowed=(bool)$q->fetchColumn();
}
if(!$allowed && Aelvo\Security::can($pdo,$actor,'courier','view') && Aelvo\Security::can($pdo,$actor,'finance','view')){
 $q=$pdo->prepare('SELECT id FROM aelvo_courier_remittances WHERE document_id=? LIMIT 1');$q->execute([$id]);$allowed=(bool)$q->fetchColumn();
}
if(!$allowed && Aelvo\Security::can($pdo,$actor,'courier','view')){
 $q=$pdo->prepare('SELECT id FROM aelvo_courier_resolution_cases WHERE evidence_id=? LIMIT 1');$q->execute([$id]);$allowed=(bool)$q->fetchColumn();
}
if(!$allowed && Aelvo\Security::can($pdo,$actor,'courier','view')){
 $q=$pdo->prepare('SELECT id FROM aelvo_courier_exception_cases WHERE evidence_id=? LIMIT 1');$q->execute([$id]);$allowed=(bool)$q->fetchColumn();
}
if(!$allowed && Aelvo\Security::can($pdo,$actor,'courier','view')){
 $q=$pdo->prepare('SELECT id FROM aelvo_courier_exception_clearances WHERE evidence_id=? LIMIT 1');$q->execute([$id]);$allowed=(bool)$q->fetchColumn();
}
if(!$allowed && Aelvo\Security::can($pdo,$actor,'payments','view') && Aelvo\Security::can($pdo,$actor,'finance','view')){
 $q=$pdo->prepare('SELECT id FROM aelvo_gateway_settlements WHERE document_id=? LIMIT 1');$q->execute([$id]);$allowed=(bool)$q->fetchColumn();
}
if(!$allowed && Aelvo\Security::can($pdo,$actor,'payments','view') && Aelvo\Security::can($pdo,$actor,'finance','view')){
 $q=$pdo->prepare('SELECT id FROM aelvo_gateway_payout_batches WHERE document_id=? LIMIT 1');$q->execute([$id]);$allowed=(bool)$q->fetchColumn();
}
if(!$allowed && Aelvo\Security::can($pdo,$actor,'payments','view') && Aelvo\Security::can($pdo,$actor,'finance','view')){
 $q=$pdo->prepare('SELECT id FROM aelvo_gateway_exception_cases WHERE evidence_id=? LIMIT 1');$q->execute([$id]);$allowed=(bool)$q->fetchColumn();
}
if(!$allowed){http_response_code(404);exit;}
$q=$pdo->prepare('SELECT * FROM aelvo_media WHERE id=? AND is_public=0');$q->execute([$id]);$m=$q->fetch();
if(!$m){http_response_code(404);exit;}
$file=dirname(__DIR__).'/storage/media/'.basename($m['disk_name']);if(!is_file($file)){http_response_code(404);exit;}
header('Cache-Control: private, no-store');header("Content-Security-Policy: default-src 'none'; sandbox");header('X-Content-Type-Options: nosniff');header('Content-Type: '.$m['mime']);header('Content-Disposition: attachment; filename="'.basename($m['disk_name']).'"');header('Content-Length: '.filesize($file));readfile($file);
