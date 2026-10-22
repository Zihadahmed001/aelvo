<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/OwnerAiPolicy.php';
use Aelvo\OwnerAiPolicy as Policy;
function expectBlocked(callable $f): void {try{$f();}catch(DomainException $e){return;}throw new RuntimeException('Expected access denial.');}
$owner=['id'=>7,'is_owner'=>true,'status'=>'Active','email_verified_at'=>'2026-10-21 18:00:00','email'=>'owner@example.com'];
Policy::owner($owner,7);
expectBlocked(fn()=>Policy::owner([...$owner,'id'=>8],7));
expectBlocked(fn()=>Policy::owner([...$owner,'email_verified_at'=>null],7));
foreach(['payment.create','refund.approve','balance.edit','transfer.create','gateway.configure','sql.execute','shell.execute','staff.permissions.update','inventory.adjust','code.release'] as $op)expectBlocked(fn()=>Policy::payload($op,['amount'=>100]));
expectBlocked(fn()=>Policy::payload('catalogue.text.update',['product_id'=>1,'balance'=>100]));
Policy::payload('catalogue.text.update',['product_id'=>1,'name'=>'AELVO']);
$a=Policy::digest('catalogue.text.update',['product_id'=>1,'name'=>'AELVO'],'v1');
$b=Policy::digest('catalogue.text.update',['name'=>'AELVO','product_id'=>1],'v1');
if($a!==$b)throw new RuntimeException('Canonical digest changed on field order.');
if($a===Policy::digest('catalogue.text.update',['name'=>'Changed','product_id'=>1],'v1'))throw new RuntimeException('Changed payload retained approval digest.');
if($a===Policy::digest('catalogue.text.update',['name'=>'AELVO','product_id'=>1],'v2'))throw new RuntimeException('Changed record version retained approval digest.');
Policy::grant(['owner_id'=>7,'nonce'=>'random','expires_at'=>2000,'last_seen_at'=>1000],7,1100);
expectBlocked(fn()=>Policy::grant(['owner_id'=>7,'nonce'=>'random','expires_at'=>2000,'last_seen_at'=>1000],7,1300));
expectBlocked(fn()=>Policy::grant(['owner_id'=>7,'nonce'=>'random','expires_at'=>1100,'last_seen_at'=>1000],7,1100));
echo "Owner policy checks passed. Database, email and deployment integration tests remain separate.\n";
