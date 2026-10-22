<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/SavedBag.php';
use Aelvo\SavedBag;
function assertAccept(int $amount,int $available): void {
    SavedBag::validateQuantity($amount,$available);
}
function assertReject(int $amount,int $available): void {
    try{SavedBag::validateQuantity($amount,$available);}catch(InvalidArgumentException $e){return;}
    throw new RuntimeException('Invalid bag quantity was accepted: '.$amount.'/'.$available);
}
assertAccept(1,1);
assertAccept(4,5);
assertAccept(100,1000);
assertReject(0,20);
assertReject(-1,20);
assertReject(101,1000);
assertReject(4,3);
assertReject(1,0);
assertReject(1,-1);
echo "Bag quantity guard: 9 cases passed (offline; no MySQL integration).\n";
