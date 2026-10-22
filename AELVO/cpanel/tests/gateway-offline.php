<?php
declare(strict_types=1);
// No database or provider access: offline regression checks only.
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/SslCommerz.php';
require dirname(__DIR__).'/app/Schema.php';
use Aelvo\SslCommerz;
use Aelvo\Schema;
function checkGateway(bool $ok,string $name): void {if(!$ok)throw new RuntimeException('Gateway test failed: '.$name);}
function rejectGateway(callable $callback,string $name): void {
    try {$callback();}catch(InvalidArgumentException|RuntimeException $e){return;}
    throw new RuntimeException('Gateway input unexpectedly accepted: '.$name);
}
checkGateway(SslCommerz::base('sandbox')==='https://sandbox.sslcommerz.com','Sandbox host');
checkGateway(SslCommerz::base('live')==='https://securepay.sslcommerz.com','Live host');
rejectGateway(fn()=>SslCommerz::base('unknown'),'Unknown provider mode');
checkGateway(SslCommerz::money(125025)==='1250.25','Exact BDT minor units');
checkGateway(SslCommerz::amount('1250.25')===125025,'Exact provider BDT amount');
rejectGateway(fn()=>SslCommerz::amount('1250.256'),'No extra decimals');
rejectGateway(fn()=>SslCommerz::amount('1e6'),'No floating notation');
rejectGateway(fn()=>SslCommerz::amount(1250.25),'No floating-point money');
$hosted='https://sandbox.sslcommerz.com/gwprocess/v3/gw.php?Q=PAY&SESSIONKEY=123';
checkGateway(SslCommerz::hostedUrl($hosted,'sandbox')===$hosted,'Documented provider checkout URL');
rejectGateway(fn()=>SslCommerz::hostedUrl('http://sandbox.sslcommerz.com/gwprocess/v3/gw.php','sandbox'),'HTTPS mandatory');
rejectGateway(fn()=>SslCommerz::hostedUrl('https://sandbox.sslcommerz.com.evil.test/pay','sandbox'),'Provider URL suffix attack');
rejectGateway(fn()=>SslCommerz::hostedUrl('https://sandbox.sslcommerz.com@evil.test/pay','sandbox'),'Provider URL userinfo attack');
rejectGateway(fn()=>SslCommerz::hostedUrl('https://securepay.sslcommerz.com/pay','sandbox'),'Environment binding');
$attempt=['id'=>str_repeat('a',32),'tran_id'=>'AELABCDE12345','expected_minor'=>125025];
$result=['APIConnect'=>'DONE','status'=>'VALIDATED','tran_id'=>$attempt['tran_id'],'val_id'=>'12345678ABCDEFG','currency'=>'BDT','amount'=>'1250.25','risk_level'=>'0','bank_tran_id'=>'BANK-123','value_a'=>$attempt['id']];
$validated=SslCommerz::validateResponse($result,$attempt,'12345678ABCDEFG');
checkGateway($validated['risk']===false && $validated['bank_reference']==='BANK-123','Provider-side validated, non-risk transaction');
checkGateway(SslCommerz::validateResponse([...$result,'risk_level'=>'1'],$attempt,'12345678ABCDEFG')['risk']===true,'Risk holds require manual review');
rejectGateway(fn()=>SslCommerz::validateResponse([...$result,'amount'=>'1250.26'],$attempt,'12345678ABCDEFG'),'Mismatch on amount');
rejectGateway(fn()=>SslCommerz::validateResponse([...$result,'currency'=>'USD'],$attempt,'12345678ABCDEFG'),'Currency mismatch');
rejectGateway(fn()=>SslCommerz::validateResponse([...$result,'tran_id'=>'OTHER'],$attempt,'12345678ABCDEFG'),'Reference mismatch');
rejectGateway(fn()=>SslCommerz::validateResponse([...$result,'value_a'=>'OTHER'],$attempt,'12345678ABCDEFG'),'Internal attempt mismatch');
rejectGateway(fn()=>SslCommerz::validateResponse([...$result,'status'=>'FAILED'],$attempt,'12345678ABCDEFG'),'Failed validation cannot receive money');
rejectGateway(fn()=>SslCommerz::validateResponse([...$result,'risk_level'=>'maybe'],$attempt,'12345678ABCDEFG'),'Ambiguous risk is not low-risk');
rejectGateway(fn()=>SslCommerz::validateResponse([...$result,'bank_tran_id'=>''],$attempt,'12345678ABCDEFG'),'No bank transaction evidence');
checkGateway(in_array('gateway',Schema::FILES,true),'Gateway additive schema registered in installer');
$sql=file_get_contents(dirname(__DIR__).'/database/gateway.sql');
checkGateway(count(Schema::statements($sql))===3,'All gateway SQL tables parsed');
$index=file_get_contents(dirname(__DIR__).'/public/index.php');
$upload=file_get_contents(dirname(__DIR__).'/app/Uploads.php');
checkGateway(str_contains($index,"case 'sslcommerz-save':")&&str_contains($index,"case 'gateway-payout-batch':"),'Admin post routes exist');
checkGateway(str_contains($upload,"'payments'")&&str_contains($upload,"\$module==='payments'")&&str_contains($upload,"\$this->allow('payments','edit')")&&str_contains($upload,"\$this->allow('finance','edit')"),'Settlement evidence uploader is permission-gated');
echo "SSLCOMMERZ provider-side identity/amount/risk validation, safe URLs, database schema parsing, and Admin wiring checked offline. No charge, database or network call.\n";
