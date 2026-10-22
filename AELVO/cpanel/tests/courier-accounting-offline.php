<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/CourierAccounting.php';
require dirname(__DIR__).'/app/Schema.php';
use Aelvo\CourierAccounting;
use Aelvo\Schema;
function check(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
function denied(callable $cb):void{try{$cb();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Expected invalid overpayment');}
check(CourierAccounting::outstanding(200000,70000,5000)===125000,'Partial remittance retains receivable');
check(CourierAccounting::outstanding(100000,90000,10000)===0,'Final settlement conserves collection');
denied(fn()=>CourierAccounting::outstanding(1000,900,101));
denied(fn()=>CourierAccounting::outstanding(1000,-1,0));
check(count(Schema::statements(file_get_contents(dirname(__DIR__).'/database/courier.sql')))===4,'Four additive courier tables');
echo "Courier settlement arithmetic and schema command boundaries passed (offline; no MySQL or actual payout).\n";
