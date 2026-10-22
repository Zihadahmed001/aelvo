<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Schema.php';
use Aelvo\Schema;
$files=Schema::manifest();
if(count($files)!==count(Schema::FILES))throw new RuntimeException('Manifest does not cover bundled SQL files.');
$check=function(bool $condition,string $reason):void{if(!$condition)throw new RuntimeException($reason);};
$reject=function(callable $callback,string $needle):void{
    try{$callback();}catch(RuntimeException $e){
        if(str_contains($e->getMessage(),$needle))return;
        throw new RuntimeException('Incorrect refusal reason: '.$e->getMessage());
    }
    throw new RuntimeException('Unsafe migration state was accepted.');
};
$fresh=Schema::planFromHistory($files,[],[]);
$check($fresh['mode']==='fresh' && count($fresh['pending'])===count($files),'Fresh install manifest incorrect.');
$reject(fn()=>Schema::planFromHistory($files,['aelvo_products'],[]),'Untracked existing');
$reject(fn()=>Schema::planFromHistory($files,['aelvo_schema_migrations','aelvo_products'],[]),'Migration ledger is empty');
$rows=[];
foreach($files as $version=>$file){
    $rows[]=['version'=>$version,'filename'=>$file['name'],'sha256'=>$file['sha256'],'state'=>'applied'];
}
$done=Schema::planFromHistory($files,['aelvo_schema_migrations','aelvo_products'],$rows);
$check(count($done['applied'])===count($files) && !$done['pending'],'Fully tracked plan incorrect.');
$partial=Schema::planFromHistory($files,['aelvo_schema_migrations','aelvo_users'],array_slice($rows,0,3));
$check(count($partial['applied'])===3 && count($partial['pending'])===count($files)-3,'Incremental plan incorrect.');
$tampered=$rows;$tampered[0]['sha256']=str_repeat('0',64);
$reject(fn()=>Schema::planFromHistory($files,['aelvo_schema_migrations'],$tampered),'checksum differs');
$swapped=$rows;$swapped[2]['filename']='foreign-script';
$reject(fn()=>Schema::planFromHistory($files,['aelvo_schema_migrations'],$swapped),'checksum differs');
$gap=$rows;$gap[1]['version']=9;
$reject(fn()=>Schema::planFromHistory($files,['aelvo_schema_migrations'],$gap),'checksum differs');
$broken=$rows;$broken[2]['state']='failed';
$reject(fn()=>Schema::planFromHistory($files,['aelvo_schema_migrations'],$broken),'interrupted or failed');
$interrupted=$rows;$interrupted[0]['state']='applying';
$reject(fn()=>Schema::planFromHistory($files,['aelvo_schema_migrations'],$interrupted),'interrupted or failed');
foreach($files as $file){
    $check(count($file['statements'])>0,'Empty SQL schema');
}
echo 'Migration manifest, ordering, checksum, legacy protection and interruption offline tests passed (no MySQL used).'.PHP_EOL;
