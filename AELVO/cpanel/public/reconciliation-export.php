<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
Aelvo\Security::start((bool)$config['secure_cookies']);
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
$actor=Aelvo\Security::actor($pdo);
if(!$actor || !Aelvo\Security::can($pdo,$actor,'reports','view') || !Aelvo\Security::can($pdo,$actor,'finance','view')) {
    http_response_code(403);
    exit('Permission denied.');
}
$report=(new Aelvo\FinanceReconciliation($pdo,$actor))->inspect();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="aelvo-reconciliation-'.gmdate('Ymd').'.csv"');
$out=fopen('php://output','wb');
$write=static function(array $cells)use($out):void {
    fputcsv($out,array_map([Aelvo\Reports::class,'csvCell'],$cells),',','"','');
};
$money=static fn(mixed $minor):string=>number_format((int)$minor/100,2,'.','');
$write(['Issue type','Reference','Detail','Recorded BDT','Expected BDT','Variance / due BDT']);
foreach($report['account_variances'] as $r)$write(['Account ledger', $r['id'],$r['name'],$money($r['balance_minor']),$money($r['posted_minor']),$money($r['variance_minor'])]);
foreach($report['unbalanced_transfers'] as $r)$write(['Unbalanced transfer',$r['id'],'Ledger entries '.$r['entries'].'; distinct accounts '.$r['account_count'],'0.00','0.00',$money($r['net_minor'])]);
foreach($report['cash_posting_issues'] as $r)$write(['Cash event posting',$r['id'],$r['kind'].' / detail '.$r['detail_kind'].'; rows '.$r['entries'],$money($r['posted_minor']),$money($r['expected_minor']),$money((int)$r['posted_minor']-(int)$r['expected_minor'])]);
foreach($report['supplier_dues'] as $r)$write(['Supplier purchase',$r['reference'],$r['supplier'].'; '.$r['status'],$money($r['paid_minor']),$money($r['total_minor']),$money($r['outstanding_minor'])]);
foreach($report['order_issues'] as $r)$write(['Order receipt / refund',$r['reference'],'Refunded '.$money($r['refunded_minor']),$money($r['collected_minor']),$money($r['total_minor']),$money((int)$r['collected_minor']-(int)$r['total_minor'])]);
foreach($report['courier_issues'] as $r)$write(['Courier COD',$r['invoice'],'Collected '.$money($r['collected_minor']),$money((int)$r['remitted_minor']+(int)$r['fee_minor']),$money($r['cod_minor']),'']);
foreach($report['gateway_issues'] as $r)$write(['Gateway payout',$r['tran_id'],$r['state'],$money((int)$r['settled_minor']+(int)$r['fee_minor']),$money($r['validated_minor']),'']);
foreach($report['truncated'] as $group=>$cut)if($cut)$write(['TRUNCATED',$group,'At most 100 issue rows exported for this category','','','']);
$write(['SUMMARY','Received supplier outstanding','', '', '',$money($report['supplier_due_minor'])]);
$write(['SUMMARY','Received purchase lines without landed cost','', '', '',(string)$report['uncosted_purchase_lines']]);
$write(['SUMMARY','Courier booking attempts pending review','', '', '',(string)$report['courier_pending_count']]);
$write(['SUMMARY','Gateway attempts pending review','', '', '',(string)$report['gateway_pending_count']]);
$write(['NOTICE','Read-only diagnostics only','Not an income statement or verified profit report','','','']);
fclose($out);
