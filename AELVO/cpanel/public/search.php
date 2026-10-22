<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');
try{
 $query=Aelvo\Input::text($_GET,'q',120,false);
 if(strlen($query)<2){echo '{"products":[]}';exit;}
 $rows=array_slice(Aelvo\Catalogue::publicProducts($pdo,['q'=>$query]),0,8);$result=[];
 foreach($rows as $r)$result[]=['id'=>(int)$r['id'],'url'=>Aelvo\PublicRoutes::localUrl(Aelvo\PublicRoutes::productPath($r['slug'])),'name'=>$r['name'],'name_bn'=>$r['name_bn'],'price_minor'=>(int)($r['variant_price_range']['min']??$r['price_minor'])];
 echo json_encode(['products'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(InvalidArgumentException $e){http_response_code(400);echo '{"error":"Invalid search"}';}
