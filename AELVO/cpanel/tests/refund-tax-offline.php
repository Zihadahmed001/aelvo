<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/RefundTaxes.php';
require dirname(__DIR__).'/app/Schema.php';
use Aelvo\RefundTaxes;
use Aelvo\Schema;
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
RefundTaxes::validate(0,1000,0,0);check(true,'legacy non-tax order accepts explicit zero');
RefundTaxes::validate(100,1000,500,400);check(true,'last available tax can be returned');
RefundTaxes::validate(50,1000,500,400);check(true,'partial tax allocation preserves remaining cap');
foreach([[101,100,500,0],[-1,200,500,0],[101,1000,100,0],[1,200,0,0],[0,200,100,101]] as $bad){
    $threw=false;try{RefundTaxes::validate(...$bad);}catch(InvalidArgumentException){$threw=true;}
    check($threw,'invalid tax share rejected: '.json_encode($bad));
}
check(in_array('refund-tax-allocation',Schema::FILES,true) && array_search('refund-tax-allocation',Schema::FILES,true)<array_search('variant-pricing',Schema::FILES,true),'tax allocation remains before later additive migrations');
$files=[
    dirname(__DIR__).'/app/Returns.php',dirname(__DIR__).'/app/ReturnsView.php',
    dirname(__DIR__).'/app/ReturnOperations.php',dirname(__DIR__).'/app/FinancialPerformance.php',
    dirname(__DIR__).'/public/financial-performance-export.php',
];
foreach($files as $file)check(str_contains(file_get_contents($file),'tax_refund')||str_contains(file_get_contents($file),'tax_refunded')||str_contains(file_get_contents($file),'refund_tax_allocations'),basename($file).' tax integration present');
check(str_contains(file_get_contents(dirname(__DIR__).'/app/Returns.php'),'RefundTaxes::committed'),'tax cap checked under order lock');
check(str_contains(file_get_contents(dirname(__DIR__).'/app/Returns.php'),'RefundTaxes::requireKnownHistory'),'unallocated prior taxed refunds block new proposals');
check(str_contains(file_get_contents(dirname(__DIR__).'/app/Returns.php'),'RefundTaxes::allocation'),'old taxable proposals cannot silently get zero allocation');
echo "Offline validation only; MySQL, browser and tax/legal reconciliation NOT tested.\n";
