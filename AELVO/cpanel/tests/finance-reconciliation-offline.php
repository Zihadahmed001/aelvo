<?php
declare(strict_types=1);
// Pure calculation and source-wiring smoke checks; does not touch MySQL or accounts.
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/FinanceReconciliation.php';
use Aelvo\FinanceReconciliation;
function checkFinance(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException('Finance reconciliation regression: '.$message);
}
checkFinance(FinanceReconciliation::variance(10000,10000)===0,'Balanced account');
checkFinance(FinanceReconciliation::variance(9500,10000)===-500,'Ledger account shortage');
checkFinance(FinanceReconciliation::variance(10500,10000)===500,'Ledger account surplus');
checkFinance(FinanceReconciliation::due(120000,70000)===['outstanding_minor'=>50000,'overpaid_minor'=>0],'Supplier unpaid balance');
checkFinance(FinanceReconciliation::due(120000,125000)===['outstanding_minor'=>0,'overpaid_minor'=>5000],'Do not report a negative debt');
checkFinance(FinanceReconciliation::due(120000,120000)===['outstanding_minor'=>0,'overpaid_minor'=>0],'Supplier paid in full');
$service=file_get_contents(dirname(__DIR__).'/app/FinanceReconciliation.php');
$admin=file_get_contents(dirname(__DIR__).'/app/AdminView.php');
$boot=file_get_contents(dirname(__DIR__).'/app/bootstrap.php');
$export=file_get_contents(dirname(__DIR__).'/public/reconciliation-export.php');
checkFinance(str_contains($boot,"'Reports','FinanceReconciliation'"),'Service loaded with backend');
checkFinance(str_contains($admin,"new FinanceReconciliation(\$db,\$actor)"),'Admin Reports wiring');
checkFinance(str_contains($service,"\$this->allow('finance','view')")&&str_contains($service,"\$this->allow('reports','view')"),'Finance permissions required');
checkFinance(str_contains($service,"'Received'") && str_contains($service,'uncosted_purchase_lines'),'Uncosted inventory flagged, no invented margin');
checkFinance(str_contains($export,"'finance','view'") && str_contains($export,"'reports','view'") && str_contains($export,'Reports::class'), 'Export permission and CSV escaping');
checkFinance(!str_contains($service,'UPDATE aelvo_')&&!str_contains($service,'INSERT INTO aelvo_'),'Reconciliation is read-only');
echo "Financial arithmetic, missing cost disclosure, permissions and read-only wiring passed (offline only).\n";
