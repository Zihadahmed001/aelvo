<?php
if(!isset($customers,$pdo)){http_response_code(404);exit;}
function accountStart(string $action):void{echo '<form method="post" enctype="multipart/form-data" class="checkout-form"><input type="hidden" name="csrf" value="'.esc($_SESSION['csrf']).'"><input type="hidden" name="action" value="'.esc($action).'">';}
?>
<section class="page"><h1><?=tr('Your account','আপনার অ্যাকাউন্ট')?></h1><p><a class="btn secondary" href="store.php?page=support"><?=tr('Help, contact & bug report','সাহায্য, যোগাযোগ ও সমস্যা রিপোর্ট')?></a></p>
<?php if(isset($_SESSION['account_notice'])): ?><p role="status"><?=esc($_SESSION['account_notice'])?></p><?php unset($_SESSION['account_notice']);endif;?>
<?php if(isset($_SESSION['customer_challenge'])): accountStart('customer-verify');?><h2><?=tr('Verify your phone','ফোন যাচাই করুন')?></h2><label><?=tr('Six-digit code','ছয় সংখ্যার কোড')?><input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><button class="btn"><?=tr('Verify','যাচাই করুন')?></button></form><?php endif;?>
<?php if(!$customer):?><div class="account-grid"><section><h2><?=tr('Sign in','লগইন')?></h2><?php accountStart('customer-login');?><label><?=tr('Phone','ফোন')?><input name="phone" type="tel" autocomplete="username" required></label><label><?=tr('Password','পাসওয়ার্ড')?><input name="password" type="password" autocomplete="current-password" required maxlength="72"></label><button class="btn"><?=tr('Sign in','লগইন')?></button></form></section>
<section><h2><?=tr('Create account','অ্যাকাউন্ট খুলুন')?></h2><?php accountStart('customer-signup');?><label><?=tr('Name','নাম')?><input name="name" autocomplete="name" maxlength="120" required></label><label><?=tr('Phone','ফোন')?><input name="phone" type="tel" autocomplete="tel" required></label><label><?=tr('Password (12+ characters)','পাসওয়ার্ড (১২+ অক্ষর)')?><input name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></label><label><?=tr('Confirm password','পাসওয়ার্ড নিশ্চিত করুন')?><input name="confirm" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></label><p><?=tr('An account is created only after phone verification. You can also checkout as a guest.','ফোন যাচাইয়ের পর অ্যাকাউন্ট তৈরি হবে। অ্যাকাউন্ট ছাড়াও অর্ডার করা যাবে।')?></p><button class="btn"><?=tr('Send verification code','যাচাই কোড পাঠান')?></button></form></section></div>
<details><summary><?=tr('Forgot password?','পাসওয়ার্ড ভুলে গেছেন?')?></summary>
<?php accountStart('customer-reset');?><label><?=tr('Account phone','অ্যাকাউন্টের ফোন')?><input name="phone" type="tel" autocomplete="tel" required></label><label><?=tr('New password (12+ characters)','নতুন পাসওয়ার্ড (১২+ অক্ষর)')?><input name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></label><label><?=tr('Confirm new password','নতুন পাসওয়ার্ড নিশ্চিত করুন')?><input name="confirm" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></label><p><?=tr('Verify the code sent to this phone. Successful recovery signs out older sessions.','ফোনে পাঠানো কোড যাচাই করুন। পাসওয়ার্ড পরিবর্তন হলে পুরোনো লগইন বন্ধ হবে।')?></p><button class="btn"><?=tr('Send recovery code','রিকভারি কোড পাঠান')?></button></form></details>
<?php else:?><p><?=esc($customer['name'])?> · <?=esc($customer['phone'])?> — <?=tr('Verified','যাচাইকৃত')?></p>
<nav class="account-quick-links" aria-label="<?=tr('Account sections','অ্যাকাউন্টের অংশ')?>"><a href="#account-security"><?=tr('Profile & security','প্রোফাইল ও নিরাপত্তা')?></a><a href="#account-addresses"><?=tr('Saved addresses','সংরক্ষিত ঠিকানা')?></a><a href="#account-orders"><?=tr('Orders','অর্ডার')?></a><a href="store.php?page=wishlist"><?=tr('Wishlist','পছন্দের তালিকা')?></a><a href="store.php?page=support"><?=tr('Help','সাহায্য')?></a></nav>
<div class="account-section" id="account-security"><?php require __DIR__.'/profile-view.php';?></div>
<div class="account-section" id="account-addresses"><?php require __DIR__.'/addresses-view.php';?></div>
<div class="account-section" id="account-returns"><?php require __DIR__.'/return-requests-view.php';?></div>
<section class="account-section" id="account-orders"><h2><?=tr('Your orders','আপনার অর্ডার')?></h2>
<?php
$orderCount=$customers->ordersCount((int)$customer['id']);
$orderPageRequest=filter_var($_GET['orders_page']??1,FILTER_VALIDATE_INT);
$orderPages=max(1,(int)ceil($orderCount/10));
$orderPage=min(max(1,$orderPageRequest===false?1:$orderPageRequest),$orderPages);
$orders=$customers->orders((int)$customer['id'],$orderPage);
$cancelByOrder=Aelvo\OrderCancellationRequests::forCustomer($pdo,(int)$customer['id'],array_column($orders,'id'));
$orderEventMap=[];$orderCourierMap=[];
$orderIds=array_map('intval',array_column($orders,'id'));
if($orderIds){
 $placeholders=implode(',',array_fill(0,count($orderIds),'?'));
 // Recheck actual account ownership in each detail query, never infer from a matched phone.
 $q=$pdo->prepare('SELECT e.order_id,e.status,e.created_at FROM aelvo_order_events e JOIN aelvo_customer_orders co ON co.order_id=e.order_id WHERE co.customer_id=? AND e.order_id IN ('.$placeholders.') ORDER BY e.id');
 $q->execute([(int)$customer['id'],...$orderIds]);foreach($q as $event)$orderEventMap[(int)$event['order_id']][]=$event;
 $q=$pdo->prepare("SELECT s.order_id,s.tracking_code,s.remote_status FROM aelvo_courier_shipments s JOIN aelvo_customer_orders co ON co.order_id=s.order_id WHERE co.customer_id=? AND s.booking_state='Booked' AND s.order_id IN (".$placeholders.")");
 $q->execute([(int)$customer['id'],...$orderIds]);foreach($q as $shipment)$orderCourierMap[(int)$shipment['order_id']]=$shipment;
}

if(!$orders)echo '<p>'.tr('No account orders yet. Guest orders are kept separate.','এখনও অ্যাকাউন্টের অর্ডার নেই। Guest অর্ডার আলাদা থাকে।').'</p>';
foreach($orders as $order):?>
<article class="account-order"><h3><?=esc($order['reference'])?></h3><p><?=esc($order['status'])?> · <?=esc($order['payment_status'])?> · ৳<?=number_format($order['total_minor']/100,2)?></p><p><?=esc($order['address'])?></p>
<?php $cancelRequest=$cancelByOrder[(int)$order['id']]??null; ?>
<?php if($cancelRequest):?>
<div class="order-cancellation-status"><strong><?=tr('Cancellation request','অর্ডার বাতিলের অনুরোধ')?>:</strong>
<?=esc($order['status']==='Cancelled'?tr('Order cancelled','অর্ডার বাতিল হয়েছে'):($cancelRequest['state']==='Declined'?tr('Declined — contact support if needed','প্রত্যাখ্যাত — প্রয়োজনে সাপোর্টে যোগাযোগ করুন'):tr('Awaiting staff review — order is still active','স্টাফ রিভিউ বাকি — অর্ডার এখনও সক্রিয়')))?></div>
<?php if($cancelRequest['staff_note']!==''):?><p class="preserve-lines"><strong><?=tr('Staff update','স্টাফের বার্তা')?>:</strong> <?=esc($cancelRequest['staff_note'])?></p><?php endif;?>
<?php elseif(Aelvo\OrderCancellationRequests::canRequest((string)$order['status'])):?>
<details><summary><?=tr('Request order cancellation','অর্ডার বাতিলের অনুরোধ')?></summary>
<?php accountStart('order-cancel-request');?><input type="hidden" name="order_id" value="<?=(int)$order['id']?>"><input type="hidden" name="request_key" value="<?=bin2hex(random_bytes(32))?>">
<label><?=tr('Why do you want to cancel?','কেন বাতিল করতে চান?')?><textarea name="reason" minlength="10" maxlength="1000" required></textarea></label>
<p><?=tr('This only asks staff to review. Payment, stock and courier requirements still apply; cancellation is not immediate.','এটি শুধু স্টাফের কাছে অনুরোধ পাঠাবে। পেমেন্ট, স্টক ও কুরিয়ারের নিয়ম প্রযোজ্য; সঙ্গে সঙ্গে বাতিল হবে না।')?></p>
<button class="btn secondary"><?=tr('Submit cancellation request','বাতিলের অনুরোধ পাঠান')?></button></form></details>
<?php endif;?>
<details><summary><?=tr('Order history and courier updates','অর্ডারের ইতিহাস ও কুরিয়ার আপডেট')?></summary>
<ol class="order-timeline"><?php foreach($orderEventMap[(int)$order['id']]??[] as $event):?><li><?=esc($event['status'])?> — <?=esc($event['created_at'])?> UTC</li><?php endforeach;?></ol>
<?php if(isset($orderCourierMap[(int)$order['id']])):$shipment=$orderCourierMap[(int)$order['id']];?>
<p><?=tr('Courier booking status (not proof of delivery or payment)','কুরিয়ার বুকিংয়ের অবস্থা (ডেলিভারি বা পেমেন্টের প্রমাণ নয়)')?>: <?=esc($shipment['remote_status']?:tr('Awaiting update','আপডেট বাকি'))?></p>
<?php if((string)$shipment['tracking_code']!==''):?><p><?=tr('Courier tracking reference','কুরিয়ার ট্র্যাকিং রেফারেন্স')?>: <?=esc($shipment['tracking_code'])?></p><?php endif;?>
<?php else:?><p><?=tr('No confirmed courier consignment is recorded.','নিশ্চিত কোনো কুরিয়ার বুকিং এখনও নেই।')?></p><?php endif;?></details>
<?php $discountQuery=$pdo->prepare('SELECT code,discount_minor FROM aelvo_coupon_redemptions WHERE order_id=?');$discountQuery->execute([$order['id']]);$discount=$discountQuery->fetch();if($discount):?><p><?=tr('Coupon discount','কুপন ছাড়')?>: <?=esc($discount['code'])?> −৳<?=number_format($discount['discount_minor']/100,2)?></p><?php endif;?>
<?php $q=$pdo->prepare('SELECT i.*,r.id AS review_id FROM aelvo_order_items i LEFT JOIN aelvo_reviews r ON r.order_item_id=i.id WHERE i.order_id=?');$q->execute([$order['id']]);foreach($q as $item):?><p><?=esc($item['product_name'].' · '.$item['variant_label'])?> × <?=(int)$item['qty']?></p>
<?php if($order['status']==='Delivered')require __DIR__.'/return-request-form.php';?>
<?php if($order['status']==='Delivered'&&!$item['review_id']):?><details><summary><?=tr('Write a review','রিভিউ লিখুন')?></summary><?php accountStart('review-submit');?><input type="hidden" name="order_item_id" value="<?=(int)$item['id']?>"><label><?=tr('Rating','রেটিং')?><select name="rating"><?php for($rating=5;$rating>=1;$rating--)echo '<option>'.$rating.'</option>';?></select></label><label><?=tr('Your experience','আপনার অভিজ্ঞতা')?><textarea name="body" maxlength="2000" required></textarea></label><label><?=tr('Photos (optional, up to 3; 5 MB each)','ছবি (ঐচ্ছিক, সর্বোচ্চ ৩টি; প্রতিটি ৫ MB)')?><input name="photos[]" type="file" multiple accept="image/jpeg,image/png,image/webp"></label><p><?=tr('Approved photos appear publicly with your review. Avoid personal information.','অনুমোদিত ছবিগুলো রিভিউয়ের সঙ্গে প্রকাশিত হবে। ব্যক্তিগত তথ্যযুক্ত ছবি দেবেন না।')?></p><button class="btn"><?=tr('Submit for review','রিভিউ জমা দিন')?></button></form></details><?php endif;endforeach;?><?php $proofOrderId=(int)$order['id'];$proofCheckoutId='';if($order['status']!=='Cancelled')require __DIR__.'/payment-proof-view.php';?></article><?php endforeach;?>
<?php if($orderPages>1):?><nav class="account-order-pagination" aria-label="<?=tr('Your order history pages','আপনার অর্ডারের পৃষ্ঠা')?>">
<?php if($orderPage>1):?><a class="btn secondary" href="store.php?page=account&amp;orders_page=<?=$orderPage-1?>#account-orders"><?=tr('Newer orders','নতুন অর্ডার')?></a><?php endif;?>
<span><?=tr('Page','পৃষ্ঠা')?> <?=$orderPage?> / <?=$orderPages?></span>
<?php if($orderPage<$orderPages):?><a class="btn secondary" href="store.php?page=account&amp;orders_page=<?=$orderPage+1?>#account-orders"><?=tr('Older orders','পুরোনো অর্ডার')?></a><?php endif;?>
</nav><?php endif;?></section>
<details><summary><?=tr('Change primary phone','প্রধান ফোন নম্বর পরিবর্তন')?></summary><?php accountStart('customer-phone');?><label><?=tr('New phone number','নতুন ফোন নম্বর')?><input name="phone" type="tel" required></label><p><?=tr('Your current number stays unchanged until the new number is verified.','নতুন নম্বর যাচাই না হওয়া পর্যন্ত পুরোনো নম্বর থাকবে।')?></p><button class="btn"><?=tr('Send code','কোড পাঠান')?></button></form></details>
<?php accountStart('customer-logout');?><button class="btn secondary"><?=tr('Sign out','লগআউট')?></button></form><?php endif;?></section>
