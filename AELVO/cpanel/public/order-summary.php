<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
Aelvo\Security::start((bool)$config['secure_cookies']);
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; frame-ancestors \'none\'; base-uri \'none\'');
$actor=Aelvo\Security::actor($pdo);
if(!$actor||!Aelvo\Security::can($pdo,$actor,'orders','view')){http_response_code(403);exit('Access denied');}
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if($id===false||$id===null){http_response_code(404);exit('Order not found');}
$q=$pdo->prepare('SELECT * FROM aelvo_orders WHERE id=? LIMIT 1');$q->execute([(int)$id]);$order=$q->fetch();
if(!$order){http_response_code(404);exit('Order not found');}
$q=$pdo->prepare('SELECT product_name,variant_label,qty,unit_price_minor FROM aelvo_order_items WHERE order_id=? ORDER BY id');$q->execute([(int)$id]);$items=$q->fetchAll();
$canPayments=Aelvo\Security::can($pdo,$actor,'payments','view');
$canReturns=Aelvo\Security::can($pdo,$actor,'returns','view');
$canCourier=Aelvo\Security::can($pdo,$actor,'courier','view');
$received=$gateway=$refunded=0;$courier=null;
if($canPayments){
    $q=$pdo->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM aelvo_order_receipts WHERE order_id=?');$q->execute([(int)$id]);$received=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM aelvo_gateway_receipts WHERE order_id=?');$q->execute([(int)$id]);$gateway=(int)$q->fetchColumn();
}
if($canReturns){
    $q=$pdo->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM aelvo_refunds WHERE order_id=?');$q->execute([(int)$id]);$refunded=(int)$q->fetchColumn();
}
if($canCourier){
    $q=$pdo->prepare('SELECT booking_state,cod_minor,remote_status FROM aelvo_courier_shipments WHERE order_id=?');$q->execute([(int)$id]);$courier=$q->fetch()?:null;
}
$q=$pdo->prepare('SELECT COALESCE(SUM(discount_minor),0) FROM aelvo_coupon_redemptions WHERE order_id=?');$q->execute([(int)$id]);$discount=(int)$q->fetchColumn();
$e=fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$money=fn(int $p):string=>'৳'.number_format($p/100,2);
$uncollectedBeforeCourier=max(0,(int)$order['total_minor']-$received-$gateway);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Order summary <?= $e($order['reference']) ?> · AELVO</title>
<style>body{font:16px/1.55 system-ui,sans-serif;color:#16231b;background:#f5f5f0;margin:0;padding:2rem}main{max-width:780px;margin:auto;padding:2rem;background:white;border:1px solid #c4cec7;border-radius:14px}h1{margin-bottom:0}small{color:#526255}table{border-collapse:collapse;width:100%;margin:1.5rem 0}td,th{padding:.65rem;text-align:left;border-bottom:1px solid #c4cec7}td.num,th.num{text-align:right}dl{display:grid;grid-template-columns:1fr auto;gap:.3rem}dt,dd{margin:0}button{padding:.7rem 1.25rem;background:#466b5c;color:white;border:0;border-radius:8px;cursor:pointer}address{white-space:pre-line;font-style:normal}.disclaimer{padding:.8rem;background:#e1e5e0;border-radius:8px}@media print{body{background:white;padding:0}main{border:0;padding:0}.print-action{display:none}}@media (max-width:600px){body{padding:.5rem}main{padding:1rem}table{font-size:.85rem}}</style></head><body><main><h1>AELVO <small>FASHION</small></h1><p>Order summary · <?= $e($order['reference']) ?></p><p class="disclaimer">Internal order summary, not a tax invoice or payment receipt. Courier COD and gateway-validated charges are not merchant bank cash. Refunds require independent reconciliation.</p>
<p><strong>Customer:</strong> <?= $e($order['customer_name']) ?><br><strong>Phone:</strong> <?= $e($order['phone']) ?><br><strong>Status:</strong> <?= $e($order['status']) ?> / <?= $e($order['payment_status']) ?><br><strong>Order date (UTC):</strong> <?= $e($order['created_at']) ?></p><address><?= $e($order['address']) ?></address>
<table><thead><tr><th>Item / variant</th><th class="num">Qty</th><th class="num">Unit</th><th class="num">Amount</th></tr></thead><tbody><?php foreach($items as $item): ?><tr><td><?= $e($item['product_name']) ?><small> · <?= $e($item['variant_label']) ?></small></td><td class="num"><?= (int)$item['qty'] ?></td><td class="num"><?= $money((int)$item['unit_price_minor']) ?></td><td class="num"><?= $money((int)$item['qty']*(int)$item['unit_price_minor']) ?></td></tr><?php endforeach; ?></tbody></table>
<dl><dt>Items subtotal</dt><dd><?= $money((int)$order['subtotal_minor']) ?></dd><dt>Delivery charge</dt><dd><?= $money((int)$order['delivery_minor']) ?></dd><dt>Configured tax charged</dt><dd><?= $money((int)$order['tax_minor']) ?></dd><dt>Recorded coupon discount</dt><dd>−<?= $money($discount) ?></dd><dt><strong>Order total</strong></dt><dd><strong><?= $money((int)$order['total_minor']) ?></strong></dd><?php if($canPayments): ?><dt>Verified direct receipts</dt><dd><?= $money($received) ?></dd><dt>Gateway charges validated (not bank payout)</dt><dd><?= $money($gateway) ?></dd><dt>Balance before courier collections / adjustments</dt><dd><?= $money($uncollectedBeforeCourier) ?></dd><?php endif; ?><?php if($canReturns): ?><dt>Recorded paid refunds (separate event)</dt><dd><?= $money($refunded) ?></dd><?php endif; ?><?php if($canCourier && $courier): ?><dt>Courier booking state (not payment)</dt><dd><?= $e($courier['booking_state']) ?></dd><dt>Courier booked COD (not received cash)</dt><dd><?= $money((int)$courier['cod_minor']) ?></dd><?php endif; ?></dl>
<p class="print-action"><button type="button" onclick="window.print()">Print order summary</button></p></main></body></html>
