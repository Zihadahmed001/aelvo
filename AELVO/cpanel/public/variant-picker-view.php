<?php
// Presentation controls use the real active variant IDs; the select remains
// the server-validated input and the no-JavaScript fallback.
if(!isset($p) || !is_array($p)){http_response_code(404);exit;}
$colours=[];$sizes=[];
foreach($p['variants'] as $variant){
    $colour=(string)$variant['colour'];$size=(string)$variant['size'];
    if(!array_key_exists($colour,$colours))$colours[$colour]=(string)$variant['colour_hex'];
    $sizes[$size]=true;
}
?>
<div class="variant-picker" data-variant-picker hidden>
  <fieldset class="variant-picker-field"><legend><?=tr('Colour','রং')?>: <strong data-selected-colour>—</strong></legend>
    <div class="colour-swatches" role="group" aria-label="<?=tr('Choose colour','রং বেছে নিন')?>">
      <?php foreach($colours as $colour=>$hex):$validHex=preg_match('/^#[0-9a-f]{6}$/iD',$hex)?$hex:'#d3dcd5';?>
        <button type="button" class="colour-option" data-pick-colour="<?=esc($colour)?>" aria-pressed="false">
          <svg class="variant-colour-circle" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="<?=esc($validHex)?>" stroke="#8ba194" stroke-width="1.3"/></svg>
          <span><?=esc($colour)?></span>
        </button>
      <?php endforeach;?>
    </div>
  </fieldset>
  <fieldset class="variant-picker-field"><legend><?=tr('Size','সাইজ')?>: <strong data-selected-size>—</strong></legend>
    <div class="size-list" role="group" aria-label="<?=tr('Choose size','সাইজ বেছে নিন')?>">
    <?php foreach(array_keys($sizes) as $size):?><button type="button" class="size-btn" data-pick-size="<?=esc($size)?>" aria-pressed="false"><?=esc($size)?></button><?php endforeach;?>
    </div>
  </fieldset>
  <p class="variant-available" aria-live="polite"><span data-variant-available></span> <?=tr('available','টি আছে')?></p>
  <p class="variant-extra" data-variant-extra hidden aria-live="polite"></p>
</div>
<label class="native-variant-select"><?=tr('Colour / Size','রং / সাইজ')?><select name="variant_id" id="variant-select" required>
  <option value=""><?=tr('Choose your variant','ভ্যারিয়েন্ট বেছে নিন')?></option>
  <?php foreach($p['variants'] as $variant):?>
  <option value="<?=(int)$variant['id']?>" data-photo="<?=esc(photo($p,$variant['colour']))?>" data-colour="<?=esc($variant['colour'])?>" data-size="<?=esc($variant['size'])?>" data-material="<?=esc($variant['material']??'')?>" data-style="<?=esc($variant['style']??'')?>" data-available="<?=(int)$variant['available']?>" data-price-minor="<?=(int)($variant['price_minor']??$p['price_minor']??0)?>" data-regular-price-minor="<?=(int)($variant['regular_price_minor']??$variant['price_minor']??$p['price_minor']??0)?>" <?=(int)$variant['available']<1?'disabled':''?>><?=esc($variant['colour'].' / '.$variant['size'].(!empty($variant['material'])?' / '.$variant['material']:'').(!empty($variant['style'])?' / '.$variant['style']:''))?> — <?=(int)$variant['available']?> <?=tr('available','টি আছে')?> · ৳<?=number_format((int)($variant['price_minor']??$p['price_minor']??0)/100,2)?></option>
  <?php endforeach;?>
</select></label>
