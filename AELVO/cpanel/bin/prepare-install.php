<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);
if(is_file($root.'/config/config.php')){fwrite(STDERR,"Installation already configured.\n");exit(1);}
$target=$root.'/config/setup.key';$handle=fopen($target,'x');
if(!$handle){fwrite(STDERR,"Setup key already exists or config directory is not writable.\n");exit(1);}
$token=bin2hex(random_bytes(32));fwrite($handle,$token);fclose($handle);chmod($target,0600);
echo "One-time installation key:\n".$token."\nOpen public/setup.php. Keep this key private; it is also required for the initial Owner account.\n";
