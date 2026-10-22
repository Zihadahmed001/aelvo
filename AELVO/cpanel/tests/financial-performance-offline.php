<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/Reports.php';
require dirname(__DIR__).'/app/FinancialPerformance.php';
use Aelvo\FinancialPerformance;
function verifyPerformance(bool $pass,string $label): void {
    if(!$pass)throw new RuntimeException('Financial performance regression: '.$label);
    echo 'PASS '.$label.PHP_EOL;
}
$values=[
    'delivered_minor'=>25000,'paid_refunds_minor'=>4500,
    'known_cogs_minor'=>10000,'known_return_reversal_minor'=>2400,
    'known_disposals_minor'=>300,'operating_expenses_minor'=>800,
    'gateway_fees_minor'=>110,'courier_fees_minor'=>390,'tax_charged_minor'=>0,'tax_refunded_minor'=>0,
];
verifyPerformance(FinancialPerformance::recordedBridge($values)===11300,'return cost reverses COGS, refund reduces recorded bridge');
verifyPerformance(FinancialPerformance::recordedBridge([...$values,'known_return_reversal_minor'=>0])===8900,'without sellable return reversal, bridge is lower');
verifyPerformance(FinancialPerformance::recordedBridge([...$values,'tax_charged_minor'=>700])===10600,'configured tax excluded from incomplete margin bridge');
verifyPerformance(FinancialPerformance::recordedBridge([...$values,'tax_charged_minor'=>700,'tax_refunded_minor'=>160])===10760,'tax returned to customer is not treated as merchandise refund twice');
verifyPerformance(FinancialPerformance::recordedBridge([...$values,'delivered_minor'=>0])<0,'negative event subtotal is possible; no forced positive profit');
foreach ([['delivered_minor'=>-1],['known_cogs_minor'=>'10000'],['courier_fees_minor'=>null]] as $bad) {
    $threw=false;try {FinancialPerformance::recordedBridge(array_replace($values,$bad));}
    catch(InvalidArgumentException){$threw=true;}
    verifyPerformance($threw,'invalid or unknown monetary input is rejected');
}
$coverage=[
    'unknown_cogs_units'=>0,'delivered_lines_missing_postings'=>0,
    'unknown_restock_units'=>0,'return_layers_missing'=>0,
    'unknown_disposal_units'=>0,'orders_missing_delivery_events'=>0,
    'invalid_discount_orders'=>0,'taxed_orders_missing_snapshots'=>0,'taxed_refunds_missing_allocations'=>0,
];
$flags=FinancialPerformance::coverageFlags($coverage);
verifyPerformance(count($flags)===3,'tax, fulfilment, accrual and fee coverage flagged even when FIFO is fully known');
verifyPerformance(count(FinancialPerformance::coverageFlags([...$coverage,'unknown_cogs_units'=>3,'return_layers_missing'=>1]))===5,'unknown cost and missing return valuations are flagged');
$invalid=false;try {FinancialPerformance::coverageFlags([...$coverage,'unknown_cogs_units'=>-1]);}catch(InvalidArgumentException){$invalid=true;}
verifyPerformance($invalid,'negative coverage count rejected');
$service=file_get_contents(dirname(__DIR__).'/app/FinancialPerformance.php');
$ui=file_get_contents(dirname(__DIR__).'/app/AdminView.php');
$boot=file_get_contents(dirname(__DIR__).'/app/bootstrap.php');
verifyPerformance(str_contains($boot,"'FinancialPerformance'"),'service autoloaded');
verifyPerformance(str_contains($ui,'new FinancialPerformance($db,$actor)'),'operational reports screen wired');
verifyPerformance(str_contains($service,"'finance','view'")&&str_contains($service,"'reports','view'"),'both read permissions required');
verifyPerformance(!preg_match('/\b(?:UPDATE|INSERT|DELETE|REPLACE)\s+(?:INTO\s+|FROM\s+)?aelvo_/i',$service),'no balance or ledger mutations');
verifyPerformance(str_contains($service,"origin_type='sellable-return'")&&str_contains($service,"status='Delivered'"),'delivery and return events used');
verifyPerformance(str_contains($ui,'NOT verified net profit'),'incomplete bridge not misrepresented as verified net income');
echo "All financial-performance offline assertions passed; MySQL/browser and accounting acceptance are NOT covered.\n";
