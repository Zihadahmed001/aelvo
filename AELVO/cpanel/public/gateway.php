<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
Aelvo\Security::start((bool)$config['secure_cookies']);
header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit('Payment requests must start from your checkout page.');}
Aelvo\Security::csrf();
try {
    if(!isset($_SESSION['checkout_identity'])||!is_string($_SESSION['checkout_identity']))throw new InvalidArgumentException('Your checkout session has expired.');
    if(!Aelvo\Security::throttle($pdo,'gateway-start:'.session_id(),10))throw new InvalidArgumentException('Too many payment attempts. Contact support for reconciliation.');
    $url=Aelvo\GatewayPayments::initiate($pdo,$_SESSION['checkout_identity'],Aelvo\Input::text($_POST,'checkout_id',64),Aelvo\Input::text($_POST,'choice',10),Aelvo\Input::text($_POST,'customer_email',254),Aelvo\Input::text($_POST,'customer_city',80),$config['encryption_key']??'',$config);
    header('Location: '.$url,true,303);exit;
} catch(Throwable $e) {
    error_log('AELVO gateway start blocked: '.get_class($e));
    http_response_code(409);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="robots" content="noindex"><title>Payment pending review</title><main><h1>Payment could not start</h1><p>'.htmlspecialchars($e instanceof InvalidArgumentException?$e->getMessage():'Online payment is unavailable or awaiting reconciliation. No second charge has been requested.',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p><a href="store.php?page=account">Return to your account</a></main></html>';
}
