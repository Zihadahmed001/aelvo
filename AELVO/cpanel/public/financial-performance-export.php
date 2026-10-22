<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
Aelvo\Security::start((bool)$config['secure_cookies']);
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
$actor=Aelvo\Security::actor($pdo);
if(!$actor || !Aelvo\Security::can($pdo,$actor,'reports','view') || !Aelvo\Security::can($pdo,$actor,'finance','view')) {
    http_response_code(403);exit('Permission denied.');
}
$from=$_GET['from']??null;$to=$_GET['to']??null;
if(!is_string($from)||!is_string($to)){http_response_code(400);exit('Invalid report dates.');}
try {
    $service=new Aelvo\FinancialPerformance($pdo,$actor);
    $summary=$service->period($from,$to);
    $rows=$service->deliveredOrderReview($from,$to);
} catch(InvalidArgumentException $e){http_response_code(400);exit('Invalid report dates.');}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="aelvo-financial-review-'.gmdate('Ymd').'.csv"');
$out=fopen('php://output','wb');
$write=static function(array $cells)use($out):void {
    fputcsv($out,array_map([Aelvo\Reports::class,'csvCell'],$cells),',','"','');
};
$money=static fn($minor):string=>number_format((int)$minor/100,2,'.','');
$write(['AELVO financial review','UTC from',$from,'UTC to',$to,'Not a verified profit/loss or cash flow statement']);
$write(['Metric','BDT (recorded)']);
foreach([
    'delivered_merchandise_minor'=>'Delivered merchandise subtotal',
    'delivery_charged_minor'=>'Delivery charged',
    'tax_charged_minor'=>'Configured tax charged (not merchant income)',
    'discounts_applied_minor'=>'Discounts applied',
    'delivered_minor'=>'Net order totals first delivered',
    'paid_refunds_minor'=>'Paid refunds recorded during interval',
    'tax_refunded_minor'=>'Explicit tax included in paid refunds',
    'known_cogs_minor'=>'Posted known COGS during interval',
    'known_return_reversal_minor'=>'Posted sellable return cost reversal',
    'known_disposals_minor'=>'Recorded known inventory disposal cost',
    'operating_expenses_minor'=>'Recorded operating expenses',
    'gateway_fees_minor'=>'Gateway settlement fees',
    'courier_fees_minor'=>'Courier remittance fees',
    'recorded_bridge_minor'=>'Incomplete recorded-components subtotal; NOT profit',
] as $key=>$label)$write([$label,$money($summary[$key])]);
$write(['Unresolved coverage','Count']);
foreach([
    'unknown_cogs_units','delivered_lines_missing_postings','unknown_restock_units',
    'return_layers_missing','unknown_disposal_units','orders_missing_delivery_events',
    'invalid_discount_orders','taxed_orders_missing_snapshots','taxed_refunds_missing_allocations',
] as $key)$write([$key,(string)$summary[$key]]);
foreach($summary['warnings'] as $warning)$write(['WARNING',$warning]);
$write(['ORDER REVIEW — first 100 delivered orders, lifetime refund/return amounts below']);
$write(['Reference','First delivery UTC','Order total BDT','Tax charged BDT','Known delivery COGS BDT','Unknown cost units','Unposted lines','Lifetime paid refunds BDT','Lifetime allocated refund tax BDT','Lifetime sellable return cost BDT','Lifetime unpriced return units']);
foreach($rows as $r)$write([
    $r['reference'],$r['delivered_at'],$money($r['total_minor']),$money($r['tax_minor']),$money($r['known_cost_minor']),
    (string)$r['unknown_qty'],(string)$r['unposted_lines'],$money($r['lifetime_paid_refunds_minor']),$money($r['lifetime_refunded_tax_minor']),
    $money($r['lifetime_return_reversal_minor']),(string)$r['lifetime_unpriced_return_units'],
]);
fclose($out);
