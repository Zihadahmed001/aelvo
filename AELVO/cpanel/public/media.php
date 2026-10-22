<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$id=$_GET['id']??'';
if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)){http_response_code(404);exit;}
$q=$pdo->prepare("SELECT m.* FROM aelvo_media m WHERE m.id=? AND m.is_public=1 AND EXISTS (SELECT 1 FROM aelvo_product_media pm JOIN aelvo_products p ON p.id=pm.product_id WHERE pm.media_id=m.id AND p.status='Active')");$q->execute([$id]);$m=$q->fetch();$private=false;
if(!$m&&Aelvo\Content::publicMedia($pdo,$id)){$q=$pdo->prepare('SELECT m.* FROM aelvo_media m JOIN aelvo_content_media c ON c.media_id=m.id WHERE m.id=?');$q->execute([$id]);$m=$q->fetch();}
if(!$m && Aelvo\CmsPages::publishedMedia($pdo,$id)){
    $q=$pdo->prepare('SELECT m.* FROM aelvo_media m JOIN aelvo_content_media c ON c.media_id=m.id WHERE m.id=? AND m.mime IN (\'image/jpeg\',\'image/png\',\'image/webp\')');
    $q->execute([$id]);$m=$q->fetch();
}
if(!$m){
    Aelvo\Security::start((bool)$config['secure_cookies']);$actor=Aelvo\Security::actor($pdo);
    if(!$actor){http_response_code(404);exit;}
    $q=$pdo->prepare('SELECT m.*,c.media_id AS content_media FROM aelvo_media m LEFT JOIN aelvo_content_media c ON c.media_id=m.id WHERE m.id=?');$q->execute([$id]);$m=$q->fetch();
    if(!$m||!Aelvo\Security::can($pdo,$actor,$m['content_media']?'content':'products','view')||(!$m['content_media']&&!(int)$m['is_public'])){http_response_code(404);exit;}$private=true;
}
$file=dirname(__DIR__).'/storage/media/'.basename($m['disk_name']);if(!is_file($file)){http_response_code(404);exit;}
header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'");header('Content-Type: '.$m['mime']);header($private?'Cache-Control: private, no-store':'Cache-Control: public, max-age=300');
$length=filesize($file);$start=0;$end=$length-1;header('Accept-Ranges: bytes');
if(isset($_SERVER['HTTP_RANGE'])){
    $range=$_SERVER['HTTP_RANGE'];
    if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$parts)||($parts[1]===''&&$parts[2]==='')){http_response_code(416);header('Content-Range: bytes */'.$length);exit;}
    if($parts[1]===''){$suffix=(int)$parts[2];$start=max(0,$length-$suffix);}else{$start=(int)$parts[1];if($parts[2]!=='')$end=min($end,(int)$parts[2]);}
    if($start>$end||$start>=$length){http_response_code(416);header('Content-Range: bytes */'.$length);exit;}
    http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$length);
}
header('Content-Length: '.($end-$start+1));if($_SERVER['REQUEST_METHOD']==='HEAD')exit;
$stream=fopen($file,'rb');fseek($stream,$start);$remaining=$end-$start+1;
while($remaining>0&&!feof($stream)&&!connection_aborted()){$chunk=fread($stream,min(65536,$remaining));if($chunk===false||$chunk==='')break;echo $chunk;$remaining-=strlen($chunk);}fclose($stream);
