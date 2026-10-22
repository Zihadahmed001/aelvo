<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$flags=array_slice($argv,1);
foreach($flags as $flag){
    if(!in_array($flag,['--plan','--apply','--restore-tested'],true)){
        fwrite(STDERR,"Usage: php bin/migrate.php [--plan] [--apply] [--restore-tested]\n");exit(2);
    }
}
try{
    $package=Aelvo\UpgradeAudit::verifyPackage(dirname(__DIR__));
    $plan=Aelvo\Schema::plan($pdo); // safe read-only validation
    echo 'Migration mode: '.$plan['mode'].'; applied: '.count($plan['applied']).'; pending: '.count($plan['pending']).PHP_EOL;
    if(!in_array('--apply',$flags,true)){
        echo "Read-only preflight. No database objects were changed.\n";
        exit(0);
    }
    if($plan['mode']==='tracked' && $plan['pending'] && !in_array('--restore-tested',$flags,true)){
        fwrite(STDERR,"Upgrade refused: take a backup and actually test its restore on an isolated database first. --restore-tested only records YOUR attestation; it does not test a backup.\n");exit(2);
    }
    if(!$plan['pending']){echo "No unapplied bundled schema files.\n";exit(0);}
    Aelvo\Schema::migrate($pdo);
    echo "Bundled migrations applied. Database/browser/upgrade acceptance still required.\n";
}catch(Throwable $e){fwrite(STDERR,'Migration blocked: '.$e->getMessage().PHP_EOL);exit(1);}
