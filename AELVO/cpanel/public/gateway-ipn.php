<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit('POST required');}
if((int)($_SERVER['CONTENT_LENGTH']??0)>16384){http_response_code(413);exit('Invalid payload');}
$ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
if(!Aelvo\Security::throttle($pdo,'gateway-ipn:'.$ip,60)){http_response_code(429);exit('Rate limited');}
try {
    foreach($_POST as $value)if(!is_string($value))throw new InvalidArgumentException('Malformed notification.');
    echo Aelvo\GatewayPayments::notify($pdo,$_POST,$config['encryption_key']??'');
} catch(InvalidArgumentException $e){http_response_code(400);echo 'Invalid notification';}
catch(Throwable $e){error_log('AELVO gateway IPN needs reconciliation: '.get_class($e));http_response_code(503);echo 'Manual reconciliation required';}
