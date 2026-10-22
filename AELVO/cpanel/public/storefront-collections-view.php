<?php if(!isset($navigation, $page)) { http_response_code(404); exit; } ?>
<?php if($navigation['categories'] || $navigation['brands']): ?>
<nav class="store-category-pills" aria-label="<?=tr('Explore the collection','কালেকশন দেখুন')?>">
  <a href="store.php?page=shop" <?=$page==='shop'?'aria-current="page"':''?>><?=tr('All products','সব পণ্য')?></a>
  <?php foreach ($navigation['categories'] as $root): $item=$root['item']; ?>
    <a href="<?=esc(Aelvo\PublicRoutes::localUrl($item['path']))?>" <?=($page==='category' && (int)($taxonomyPage['id']??0)===$item['id'])?'aria-current="page"':''?>><?=esc($lang==='bn'&&$item['name_bn']!==''?$item['name_bn']:$item['name'])?></a>
  <?php endforeach; ?>
  <?php if($navigation['brands']): ?><a href="#available-brands"><?=tr('Browse brands','ব্র্যান্ড দেখুন')?></a><?php endif; ?>
</nav>
<?php if($navigation['brands']): ?>
<details class="store-brand-selector" id="available-brands"><summary><?=tr('Shop by brand','ব্র্যান্ড অনুযায়ী শপ')?></summary>
<div class="store-brand-pills"><?php foreach ($navigation['brands'] as $brand): ?><a href="<?=esc(Aelvo\PublicRoutes::localUrl($brand['path']))?>"><?=esc($lang==='bn'&&$brand['name_bn']!==''?$brand['name_bn']:$brand['name'])?></a><?php endforeach; ?></div></details>
<?php endif; ?>
<?php endif; ?>
