<?php
/** Published-only storefront footer based on the approved four-column composition. */
if (!isset($pdo, $navigation, $lang)) { http_response_code(500); return; }
$policyLabels=[
 'about'=>['About','আমাদের সম্পর্কে'],
 'delivery-returns'=>['Delivery & returns','ডেলিভারি ও রিটার্ন'],
 'privacy'=>['Privacy','গোপনীয়তা'],
 'terms'=>['Terms','শর্তাবলি'],
];
$footerPolicies=[];
foreach ($policyLabels as $key=>$labels) {
    if (Aelvo\Content::active(Aelvo\Content::get($pdo,$key)['value'])) {
        $footerPolicies[]=['key'=>$key,'label'=>$lang==='bn'?$labels[1]:$labels[0]];
    }
}
$footerCms=Aelvo\CmsPages::navigation($pdo,12);
?>
<footer class="store-footer" aria-label="<?=tr('Store information','স্টোরের তথ্য')?>">
 <div class="footer-grid">
  <div class="footer-brand">
   <a class="wordmark" href="./" aria-label="AELVO Fashion — <?=tr('Home','হোম')?>">AELVO<small>FASHION</small></a>
   <p><?=tr('Considered shoes and accessories for your everyday wardrobe.','আপনার প্রতিদিনের পোশাকের জন্য যত্নে বাছাই করা জুতা ও অ্যাকসেসরিজ।')?></p>
  </div>
  <nav class="footer-column" aria-label="<?=tr('Shop','শপ')?>">
   <h2><?=tr('Shop','শপ')?></h2>
   <a href="store.php?page=shop"><?=tr('All essentials','সব পণ্য')?></a>
   <?php foreach($navigation['categories'] as $root): $category=$root['item']; ?>
    <a href="<?=esc(Aelvo\PublicRoutes::localUrl($category['path']))?>"><?=esc($lang==='bn'&&$category['name_bn']!==''?$category['name_bn']:$category['name'])?></a>
   <?php endforeach; ?>
  </nav>
  <nav class="footer-column" aria-label="<?=tr('Customer care','গ্রাহক সেবা')?>">
   <h2><?=tr('Customer care','গ্রাহক সেবা')?></h2>
   <a href="store.php?page=tracking"><?=tr('Track order','অর্ডার ট্র্যাক করুন')?></a>
   <a href="store.php?page=chat"><?=tr('Chat support','চ্যাট সাপোর্ট')?></a>
   <a href="store.php?page=support"><?=tr('Help & contact','সাহায্য ও যোগাযোগ')?></a>
   <a href="store.php?page=account"><?=tr('Your account','আপনার অ্যাকাউন্ট')?></a>
  </nav>
  <?php if($footerPolicies || $footerCms): ?>
  <nav class="footer-column" aria-label="<?=tr('Information','তথ্য')?>">
   <h2><?=tr('Information','তথ্য')?></h2>
   <?php foreach($footerPolicies as $policy): ?>
    <a href="store.php?page=info&amp;key=<?=esc($policy['key'])?>"><?=esc($policy['label'])?></a>
   <?php endforeach; ?>
   <?php foreach($footerCms as $infoPage): $value=$infoPage['value']; ?>
    <a href="<?=esc(Aelvo\PublicRoutes::localUrl(Aelvo\CmsPages::path($infoPage['slug'])))?>"><?=esc($lang==='bn'&&($value['heading_bn']??'')!==''?$value['heading_bn']:$value['heading'])?></a>
   <?php endforeach; ?>
  </nav>
  <?php endif; ?>
 </div>
 <div class="footer-bottom"><span>© <?=date('Y')?> AELVO Fashion</span><span><?=tr('Made for everyday life · Bangladesh','প্রতিদিনের জীবনের জন্য · বাংলাদেশ')?></span></div>
</footer>
