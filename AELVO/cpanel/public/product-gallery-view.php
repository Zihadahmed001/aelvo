<?php
// All image records originate from the active server-side catalogue query.
// Colour filters are presentation only; stock/variant validation stays server-side.
if (!isset($p) || !is_array($p)) { http_response_code(404); exit; }
$galleryImages=[];
foreach ($p['images'] as $media) {
    $mediaId=(string)($media['media_id']??'');
    if (preg_match('/^[a-f0-9]{32}$/D',$mediaId) && !isset($galleryImages[$mediaId])) {
        $galleryImages[$mediaId]=[
            'src'=>'media.php?id='.$mediaId,
            'alt'=>(($lang==='bn' && ($media['alt_bn']??'')!=='') ? $media['alt_bn'] : ($media['alt_en']??'')) ?: nameOf($p),
            'colour'=>(string)($media['colour']??''),
        ];
    }
}
if (!$galleryImages) $galleryImages=[0=>['src'=>'assets/placeholder.svg','alt'=>nameOf($p),'colour'=>'']];
$firstGalleryImage=reset($galleryImages);
?>
<div class="detail-gallery" data-product-gallery>
  <figure class="detail-photo">
    <img id="product-photo" src="<?=esc($firstGalleryImage['src'])?>" alt="<?=esc($firstGalleryImage['alt'])?>" loading="eager" fetchpriority="high">
    <figcaption class="visually-hidden"><?=tr('Product photograph','পণ্যের ছবি')?></figcaption>
  </figure>
  <?php if (count($galleryImages)>1): ?>
  <div class="gallery-thumbs" role="group" aria-label="<?=tr('Product photographs','পণ্যের ছবিগুলো')?>">
    <?php foreach ($galleryImages as $media): ?>
    <button class="gallery-thumb" type="button" data-gallery-photo data-src="<?=esc($media['src'])?>" data-alt="<?=esc($media['alt'])?>" data-colour="<?=esc($media['colour'])?>" aria-pressed="false" aria-label="<?=esc($media['alt'])?>">
      <img src="<?=esc($media['src'])?>" alt="" loading="lazy">
    </button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
