<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/Orders.php';
require dirname(__DIR__).'/app/Purchases.php';
use Aelvo\Orders;
use Aelvo\Purchases;
function expect(bool $test, string $message): void {
    if(!$test)throw new RuntimeException('Staff line parser: '.$message);
}
function rejects(callable $test, string $message): void {
    try{$test();}catch(InvalidArgumentException $e){return;}
    throw new RuntimeException('Staff line parser did not reject: '.$message);
}
$order=Orders::parseOrderLines("42,2\r\n18:5\n");
expect(count($order)===2 && $order[0]['variant_id']==='42' && $order[1]['qty']==='5','ordinary order lines');
rejects(fn()=>Orders::parseOrderLines('42,0'),'zero quantity');
rejects(fn()=>Orders::parseOrderLines('42,2; DROP TABLE x'),'SQL-like suffix');
rejects(fn()=>Orders::parseOrderLines(str_repeat("12,1\n",31)),'overlong order');
rejects(fn()=>Orders::parseOrderLines(''),'empty order');
$purchase=Purchases::parsePurchaseLines("42,5,1200.00\n7;1;0.00\n");
expect(count($purchase)===2&&$purchase[0]['unit_cost']==='1200.00','ordinary purchase lines');
rejects(fn()=>Purchases::parsePurchaseLines('42,1,-50'),'negative cost');
rejects(fn()=>Purchases::parsePurchaseLines('42,0,10'),'zero units');
rejects(fn()=>Purchases::parsePurchaseLines('42,1,12.345'),'excess precision');
rejects(fn()=>Purchases::parsePurchaseLines(str_repeat("42,1,10\n",31)),'overlong purchase');
echo "Offline staff order/purchase line parsing checks completed. Database operations remain unverified.\n";
