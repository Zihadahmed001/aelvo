<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$failed=false;$count=0;
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file){
 if($file->getExtension()!=='php'||str_contains($file->getPathname(),DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR))continue;
 $process=proc_open([PHP_BINARY,'-l',$file->getPathname()],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process)){fwrite(STDERR,"Unable to launch PHP lint.\n");exit(1);}
 $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 if(proc_close($process)!==0){echo $out.$err;$failed=true;}$count++;
}
echo 'Lint checked '.$count.' PHP files'.($failed?' with failures.':'.')."\n";
if($failed)exit(1);
foreach(['policy.php','support-reports.php','sms-adapter.php','seo-routes.php','staff-lines.php','sql-parser.php','migration-ledger-offline.php','database.php','checkout.php','customer-content.php','cms-pages.php','cms-blocks.php','courier-offline.php','courier-accounting-offline.php','gateway-offline.php'] as $test){
 $process=proc_open([PHP_BINARY,$root.'/tests/'.$test],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,$root);
 if(!is_resource($process)||proc_close($process)!==0)exit(1);
}
echo "PHP checks completed. Browser, concurrent checkout and provider acceptance tests are separate release gates.\n";
