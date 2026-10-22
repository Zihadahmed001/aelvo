<?php
if(!isset($p,$pdo)){http_response_code(404);exit;}
$reviewSummary=Aelvo\Reviews::publishedSummary($pdo,(int)$p['id']);
$reviewPages=max(1,(int)ceil($reviewSummary['count']/Aelvo\Reviews::PUBLIC_PAGE_SIZE));
$requestedReviewPage=$_GET['rp']??'1';
$reviewPage=(is_string($requestedReviewPage) || is_int($requestedReviewPage)) && preg_match('/^[1-9][0-9]{0,4}$/D',(string)$requestedReviewPage)
    ?min($reviewPages,(int)$requestedReviewPage):1;
$reviews=Aelvo\Reviews::published($pdo,(int)$p['id'],$reviewPage);
$reviewHref=Aelvo\PublicRoutes::localUrl(Aelvo\PublicRoutes::productPath((string)$p['slug']));
?>
<section class="product-section" id="customer-reviews" aria-labelledby="review-heading"><h2 id="review-heading"><?=tr('Customer reviews','ক্রেতাদের রিভিউ')?></h2>
<?php if($reviewSummary['count']):?>
<div class="product-review-summary" aria-label="<?=esc(tr('Verified purchase review summary','যাচাইকৃত ক্রেতাদের রিভিউয়ের সারাংশ'))?>"><strong><?=number_format((float)$reviewSummary['average'],1)?> <span aria-hidden="true">★</span></strong><span><?=number_format($reviewSummary['count'])?> <?=tr('verified purchase reviews','যাচাইকৃত ক্রেতার রিভিউ')?></span></div>
<?php else:?><p><?=tr('No published reviews yet.','এখনও প্রকাশিত রিভিউ নেই।')?></p><?php endif;?>
<?php foreach($reviews as $review):?>
<article class="account-order review-card"><header class="review-card-head"><strong><?=esc($review['name'])?></strong><span aria-label="<?=(int)$review['rating']?> <?=tr('out of 5 stars','তারকার মধ্যে ৫')?>"><?=str_repeat('★',(int)$review['rating'])?><span class="star-unfilled"><?=str_repeat('☆',5-(int)$review['rating'])?></span></span></header><p class="review-verified"><?=tr('Verified purchase','যাচাইকৃত ক্রয়')?> · <?=esc(substr((string)$review['created_at'],0,10))?></p><p class="preserve-lines"><?=esc($review['body'])?></p>
<div class="review-photos"><?php foreach($review['photos'] as $photo):?><a href="review-photo.php?id=<?=esc($photo)?>" target="_blank" rel="noopener"><img loading="lazy" width="120" height="120" src="review-photo.php?id=<?=esc($photo)?>" alt="<?=tr('Customer product photo','ক্রেতার পণ্যের ছবি')?>"></a><?php endforeach;?></div>
<?php if($review['reply']!==''):?><blockquote class="review-store-reply"><strong>AELVO</strong><p class="preserve-lines"><?=esc($review['reply'])?></p></blockquote><?php endif;?></article>
<?php endforeach;?>
<?php if($reviewPages>1):?>
<nav class="product-review-pagination" aria-label="<?=tr('Review pages','রিভিউয়ের পৃষ্ঠা')?>">
<?php if($reviewPage>1):?><a class="btn secondary" href="<?=esc($reviewHref.'?rp='.($reviewPage-1).'#customer-reviews')?>"><?=tr('Newer reviews','নতুন রিভিউ')?></a><?php endif;?><span><?=tr('Page','পৃষ্ঠা')?> <?=$reviewPage?> / <?=$reviewPages?></span><?php if($reviewPage<$reviewPages):?><a class="btn secondary" href="<?=esc($reviewHref.'?rp='.($reviewPage+1).'#customer-reviews')?>"><?=tr('Older reviews','পুরোনো রিভিউ')?></a><?php endif;?>
</nav><?php endif;?>
<p><a href="store.php?page=account"><?=tr('Review a delivered order from your account','আপনার অ্যাকাউন্ট থেকে ডেলিভারিকৃত অর্ডারের রিভিউ দিন')?></a></p></section>
