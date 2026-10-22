<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
Aelvo\Security::start((bool)$config['secure_cookies']);
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'");
$actor=Aelvo\Security::actor($pdo);
if(!$actor||!Aelvo\Security::can($pdo,$actor,'purchases','view')){http_response_code(403);exit('Access denied');}
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$id){http_response_code(404);exit('Purchase not found');}
$q=$pdo->prepare('SELECT p.*,s.name AS directory_supplier,s.company,s.phone,s.email,s.address AS supplier_address FROM aelvo_purchases p LEFT JOIN aelvo_purchase_suppliers ps ON ps.purchase_id=p.id LEFT JOIN aelvo_suppliers s ON s.id=ps.supplier_id WHERE p.id=?');
$q->execute([$id]);$purchase=$q->fetch();
if(!$purchase){http_response_code(404);exit('Purchase not found');}
$q=$pdo->prepare('SELECT i.variant_id,i.qty,i.unit_cost_minor,v.sku,COALESCE(c.landed_total_minor,i.qty*i.unit_cost_minor) AS landed_total_minor FROM aelvo_purchase_items i JOIN aelvo_variants v ON v.id=i.variant_id LEFT JOIN aelvo_purchase_landed_items c ON c.purchase_item_id=i.id WHERE i.purchase_id=? ORDER BY i.id');
$q->execute([$id]);$items=$q->fetchAll();
$q=$pdo->prepare('SELECT items_minor,shipping_minor,other_minor,discount_minor FROM aelvo_purchase_charges WHERE purchase_id=?');$q->execute([$id]);$charges=$q->fetch();
$q=$pdo->prepare('SELECT sp.reference,sp.amount_minor,sp.recorded_at,a.name AS account_name FROM aelvo_supplier_payments sp JOIN aelvo_accounts a ON a.id=sp.account_id WHERE sp.purchase_id=? ORDER BY sp.id');
$q->execute([$id]);$payments=$q->fetchAll();
$paid=array_sum(array_map(fn($p)=>(int)$p['amount_minor'],$payments));
$due=max(0,(int)$purchase['total_minor']-$paid);
$e=fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$money=fn(int $minor):string=>'৳'.number_format($minor/100,2);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Purchase <?= $e($purchase['reference']) ?> · AELVO</title>
<style>body{font:16px/1.55 system-ui,sans-serif;color:#16231b;background:#f5f5f0;margin:0;padding:1.5rem}main{max-width:850px;margin:auto;padding:2rem;background:#fff;border:1px solid #c4cec7;border-radius:14px}h1{margin-bottom:0}small,.subtle{color:#526255}table{border-collapse:collapse;width:100%;margin:1rem 0}th,td{border-bottom:1px solid #c4cec7;padding:.55rem;text-align:left}td.num,th.num{text-align:right}.notice{background:#e1e5e0;padding:1rem;border-radius:8px}button{background:#466b5c;color:white;border:0;padding:.8rem 1.1rem;border-radius:8px;cursor:pointer}dl{display:grid;grid-template-columns:1fr auto;gap:.35rem}@media(max-width:600px){body{padding:0}main{padding:.8rem}table{font-size:.85rem}}@media print{body{background:#fff;padding:0}main{padding:0;border:0}.print-action{display:none}}</style></head><body><main>
<h1>AELVO <small>FASHION</small></h1><p>Purchase record · <?= $e($purchase['reference']) ?> · <?= $e($purchase['status']) ?></p><p class="notice">This is an internal purchase and payment record, not a tax invoice and not proof of any payment beyond individually posted, verified records. Supplier payment entries do not initiate transfers.</p>
<p><strong>Supplier:</strong> <?= $e($purchase['supplier']) ?><?php if($purchase['company']??''):?> · <?= $e($purchase['company']) ?><?php endif; ?><br><strong>Created UTC:</strong> <?= $e($purchase['created_at']) ?></p>
<?php if($purchase['directory_supplier']!==null):?><p class="subtle"><?= $e($purchase['phone']) ?> · <?= $e($purchase['email']) ?></p><p><?= nl2br($e($purchase['supplier_address'])) ?></p><?php else: ?><p class="subtle">Historical purchase: no supplier-directory record linked.</p><?php endif; ?>
<h2>Purchase lines</h2><table><thead><tr><th>Variant / SKU</th><th class="num">Quantity</th><th class="num">Unit purchase cost</th><th class="num">Landed line cost</th></tr></thead><tbody>
<?php foreach($items as $item):?><tr><td>#<?= (int)$item['variant_id'] ?> · <?= $e($item['sku']) ?></td><td class="num"><?= (int)$item['qty'] ?></td><td class="num"><?= $money((int)$item['unit_cost_minor']) ?></td><td class="num"><?= $money((int)$item['landed_total_minor']) ?></td></tr><?php endforeach; ?></tbody></table>
<dl><dt>Items cost</dt><dd><?= $money($charges?(int)$charges['items_minor']:array_sum(array_map(fn($i)=>(int)$i['qty']*(int)$i['unit_cost_minor'],$items))) ?></dd><dt>Shipping / import</dt><dd><?= $money((int)($charges['shipping_minor']??0)) ?></dd><dt>Other landed costs</dt><dd><?= $money((int)($charges['other_minor']??0)) ?></dd><dt>Purchase discount</dt><dd>−<?= $money((int)($charges['discount_minor']??0)) ?></dd><dt><strong>Purchase total</strong></dt><dd><strong><?= $money((int)$purchase['total_minor']) ?></strong></dd><dt>Recorded supplier payouts</dt><dd><?= $money($paid) ?></dd><dt>Outstanding amount</dt><dd><?= $money($due) ?></dd></dl>
<h2>Recorded payments</h2><?php if($payments):?><table><thead><tr><th>Reference</th><th>Account</th><th>Recorded UTC</th><th class="num">Amount</th></tr></thead><tbody><?php foreach($payments as $p):?><tr><td><?= $e($p['reference']) ?></td><td><?= $e($p['account_name']) ?></td><td><?= $e($p['recorded_at']) ?></td><td class="num"><?= $money((int)$p['amount_minor']) ?></td></tr><?php endforeach;?></tbody></table><?php else:?><p>No supplier payment has been recorded.</p><?php endif; ?>
<p class="print-action"><button onclick="window.print()" type="button">Print internal purchase record</button></p></main></body></html>
