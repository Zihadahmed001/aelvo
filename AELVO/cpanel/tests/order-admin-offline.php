<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/Orders.php';
require dirname(__DIR__).'/app/OrderAdmin.php';
use Aelvo\Orders;
use Aelvo\OrderAdmin;
function check(bool $valid,string $description): void {if(!$valid)throw new RuntimeException($description);}
function reject(callable $action,string $description): void {
    try{$action();}catch(InvalidArgumentException $e){return;}
    throw new RuntimeException('Expected validation to reject '.$description);
}
$f=OrderAdmin::filters(['q'=>' Customer 12 ','status'=>'Pending','p'=>'3']);
check($f===['status'=>'Pending','q'=>'Customer 12','p'=>3],'Filter normalization is wrong.');
check(OrderAdmin::filters([])===['status'=>'','q'=>'','p'=>1],'Empty filters changed.');
check(OrderAdmin::escapeLike('20%!_')==='20!%!!!_','LIKE special characters were not fully escaped.');
foreach(['%','_','!'] as $token)check(!str_contains(OrderAdmin::escapeLike($token),"'"),'Unsafe SQL wildcard conversion.');
reject(fn()=>OrderAdmin::filters(['status'=>'Paid']),'unsupported order status');
reject(fn()=>OrderAdmin::filters(['p'=>'0']),'page 0');
reject(fn()=>OrderAdmin::filters(['p'=>'10001']),'oversized page');
reject(fn()=>OrderAdmin::filters(['p'=>'1 OR 1=1']),'SQL-like page');
reject(fn()=>OrderAdmin::filters(['q'=>str_repeat('X',101)]),'oversized term');
reject(fn()=>OrderAdmin::filters(['q'=>"hello\r\n"]),'control characters');
reject(fn()=>OrderAdmin::filters(['q'=>['array']]),'array term');
check(Orders::nextStatuses('Pending')===['Confirmed','Cancelled'],'Pending state changed');
check(Orders::nextStatuses('Confirmed')===['Dispatched','Cancelled'],'Confirmed state changed');
check(Orders::nextStatuses('Dispatched')===['Delivered'],'Dispatched state changed');
check(Orders::nextStatuses('Delivered')===[],'Delivered must be terminal');
check(Orders::nextStatuses('Cancelled')===[],'Cancelled must be terminal');
check(Orders::nextStatuses('Fake')===[],'Unknown state accepted');
echo "Order filters, wildcard escaping and status transitions passed (offline, not MySQL).\n";
