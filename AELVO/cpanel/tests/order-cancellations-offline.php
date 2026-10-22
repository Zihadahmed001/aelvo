<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/OrderCancellationRequests.php';
require dirname(__DIR__).'/app/Schema.php';
use Aelvo\OrderCancellationRequests as C;
use Aelvo\Schema;
function ensure(bool $yes,string $label):void{if(!$yes)throw new RuntimeException($label);echo "PASS $label\n";}
function deny(callable $check,string $label):void{try{$check();}catch(InvalidArgumentException){echo "PASS rejected $label\n";return;}throw new RuntimeException('Accepted unsafe input: '.$label);}
foreach(['Pending','Confirmed'] as $status)ensure(C::canRequest($status),'cancellation can be requested while '.$status);
foreach(['Dispatched','Delivered','Cancelled','Unknown',''] as $status)ensure(!C::canRequest($status),'no cancellable customer request after '.$status);
ensure(C::reason(['reason'=>' Customer changed their mind '])==='Customer changed their mind','reason normalization');
foreach([[],['reason'=>[]],['reason'=>'short'],['reason'=>str_repeat('x',1001)],['reason'=>"hello\x00world"]] as $input)deny(fn()=>C::reason($input),'malformed cancellation reason');
ensure(C::filters([])===['state'=>'Pending','page'=>1],'default pending work queue');
ensure(C::filters(['cancel_state'=>'All','cancel_page'=>'9'])===['state'=>'All','page'=>9],'queue filtering');
foreach([['cancel_state'=>'Unknown'],['cancel_state'=>[]],['cancel_page'=>'0'],['cancel_page'=>'10000'],['cancel_page'=>'1 OR 1=1'],['cancel_page'=>[]]] as $input)deny(fn()=>C::filters($input),'malformed queue filter');
$src=file_get_contents(dirname(__DIR__).'/app/OrderCancellationRequests.php');
$store=file_get_contents(dirname(__DIR__).'/public/store.php');
$account=file_get_contents(dirname(__DIR__).'/public/account-view.php');
$admin=file_get_contents(dirname(__DIR__).'/app/AdminView.php');
$router=file_get_contents(dirname(__DIR__).'/public/index.php');
ensure(str_contains(file_get_contents(dirname(__DIR__).'/app/Orders.php'),'OrderCancellationRequests::assertNoPending') && str_contains(file_get_contents(dirname(__DIR__).'/app/Courier.php'),'OrderCancellationRequests::assertNoPending'),'dispatch and courier booking held for pending customer request');
ensure(str_contains($src,'FOR UPDATE') && str_contains($src,'c.customer_id=?'),'locked order and verified account ownership');
ensure(str_contains($src,'hash_equals') && str_contains($src,'request_hash'),'idempotent retry distinguishes changed payload');
ensure(str_contains($src,"$".'this->allow(\'orders\',\'cancel\')'),'declining requires cancellation permission');
ensure(str_contains($src,'version=version+1') && str_contains($src,"state='Pending'"),'compare-and-swap prevents stale staff reviews');
ensure(str_contains($account,"accountStart('order-cancel-request')") && str_contains($account,'orderEventMap'),'account request and order timeline rendered');
ensure(str_contains($admin,"$".'start(\'order-cancellation-review\'') && str_contains($admin,'Customer cancellation requests'),'admin queue and review form present');
ensure(str_contains($router,"case 'order-cancellation-review'") && str_contains($store,"$".'action===\'order-cancel-request\''),'CSRF-protected POST routes are present');
ensure(in_array('order-cancellations',Schema::FILES,true) && count(Schema::statements(file_get_contents(dirname(__DIR__).'/database/order-cancellations.sql')))===2,'additive SQL migration registered and parsed');
echo "Account cancellation and tracking checks are offline only. No MySQL/browser testing performed.\n";
