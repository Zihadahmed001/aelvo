<?php
// Shared bilingual 404 screen, backed only by actual public catalogue records.
if (!isset($page) || $page !== 'not-found') { http_response_code(404); exit; }
$missingCategories=[];
foreach($products as $candidate) {
    $label=(string)($candidate['category']??'');
    if($label!=='' && !in_array($label,$missingCategories,true))$missingCategories[]=$label;
    if(count($missingCategories)>=4)break;
}
?>
<section class="page missing-page" aria-labelledby="missing-title">
 <p class="eyebrow">404 · <?=tr('Not found','পাওয়া যায়নি')?></p>
 <h1 id="missing-title"><?=tr('This page is not available.','এই পেজটি পাওয়া যাচ্ছে না।')?></h1>
 <p><?=tr('The link may be outdated. Search our collection or return to the store.','লিংকটি পুরোনো হতে পারে। পণ্য খুঁজুন অথবা হোমপেজে ফিরে যান।')?></p>
 <form method="get" action="store.php" role="search" class="missing-search">
  <input type="hidden" name="page" value="shop">
  <label for="missing-query"><?=tr('Search AELVO','AELVO-তে খুঁজুন')?></label>
  <input id="missing-query" name="q" maxlength="100" placeholder="<?=tr('Shoes, bags, accessories…','জুতা, ব্যাগ, অ্যাকসেসরিজ…')?>">
  <button class="btn" type="submit"><?=tr('Search','খুঁজুন')?></button>
 </form>
 <p><a class="btn secondary" href="store.php"><?=tr('Back to homepage','হোমপেজে ফিরে যান')?></a> <a href="store.php?page=shop"><?=tr('Shop all','সব পণ্য')?></a></p>
 <?php if($missingCategories): ?><h2><?=tr('Browse categories','ক্যাটাগরি দেখুন')?></h2><nav class="missing-links" aria-label="<?=tr('Available categories','উপলব্ধ ক্যাটাগরি')?>">
 <?php foreach($missingCategories as $category): ?><a href="store.php?<?=esc(http_build_query(['page'=>'shop','category'=>$category]))?>"><?=esc($category)?></a><?php endforeach; ?></nav><?php endif; ?>
 <?php if($products): ?><h2><?=tr('Explore the collection','কালেকশন দেখুন')?></h2><div class="product-grid"><?php foreach(array_slice($products,0,3) as $product)card($product); ?></div><?php endif; ?>
</section>
