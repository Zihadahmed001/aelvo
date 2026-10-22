<?php
declare(strict_types=1);
// Deliberately no PDO, no payment provider calls, no synthetic bank success.
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/Schema.php';
require dirname(__DIR__).'/app/GatewayPayoutBatches.php';
use Aelvo\GatewayPayoutBatches as Batch;
use Aelvo\Schema;
function checkBatch(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Payout batch regression: '.$message);}
function rejectBatch(callable $action,string $message):void{
    try{$action();}catch(InvalidArgumentException $e){return;}
    throw new RuntimeException('Unsafe payout input accepted: '.$message);
}
$rows=Batch::parseAllocations("AELORDER222 | 8.50 | 0.50\r\nAELORDER111 | 10.00 | 1.00");
checkBatch(count($rows)===2,'multiple matches parsed');
checkBatch($rows[0]['tran_id']==='AELORDER111','deterministic order');
checkBatch($rows[0]['credited_minor']===1000&&$rows[1]['fee_minor']===50,'minor units exact');
checkBatch(Batch::conservation($rows,1850,150),'bank credit plus fee conserved');
checkBatch(!Batch::conservation($rows,1851,150),'incorrect bank credit rejected');
checkBatch(!Batch::conservation($rows,1850,149),'unmatched fee rejected');
checkBatch(!Batch::conservation($rows,0,150),'no fake fee-only bank credit');
checkBatch(Batch::referenceKey('BANK  ABC-123')===Batch::referenceKey('bank abc-123'),'statement whitespace normalized');
rejectBatch(fn()=>Batch::referenceKey('abc'),'short or ambiguous statement reference');
rejectBatch(fn()=>Batch::referenceKey('REF\nmalicious'),'control characters');
rejectBatch(fn()=>Batch::parseAllocations('AELORDER111 | 0.00 | 0.00'),'zero allocation');
rejectBatch(fn()=>Batch::parseAllocations('AELORDER111 | 5.00 | 0.00'."\n".'AELORDER111 | 4.00 | 0.00'),'duplicate charge');
rejectBatch(fn()=>Batch::parseAllocations('AELORDER111 | 5.00 | 0.00'."\n".'aelorder111 | 4.00 | 0.00'),'case-insensitive duplicate charge');
rejectBatch(fn()=>Batch::parseAllocations('AELORDER111 | 5.123 | 0.00'),'extra decimals');
rejectBatch(fn()=>Batch::parseAllocations('AELORDER111 | 1e6 | 0.00'),'floating/scientific notation');
rejectBatch(fn()=>Batch::parseAllocations('AELORDER111 | 5.00'),'missing fee field');
rejectBatch(fn()=>Batch::parseAllocations('bad | 5.00 | 0.00'),'bad transaction');
rejectBatch(fn()=>Batch::parseAllocations(implode("\n",array_fill(0,41,'AELORDER111 | 1.00 | 0.00'))),'more than 40 allocations');
checkBatch(in_array('gateway-payout-batches',Schema::FILES,true),'payout migration present in immutable order');
$sql=file_get_contents(dirname(__DIR__).'/database/gateway-payout-batches.sql');
checkBatch(count(Schema::statements($sql))===2,'two immutable SQL tables');
$root=dirname(__DIR__).'/';
foreach([
    'app/bootstrap.php'=>'GatewayPayoutBatches',
    'app/AdminView.php'=>'gateway-payout-batch',
    'public/index.php'=>"case 'gateway-payout-batch':",
    'public/document.php'=>'aelvo_gateway_payout_batches',
    'app/Reports.php'=>'aelvo_gateway_payout_allocations',
    'app/FinanceReconciliation.php'=>'aelvo_gateway_payout_batches',
    'app/FinancialPerformance.php'=>'aelvo_gateway_payout_batches',
    'app/GatewayExceptions.php'=>'aelvo_gateway_payout_allocations',
    'app/GatewayPayments.php'=>'aelvo_gateway_payout_allocations',
] as $file=>$text)checkBatch(str_contains(file_get_contents($root.$file),$text),'wiring '.$file);
$service=file_get_contents($root.'app/GatewayPayoutBatches.php');
foreach(['FOR UPDATE','request_hash','request_key','aelvo_accounts','aelvo_ledger','aelvo_gateway_receipts','aelvo_gateway_payout_allocations','bank_reference_key','state=\'Pending\''] as $guard)
    checkBatch(str_contains($service,$guard),'posting guard: '.$guard);
foreach(['SslCommerz::request','UPDATE aelvo_orders','UPDATE aelvo_gateway_attempts','UPDATE aelvo_gateway_receipts','UPDATE aelvo_gateway_settlements'] as $danger)
    checkBatch(!str_contains($service,$danger),'no provider/charge/order mutation from bank matching: '.$danger);
echo "Gateway payout batch: exact BDT parsing, matched totals, unique statement references, cumulative reconciliation and route wiring checked offline. No PDO/real bank/provider test.\n";
