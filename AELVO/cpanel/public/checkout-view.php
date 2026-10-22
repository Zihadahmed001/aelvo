<?php
// Included by store.php after session/bootstrap; direct requests reveal nothing.
if(!isset($checkout,$checkoutId)){http_response_code(404);exit;}
try{$review=$checkout->get($checkoutId);}catch(InvalidArgumentException $e){echo '<section class="page"><p role="alert">'.esc($e->getMessage()).'</p><a class="btn" href="store.php?page=cart">'.tr('Return to bag','ব্যাগে ফিরুন').'</a></section>';return;}
$data=$review['data'];
?>
<section class="page checkout-review"><h1><?=tr($review['order_id']?'Order received':'Review your order',$review['order_id']?'অর্ডার গ্রহণ করা হয়েছে':'আপনার অর্ডার দেখুন')?></h1>
<?php if($review['order_id']):$q=$pdo->prepare('SELECT reference,status FROM aelvo_orders WHERE id=?');$q->execute([$review['order_id']]);$order=$q->fetch();?>
<p role="status"><strong><?=esc($order['reference'])?></strong> · <?=esc($order['status'])?></p><?php $paymentSummary=Aelvo\Payments::summary($pdo,(int)$review['order_id']);?><p><?=tr('Verified received','যাচাইকৃত প্রাপ্ত টাকা')?>: ৳<?=number_format($paymentSummary['paid_minor']/100,2)?> · <?=tr('Remaining due','বাকি')?>: ৳<?=number_format($paymentSummary['due_minor']/100,2)?></p>
<?php endif;?>
<p><?=esc($data['customer_name'])?> · <?=esc($data['phone'])?></p><p class="preserve-lines"><?=esc($data['address'])?></p>
<?php foreach($data['items'] as $line):?><div class="cart-line"><div class="line-main"><h3><?=esc($line['name'])?></h3><p><?=esc($line['label'])?> × <?=(int)$line['qty']?></p></div><strong>৳<?=number_format($line['price_minor']*$line['qty']/100,2)?></strong></div><?php endforeach;?>
<?php if(!empty($data['coupon'])):?><p><?=tr('Coupon discount','কুপন ছাড়')?> (<?=esc($data['coupon']['code'])?>): −৳<?=number_format($data['discount_minor']/100,2)?></p><?php endif;?>
<p><?=tr('Required advance','প্রয়োজনীয় অগ্রিম')?>: ৳<?=number_format(($data['advance_minor']??0)/100,2)?></p>
<p><?=tr('Delivery area','ডেলিভারি এলাকা')?>: <?=($data['zone']??'')==='dhaka'?tr('Dhaka city','ঢাকা শহর'):tr('Outside Dhaka','ঢাকার বাইরে')?></p>
<p><?=tr('Delivery','ডেলিভারি')?>: ৳<?=number_format($data['delivery_minor']/100,2)?></p>
<p><?=tr('Tax (configured)','কর (নির্ধারিত)')?>: ৳<?=number_format(($data['tax']['tax_minor']??0)/100,2)?></p><h2><?=tr('Total','সর্বমোট')?>: ৳<?=number_format($review['total_minor']/100,2)?></h2>
<?php if(!$review['order_id']):?>
<p><?=tr('Cash on delivery. Stock is reserved only when the order is placed.','ক্যাশ অন ডেলিভারি। অর্ডার নিশ্চিত হলে stock সংরক্ষিত হবে।')?></p>
<?php if($checkout->verified($review)):?>
<form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><input type="hidden" name="checkout_id" value="<?=esc($checkoutId)?>"><input type="hidden" name="action" value="place-order"><label><input type="checkbox" name="confirm" value="1" required> <?=tr('I confirm the items, address and total shown above.','উপরের পণ্য, ঠিকানা ও মোট দাম দেখে নিশ্চিত করছি।')?></label><button class="btn"><?=tr('Place COD order','COD অর্ডার নিশ্চিত করুন')?></button></form>
<?php else:?>
<form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><input type="hidden" name="checkout_id" value="<?=esc($checkoutId)?>"><button class="btn secondary" name="action" value="send-code"><?=tr('Send / resend phone code','ফোনে কোড পাঠান / আবার পাঠান')?></button></form>
<?php if($review['otp_hash'] && (int)$review['otp_expires_at']>time()):?><form method="post" class="checkout-form"><input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><input type="hidden" name="checkout_id" value="<?=esc($checkoutId)?>"><label><?=tr('Phone verification code','ফোন যাচাই কোড')?><input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><button class="btn" name="action" value="verify-code"><?=tr('Verify phone','ফোন যাচাই করুন')?></button></form><?php endif;endif;?>
<a href="store.php?page=checkout"><?=tr('Change order details','অর্ডারের তথ্য পরিবর্তন করুন')?></a>
<?php else: $proofOrderId=(int)$review['order_id'];$proofCheckoutId=$checkoutId;
try{$gatewayConfig=Aelvo\SslCommerz::credentials($pdo,$config['encryption_key']??'');}
catch(Throwable $ignored){$gatewayConfig=[];}
$pendingProof=$pdo->prepare("SELECT 1 FROM aelvo_payment_proofs WHERE order_id=? AND status='Pending' LIMIT 1");
$pendingProof->execute([$proofOrderId]);
if(Aelvo\GatewayPayments::enabled($gatewayConfig,$config)&&$paymentSummary['due_minor']>0&&Aelvo\GatewayPayments::unresolvedForOrder($pdo,$proofOrderId)===null&&$pendingProof->fetchColumn()===false):?>
<h2><?=tr('Online payment (SSLCOMMERZ)','অনলাইন পেমেন্ট (SSLCommerz)')?></h2>
<p><?=tr('Payment must be independently verified. A gateway success page is not proof of payment.','পেমেন্ট আলাদাভাবে যাচাই করা হবে। গেটওয়ের success পেজ পেমেন্টের প্রমাণ নয়।')?></p>
<form method="post" action="gateway.php" class="checkout-form">
<input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><input type="hidden" name="checkout_id" value="<?=esc($checkoutId)?>">
<label><?=tr('Email for payment receipt','পেমেন্ট রসিদের ইমেইল')?><input type="email" name="customer_email" maxlength="254" autocomplete="email" required></label>
<label><?=tr('Delivery city','ডেলিভারির শহর')?><input name="customer_city" maxlength="80" required></label>
<label><?=tr('Pay','পেমেন্ট করুন')?><select name="choice"><option value="full"><?=tr('All outstanding balance','পুরো বকেয়া')?> — ৳<?=number_format($paymentSummary['due_minor']/100,2)?></option>
<?php if($paymentSummary['advance_due_minor']>0):?><option value="advance"><?=tr('Required advance only','শুধু নির্ধারিত অগ্রিম')?> — ৳<?=number_format($paymentSummary['advance_due_minor']/100,2)?></option><?php endif;?></select></label>
<button class="btn" type="submit"><?=tr('Continue to secure payment','নিরাপদ পেমেন্টে যান')?></button></form>
<?php endif;require __DIR__.'/payment-proof-view.php';?><a class="btn" href="store.php?page=shop"><?=tr('Continue shopping','আরও কেনাকাটা করুন')?></a><?php endif;?></section>
