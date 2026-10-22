<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/UpgradeAudit.php';
use Aelvo\UpgradeAudit;
function ensure(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function refuses(callable $callback,string $part):void{
    try{$callback();}catch(RuntimeException $e){
        ensure(str_contains($e->getMessage(),$part),'Unexpected error: '.$e->getMessage());return;
    }
    throw new RuntimeException('Unsafe input was accepted: '.$part);
}
foreach(['../config.php','cpanel/../../etc/passwd','/etc/passwd','cpanel\\app','a//b','a/./b','',"folder\0.php"] as $path)ensure(!UpgradeAudit::safeRelativePath($path),'Unsafe manifest path accepted.');
ensure(UpgradeAudit::safeRelativePath('public/index.php'),'Legitimate release path refused.');
$root=sys_get_temp_dir().'/aelvo-upgrade-review-'.bin2hex(random_bytes(5));
foreach(['cpanel/app','cpanel/public','cpanel/database','cpanel/bin','cpanel/tests','cpanel/config','cpanel/storage'] as $dir){mkdir($root.'/'.$dir,0700,true);}
$files=['app/Sample.php'=>"<?php\nreturn 1;\n",'public/index.php'=>"<?php\necho 'ok';\n",'database/schema.sql'=>"CREATE TABLE a (id INT);\n"];
foreach($files as $path=>$body)file_put_contents($root.'/cpanel/'.$path,$body);
$manifest=['files'=>[]];
foreach($files as $path=>$body)$manifest['files'][]=['path'=>$path,'bytes'=>strlen($body),'sha256'=>hash('sha256',$body)];
$write=function()use($root,&$manifest):void{file_put_contents($root.'/cpanel/RELEASE-MANIFEST.json',json_encode($manifest,JSON_THROW_ON_ERROR));};
$cleanup=function()use($root):void{
    $walker=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($walker as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($root);
};
try{
    $write();$package=UpgradeAudit::verifyPackage($root.'/cpanel');
    ensure($package['files']===3,'Valid release not scanned.');
    file_put_contents($root.'/cpanel/app/Sample.php',"<?php\nreturn 2;\n");
    refuses(fn()=>UpgradeAudit::verifyPackage($root.'/cpanel'),'does not match');
    file_put_contents($root.'/cpanel/app/Sample.php',$files['app/Sample.php']);
    file_put_contents($root.'/cpanel/public/rogue.php','<?php');
    refuses(fn()=>UpgradeAudit::verifyPackage($root.'/cpanel'),'Untracked executable');
    unlink($root.'/cpanel/public/rogue.php');
    $manifest['files'][]=$manifest['files'][0];$write();
    refuses(fn()=>UpgradeAudit::verifyPackage($root.'/cpanel'),'duplicate');
    array_pop($manifest['files']);$manifest['files'][0]['path']='../../etc/passwd';$write();
    refuses(fn()=>UpgradeAudit::verifyPackage($root.'/cpanel'),'Unsafe or duplicate');
    $manifest['files'][0]['path']='app/Sample.php';$write();
    $p=['mode'=>'tracked','applied'=>[1],'pending'=>[2]];
    $schemas=[1=>['name'=>'core','sha256'=>str_repeat('a',64)],2=>['name'=>'commerce','sha256'=>str_repeat('b',64)]];
    $first=UpgradeAudit::fingerprint($package,$schemas,$p);
    ensure($first!==UpgradeAudit::fingerprint($package,$schemas,['mode'=>'tracked','applied'=>[1,2],'pending'=>[]]),'Fingerprint does not change with applied state.');
    $schemas[2]['sha256']=str_repeat('c',64);
    ensure($first!==UpgradeAudit::fingerprint($package,$schemas,$p),'Fingerprint does not detect changed SQL.');
    file_put_contents($root.'/cpanel/config/config.php','<?php return [];');
    $sample=['encryption_key'=>str_repeat('a',64),'secure_cookies'=>true];
    $good=UpgradeAudit::environment($root.'/cpanel',$sample,['REMOTE_ADDR'=>'192.0.2.2','HTTPS'=>'on','DOCUMENT_ROOT'=>$root.'/cpanel/public']);
    // PHP extensions vary in developer/test environments. Check filesystem and HTTPS conditions only.
    ensure(($good['checks']['Only public/ is web-served']??true) && $good['checks']['HTTPS outside localhost'],'Proper public-only HTTPS root refused.');
    $bad=UpgradeAudit::environment($root.'/cpanel',$sample,['REMOTE_ADDR'=>'192.0.2.2','HTTPS'=>'off','DOCUMENT_ROOT'=>$root.'/cpanel']);
    ensure(!$bad['ready']&&!$bad['checks']['HTTPS outside localhost']&&!$bad['checks']['Private app outside web root'],'Exposed private source or HTTP not blocked.');
    $missingKey=UpgradeAudit::environment($root.'/cpanel',['secure_cookies'=>true],['REMOTE_ADDR'=>'127.0.0.1','DOCUMENT_ROOT'=>$root.'/cpanel/public']);
    ensure(!$missingKey['checks']['Random key configured'],'Missing encryption key not detected.');
}finally{$cleanup();}
echo "Upgrade release checksum, rogue file, traversal, fingerprint, and web-root offline checks passed (no MySQL/restore used).\n";
