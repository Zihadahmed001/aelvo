<?php
// Actual session bag and authoritative current catalogue prices; this view never reserves stock.
if(!isset($pdo,$page,$variants) || !in_array($page,['cart','checkout'],true)){http_response_code(404);exit;}
$isBag=$page==='cart';
$items=$isBag?$_SESSION['bag']:($_SESSION['buy_now']??$_SESSION['bag']);
$subtotal=0;$hasUnavailable=false;$itemCount=0;
foreach($items as $variantId=>$quantity){
    $itemCount+=(int)$quantity;
    if(!isset($variants[$variantId]) || (int)$quantity<1 || (int)$quantity>100 || (int)$quantity>(int)$variants[$variantId][1]['available']){
        $hasUnavailable=true;
    }
    if(isset($variants[$variantId]))$subtotal+=(int)$variants[$variantId][1]['price_minor']*(int)$quantity;
}
?>
<section class="page bag-page">
  <nav class="bag-breadcrumb" aria-label="<?=tr('Checkout progress','চেকআউটের ধাপ')?>">
    <a href="store.php?page=shop"><?=tr('Shop','শপ')?></a><span aria-hidden="true">/</span>
    <?php if($isBag):?><strong aria-current="step"><?=tr('Bag','ব্যাগ')?></strong>
    <?php else:?><a href="store.php?page=cart"><?=tr('Bag','ব্যাগ')?></a><span aria-hidden="true">/</span><strong aria-current="step"><?=tr('Checkout','চেকআউট')?></strong><?php endif;?>
  </nav>
  <div class="section-heading"><h1><?=tr($isBag?'Your shopping bag':'Checkout',$isBag?'আপনার শপিং ব্যাগ':'চেকআউট')?></h1>
    <p><?=tr('Items and prices are checked again before your order is placed.','অর্ডার দেওয়ার আগে পণ্য ও দাম আবার যাচাই করা হবে।')?></p></div>
  <?php if($isBag && !empty($_SESSION['bag_updated_notice'])):unset($_SESSION['bag_updated_notice']);?>
    <p class="bag-status" role="status"><?=tr('Your bag has been updated.','আপনার ব্যাগ আপডেট হয়েছে।')?></p>
  <?php endif;?>
  <?php if(!$items):?>
    <div class="bag-empty"><div class="bag-empty-icon" aria-hidden="true">♡</div>
      <h2><?=tr('Your bag is waiting.','আপনার ব্যাগ এখন খালি।')?></h2>
      <p><?=tr('Discover shoes and accessories that fit your everyday.','আপনার দৈনন্দিন পছন্দের জুতা ও অ্যাকসেসরিজ দেখুন।')?></p>
      <a class="btn" href="store.php?page=shop"><?=tr('Explore the collection','কালেকশন দেখুন')?></a>
    </div>
  <?php else:?>
  <div class="bag-layout">
    <div class="bag-lines" aria-label="<?=tr('Selected items','বেছে নেওয়া পণ্য')?>">
    <?php foreach($items as $variantId=>$quantity):
        $variantId=(int)$variantId;$quantity=(int)$quantity;
        $found=$variants[$variantId]??null;
        $available=$found?(int)$found[1]['available']:0;
    ?>
    <article class="bag-item<?=(!$found||$quantity>$available)?' bag-item-unavailable':''?>">
    <?php if($found):[$product,$variant]=$found;?>
      <a href="<?=esc(Aelvo\PublicRoutes::localUrl(Aelvo\PublicRoutes::productPath($product['slug'])))?>" class="bag-item-image" aria-label="<?=esc(nameOf($product))?>">
        <img src="<?=esc(photo($product,$variant['colour']))?>" alt="<?=esc(photoAlt($product,$variant['colour']))?>" loading="lazy">
      </a>
      <div class="bag-item-details">
        <a class="bag-item-name" href="<?=esc(Aelvo\PublicRoutes::localUrl(Aelvo\PublicRoutes::productPath($product['slug'])))?>"><?=esc(nameOf($product))?></a>
        <p><?=esc($variant['colour'].' / '.$variant['size'])?></p>
        <?php if($available<$quantity):?><p class="bag-stock-alert" role="status"><?=tr('Only','মাত্র')?> <?=max(0,$available)?> <?=tr('available. Lower the quantity or remove the item.','টি আছে। পরিমাণ কমান বা পণ্য সরান।')?></p><?php else:?><p class="bag-stock-note"><?=tr('In stock','স্টকে আছে')?></p><?php endif;?>
        <?php if($isBag):?>
          <div class="bag-item-controls">
            <?php if($available>0):?><form method="post" class="bag-quantity-form">
              <input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>">
              <input type="hidden" name="action" value="bag-quantity">
              <input type="hidden" name="variant_id" value="<?=$variantId?>">
              <label for="bag-quantity-<?=$variantId?>"><?=tr('Qty','পরিমাণ')?></label>
              <input id="bag-quantity-<?=$variantId?>" name="qty" type="number" inputmode="numeric" min="1" max="<?=min(100,$available)?>" value="<?=$quantity?>" required>
              <button type="submit" class="btn secondary small"><?=tr('Update','আপডেট')?></button>
            </form><?php endif;?>
            <form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><input type="hidden" name="variant_id" value="<?=$variantId?>"><button class="bag-remove" type="submit" name="action" value="remove"><?=tr('Remove','সরিয়ে দিন')?></button></form>
          </div>
        <?php endif;?>
      </div>
      <strong class="bag-item-price">৳<?=number_format((int)$variant['price_minor']*$quantity/100,2)?></strong>
    <?php else:?>
      <div class="bag-item-details"><h2><?=tr('Item currently unavailable','পণ্যটি এখন পাওয়া যাচ্ছে না')?></h2><p><?=tr('Remove it to continue.','এগোতে পণ্যটি সরিয়ে দিন।')?></p></div>
      <?php if($isBag):?><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><input type="hidden" name="variant_id" value="<?=$variantId?>"><button type="submit" class="btn secondary small" name="action" value="remove"><?=tr('Remove','সরিয়ে দিন')?></button></form><?php endif;?>
    <?php endif;?></article>
    <?php endforeach;?>
    <?php if($hasUnavailable):?><p class="bag-stock-alert" role="alert"><?=tr('Review unavailable items before continuing.','এগোনোর আগে অপ্রাপ্য পণ্যগুলো ঠিক করুন।')?></p><?php endif;?>
    <?php if($isBag):?><a class="text-link" href="store.php?page=shop">← <?=tr('Continue shopping','আরও পণ্য দেখুন')?></a><?php endif;?>
    </div>
    <aside class="bag-summary" aria-label="<?=tr('Order summary','অর্ডারের সারাংশ')?>">
      <h2><?=tr('Order summary','অর্ডারের সারাংশ')?></h2>
      <p class="bag-summary-row"><span><?=tr('Items','পণ্য')?> (<?=$itemCount?>)</span><strong>৳<?=number_format($subtotal/100,2)?></strong></p>
      <p class="bag-summary-row"><span><?=tr('Delivery & discounts','ডেলিভারি ও ছাড়')?></span><span><?=tr('Calculated at checkout','চেকআউটে হিসাব হবে')?></span></p>
      <div class="bag-summary-total"><span><?=tr('Current subtotal','বর্তমান সাবটোটাল')?></span><strong>৳<?=number_format($subtotal/100,2)?></strong></div>
      <?php if($isBag):?>
        <?php if(!$hasUnavailable):?><a class="btn full" href="store.php?page=checkout"><?=tr('Proceed to checkout','চেকআউটে যান')?></a>
        <?php else:?><p class="bag-stock-alert"><?=tr('Update or remove unavailable items to continue.','এগোতে অপ্রাপ্য পণ্যের পরিমাণ বদলান বা সরান।')?></p><?php endif;?>
      <?php else:?>
        <?php $delivery=Aelvo\Settings::get($pdo,'delivery')['value'];?>
        <?php if($hasUnavailable):?><p class="bag-stock-alert"><?=tr('Some items are no longer available in the selected quantities.','কিছু পণ্য নির্বাচিত পরিমাণে আর পাওয়া যাচ্ছে না।')?></p>
          <a href="store.php?page=cart" class="btn secondary full"><?=tr('Review your bag','ব্যাগ দেখুন')?></a>
        <?php elseif(($delivery['cod_enabled']??false)===true):?>
          <form method="post" class="checkout-form bag-checkout-form"><input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><input type="hidden" name="action" value="quote">
            <label><?=tr('Full name','পুরো নাম')?><input name="customer_name" autocomplete="name" maxlength="120" value="<?=esc($_SESSION['checkout_form']['customer_name']??$customer['name']??'')?>" required></label>
            <label><?=tr('Mobile number','মোবাইল নম্বর')?><input name="phone" type="tel" autocomplete="tel" value="<?=esc($_SESSION['checkout_form']['phone']??$customer['phone']??'')?>" required></label>
            <label><?=tr('Complete delivery address','সম্পূর্ণ ডেলিভারি ঠিকানা')?><textarea name="address" autocomplete="street-address" maxlength="2000" required><?=esc($_SESSION['checkout_form']['address']??'')?></textarea></label>
            <label><?=tr('Delivery area','ডেলিভারি এলাকা')?><select name="zone" required><option value="dhaka"><?=tr('Dhaka city','ঢাকা শহর')?> — <?=tr('Base rate','মূল রেট')?> ৳<?=number_format(($delivery['dhaka_minor']??0)/100,2)?></option><option value="outside" <?=($_SESSION['checkout_form']['zone']??'')==='outside'?'selected':''?>><?=tr('Outside Dhaka','ঢাকার বাইরে')?> — <?=tr('Base rate','মূল রেট')?> ৳<?=number_format(($delivery['outside_minor']??0)/100,2)?></option></select></label>
            <?php if(!empty($delivery['value_tiers'])||!empty($delivery['free_threshold_minor'])):?>
              <p class="bag-stock-note"><?=tr('Shipping may be reduced by order-value rules. Final shipping and any coupon discount are recalculated on the next screen.','অর্ডারের দামের ওপর ডেলিভারি চার্জ কমতে পারে। পরের ধাপে চূড়ান্ত চার্জ ও কুপনের ছাড় দেখানো হবে।')?></p>
            <?php endif;?>
            <label><?=tr('Coupon (optional)','কুপন (ঐচ্ছিক)')?><input name="coupon" maxlength="40" value="<?=esc($_SESSION['checkout_form']['coupon']??'')?>"></label>
            <p><?=tr('Review the final total and verify your phone before confirming.','অর্ডার নিশ্চিত করার আগে মোট দাম দেখুন এবং ফোন যাচাই করুন।')?></p><button type="submit" class="btn full"><?=tr('Review order','অর্ডার দেখুন')?></button>
          </form>
        <?php else:?><p class="notice"><?=tr('Online ordering is currently closed. Your bag is saved.','অনলাইন অর্ডার আপাতত বন্ধ। আপনার ব্যাগ রাখা হয়েছে।')?></p><?php endif;?>
      <?php endif;?>
    </aside>
  </div>
  <?php endif;?>
</section>
