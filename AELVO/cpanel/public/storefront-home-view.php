<?php
/** Approved dist storefront homepage, rendered exclusively from published catalogue data. */
if (!isset($navigation, $products, $pdo)) { http_response_code(500); return; }
$bagsPath='store.php?page=shop';
foreach ($navigation['categories'] as $root) {
    if (($root['root_code']??'')==='Bags') {
        $bagsPath=Aelvo\PublicRoutes::localUrl($root['item']['path']);
        break;
    }
}
$aboutPublished=Aelvo\Content::active(Aelvo\Content::get($pdo,'about')['value']);
?>
<?php if($navigation['categories']): ?>
<nav class="home-categories" aria-label="<?=tr('Shop by category','ক্যাটাগরি অনুযায়ী পণ্য দেখুন')?>">
 <div class="category-row">
  <a class="category-pill" href="store.php?page=shop"><?=tr('All essentials','সব পণ্য')?></a>
  <?php foreach ($navigation['categories'] as $root): $category=$root['item']; ?>
  <a class="category-pill" href="<?=esc(Aelvo\PublicRoutes::localUrl($category['path']))?>"><?=esc($lang==='bn' && $category['name_bn']!==''?$category['name_bn']:$category['name'])?></a>
  <?php endforeach; ?>
 </div>
</nav>
<?php endif; ?>
<section class="section home-featured" aria-labelledby="home-featured-heading">
 <div class="section-heading">
  <div><p class="eyebrow"><?=tr('The edit','আমাদের বাছাই')?></p><h2 id="home-featured-heading"><?=tr('Everyday favourites.','প্রতিদিনের পছন্দ।')?></h2></div>
  <a class="text-link" href="store.php?page=shop"><?=tr('View all pieces','সব পণ্য দেখুন')?></a>
 </div>
 <div class="product-grid">
 <?php foreach ($products as $product) card($product); ?>
 <?php if (!$products): ?><p class="home-empty-products"><?=tr('The collection is being prepared. Please check back soon.','কালেকশন প্রস্তুত হচ্ছে। আবার দেখুন।')?></p><?php endif; ?>
 </div>
</section>
<section class="editorial home-editorial" aria-labelledby="home-editorial-heading">
 <img src="assets/bag.png" alt="<?=tr('Black everyday tote bag','কালো টোট ব্যাগ')?>" loading="lazy" decoding="async">
 <div class="editorial-copy">
  <span class="eyebrow"><?=tr('Less, but considered','সাদামাটা, কিন্তু যত্নে বাছাই')?></span>
  <h2 id="home-editorial-heading"><?=tr('Carry what matters.','প্রয়োজনীয় সবকিছু সঙ্গে রাখুন।')?></h2>
  <p><?=tr('Thoughtful shapes. Easy combinations. The finishing details of your everyday wardrobe.','পরিপাটি নকশা, সহজে মানিয়ে নেওয়া রঙ আর প্রতিদিনের পোশাকের সঙ্গে মানানসই অ্যাকসেসরিজ।')?></p>
  <a class="text-link" href="<?=esc($bagsPath)?>"><?=tr('Discover bags','ব্যাগ দেখুন')?></a>
 </div>
</section>
<section class="section home-notes" aria-labelledby="home-notes-heading">
 <div class="section-heading">
  <div><p class="eyebrow"><?=tr('AELVO notes','AELVO-এর কথা')?></p><h2 id="home-notes-heading"><?=tr('Good style starts with you.','নিজের পছন্দেই গড়ে ওঠে সুন্দর স্টাইল।')?></h2></div>
  <?php if($aboutPublished): ?><a class="text-link" href="store.php?page=info&amp;key=about"><?=tr('Our story','আমাদের গল্প')?></a><?php endif; ?>
 </div>
</section>
