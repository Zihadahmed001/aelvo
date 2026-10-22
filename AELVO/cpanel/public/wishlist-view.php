<?php if(!isset($customers,$pdo,$wishlistIds)){http_response_code(404);exit;} ?>
<section class="page"><h1><?=tr('Your wishlist','আপনার পছন্দের তালিকা')?></h1>
<?php if(!$customer):?><p><?=tr('Sign in to keep your wishlist across devices.','পছন্দের তালিকা সব ডিভাইসে রাখতে লগইন করুন।')?></p><a class="btn" href="store.php?page=account"><?=tr('Sign in','লগইন')?></a>
<?php else: $savedProducts=$wishlistIds?Aelvo\Catalogue::publicProducts($pdo,[],$wishlistIds):[];$savedMap=[];foreach($savedProducts as $saved)$savedMap[(int)$saved['id']]=$saved;?>
<?php if(!$wishlistIds):?><p><?=tr('Your wishlist is empty.','আপনার পছন্দের তালিকা খালি।')?></p><a class="btn" href="store.php?page=shop"><?=tr('Explore the collection','কালেকশন দেখুন')?></a><?php endif;?>
<div class="product-grid"><?php foreach($wishlistIds as $savedId){if(isset($savedMap[$savedId]))card($savedMap[$savedId]);else{echo '<article class="product-card"><p>'.tr('This saved product is currently unavailable.','এই পণ্যটি আপাতত পাওয়া যাচ্ছে না।').'</p>';wishlistButton($savedId);echo '</article>';}}?></div>
<?php endif;?></section>
