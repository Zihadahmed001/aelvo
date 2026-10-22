<?php
$facts=$p['product_details']??[];
$short=$lang==='bn' && !empty($facts['short_bn'])?$facts['short_bn']:($facts['short_en']??'');
if($short!==''):?><p class="product-short-description"><?=esc($short)?></p><?php endif;?>
<?php
$material=$lang==='bn' && !empty($facts['material_bn'])?$facts['material_bn']:($facts['material_en']??'');
$care=$lang==='bn' && !empty($facts['care_bn'])?$facts['care_bn']:($facts['care_en']??'');
$items=[];
if($material!=='')$items[]=[tr('Material','উপাদান'),$material];
if($care!=='')$items[]=[tr('Care instructions','যত্নের নিয়ম'),$care];
if(!empty($facts['parent_sku']))$items[]=['SKU',(string)$facts['parent_sku']];
if(!empty($facts['barcode']))$items[]=[tr('Barcode','বারকোড'),(string)$facts['barcode']];
if(isset($facts['weight_grams']) && $facts['weight_grams']!==null)$items[]=[tr('Package weight','প্যাকেজের ওজন'),(int)$facts['weight_grams'].' g'];
if(isset($facts['length_mm'],$facts['width_mm'],$facts['height_mm']))$items[]=[tr('Dimensions (L × W × H)','মাত্রা (লম্বা × চওড়া × উচ্চতা)'),(int)$facts['length_mm'].' × '.(int)$facts['width_mm'].' × '.(int)$facts['height_mm'].' mm'];
if(!empty($facts['tags_csv']))$items[]=[tr('Tags','ট্যাগ'),(string)$facts['tags_csv']];
if($items):?><dl class="product-specifications"><?php foreach($items as [$label,$value]):?><div><dt><?=esc($label)?></dt><dd><?=esc($value)?></dd></div><?php endforeach;?></dl><?php endif;?>
