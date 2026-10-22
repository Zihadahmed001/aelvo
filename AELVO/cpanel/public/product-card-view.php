<?php
// Shared card for Home, Shop, taxonomy pages, related products and Wishlist.
if(!isset($p,$pdo) || !is_array($p)){http_response_code(404);exit;}
$productHref=Aelvo\PublicRoutes::localUrl(Aelvo\PublicRoutes::productPath((string)$p['slug']));
$availableUnits=array_sum(array_map(static fn(array $v):int=>max(0,(int)$v['available']),$p['variants']));
$onSale=!empty($p['sale_price_minor']) && array_reduce($p['variants'],static fn(bool $found,array $v):bool=>$found || ((int)($v['available']??0)>0 && ($v['price_override_minor']??null)===null),false);
?>
<article class="product-card aelvo-product-card">
  <a class="product-card-main" href="<?=esc($productHref)?>" aria-label="<?=esc(tr('View','দেখুন').' '.nameOf($p))?>">
    <div class="product-image"><img loading="lazy" src="<?=esc(photo($p))?>" alt="<?=esc(photoAlt($p))?>">
      <?php if($availableUnits===0):?><span class="product-stock-tag"><?=tr('Out of stock','স্টকে নেই')?></span><?php endif;?>
      <?php if(!empty($p['is_new_arrival'])):?><span class="product-new-tag"><?=tr('New arrival','নতুন এসেছে')?></span><?php elseif(!empty($p['is_featured'])):?><span class="product-new-tag"><?=tr('Featured','বিশেষ বাছাই')?></span><?php endif;?>
    </div>
    <div class="product-info"><div class="product-card-title"><strong><?=esc(nameOf($p))?></strong><p><?=esc($p['category'])?></p></div><strong class="product-card-price"><?=($p['variant_price_range']['min']??$p['price_minor'])!==($p['variant_price_range']['max']??$p['price_minor'])?tr('From','শুরু'):''?> ৳<?=number_format((int)($p['variant_price_range']['min']??$p['price_minor'])/100,2)?></strong><?php if($onSale):?><span class="product-sale-label"><?=tr('Sale available on selected options','কিছু অপশনে ছাড় আছে')?></span><?php endif;?></div>
  </a>
  <div class="card-buy"><a class="btn secondary full" href="<?=esc($productHref)?>"><?=tr('View product','পণ্য দেখুন')?></a></div>
  <?php wishlistButton((int)$p['id']);?>
</article>
