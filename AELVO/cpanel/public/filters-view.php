<?php if(!isset($filters,$page,$navigation)){http_response_code(404);exit;}?>
<form method="get" class="filter-form storefront-filters">
  <input type="hidden" name="page" value="shop">
  <label><?=tr('Search','খুঁজুন')?><input name="q" maxlength="180" value="<?=esc($filters['q']??'')?>" placeholder="<?=tr('Product name','পণ্যের নাম')?>"></label>
  <label><?=tr('Category','ক্যাটাগরি')?><select name="category">
      <option value=""><?=tr('All categories','সব ক্যাটাগরি')?></option>
      <?php foreach($navigation['categories'] as $root):$item=$root['item'];$code=$root['root_code'];?>
      <option value="<?=esc($code)?>" <?=($filters['category']??'')===$code?'selected':''?>><?=esc($lang==='bn'&&$item['name_bn']!==''?$item['name_bn']:$item['name'])?></option>
      <?php endforeach;?>
  </select></label>
  <label><?=tr('Subcategory','সাবক্যাটাগরি')?><select name="category_id">
      <option value=""><?=tr('All subcategories','সব সাবক্যাটাগরি')?></option>
      <?php foreach($navigation['categories'] as $root): foreach($root['children'] as $child):?>
      <option value="<?=(int)$child['id']?>" <?=($filters['category_id']??'')===(string)$child['id']?'selected':''?>><?=esc(($lang==='bn'&&$root['item']['name_bn']!==''?$root['item']['name_bn']:$root['item']['name']).' / '.($lang==='bn'&&$child['name_bn']!==''?$child['name_bn']:$child['name']))?></option>
      <?php endforeach; endforeach;?>
  </select></label>
  <label><?=tr('Brand','ব্র্যান্ড')?><select name="brand_id">
      <option value=""><?=tr('All brands','সব ব্র্যান্ড')?></option>
      <?php foreach($navigation['brands'] as $brand):?>
      <option value="<?=(int)$brand['id']?>" <?=($filters['brand_id']??'')===(string)$brand['id']?'selected':''?>><?=esc($lang==='bn'&&$brand['name_bn']!==''?$brand['name_bn']:$brand['name'])?></option>
      <?php endforeach;?>
  </select></label>
  <label><?=tr('Minimum BDT','সর্বনিম্ন টাকা')?><input name="min" inputmode="decimal" value="<?=esc($filters['min']??'')?>"></label>
  <label><?=tr('Maximum BDT','সর্বোচ্চ টাকা')?><input name="max" inputmode="decimal" value="<?=esc($filters['max']??'')?>"></label>
  <label><?=tr('Sort','সাজান')?><select name="sort">
      <?php foreach(['newest'=>['Newest','নতুন আগে'],'price-low'=>['Price: low to high','দাম: কম থেকে বেশি'],'price-high'=>['Price: high to low','দাম: বেশি থেকে কম'],'name'=>['Name','নাম']] as $value=>$labels):?>
      <option value="<?=esc($value)?>" <?=($filters['sort']??'newest')===$value?'selected':''?>><?=tr($labels[0],$labels[1])?></option>
      <?php endforeach;?>
  </select></label>
  <label class="filter-stock"><input type="checkbox" name="stock" value="1" <?=($filters['stock']??'')==='1'?'checked':''?>><?=tr('In stock','স্টকে আছে')?></label>
  <div class="filter-controls"><button class="btn" type="submit"><?=tr('Apply filters','ফিল্টার করুন')?></button><a href="store.php?page=shop" class="btn secondary"><?=tr('Clear','মুছুন')?></a></div>
</form>
<p class="filter-results-count" role="status"><?=(int)$productCount?> <?=tr('products','টি পণ্য')?></p>
