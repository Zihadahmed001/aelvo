<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
Aelvo\Security::start((bool)$config['secure_cookies']);
header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');header('Cache-Control: private, no-store');
$actor=Aelvo\Security::actor($pdo);
if(!$actor||!Aelvo\Security::can($pdo,$actor,'reports','view')){http_response_code(403);exit('Permission denied.');}
$from=$_GET['from']??'';$to=$_GET['to']??'';
try{$rows=(new Aelvo\Reports($pdo,$actor))->orderRows($from,$to);}catch(InvalidArgumentException $e){http_response_code(400);exit('Invalid report dates.');}
header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="aelvo-orders-'.gmdate('Ymd').'.csv"');
$out=fopen('php://output','wb');
fputcsv($out,['Reference','Order status','Payment status','Created UTC','Subtotal BDT','Delivery BDT','Configured tax BDT','Order total BDT'],',','"','');
foreach($rows as $r){
 $line=[];foreach(['reference','status','payment_status','created_at'] as $key)$line[]=Aelvo\Reports::csvCell($r[$key]);
 foreach(['subtotal_minor','delivery_minor','tax_minor','total_minor'] as $key)$line[]=number_format((int)$r[$key]/100,2,'.','');
 fputcsv($out,$line,',','"','');
}
fclose($out);
