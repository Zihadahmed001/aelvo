<?php
if (!isset($pdo, $page, $navigation, $languageHref)) { http_response_code(404); exit; }
$shopSelected = in_array($page, ['shop', 'category', 'brand'], true);
?>
<a href="#store-main" class="store-skip"><?=tr('Skip to content', 'মূল অংশে যান')?></a>
<header class="store-header">
  <a class="wordmark" href="./" aria-label="AELVO Fashion — <?=tr('Home','হোম')?>">AELVO<small>FASHION</small></a>
  <nav class="store-nav" aria-label="<?=tr('Browse collections','কালেকশন দেখুন')?>">
    <a href="store.php?page=shop" <?=$shopSelected?'aria-current="page"':''?>><?=tr('Shop all', 'সব পণ্য')?></a>
    <?php foreach (array_slice($navigation['categories'], 0, 5) as $root): $item = $root['item']; ?>
      <a href="<?=esc(Aelvo\PublicRoutes::localUrl($item['path']))?>" <?=($page==='category' && (int)($taxonomyPage['id']??0)===$item['id'])?'aria-current="page"':''?>><?=esc($lang==='bn'&&$item['name_bn']!==''?$item['name_bn']:$item['name'])?></a>
    <?php endforeach; ?>
  </nav>
  <div class="header-actions">
    <?php require __DIR__.'/search-view.php'; ?>
    <a href="<?=esc($languageHref)?>" hreflang="<?=$lang==='en'?'bn':'en'?>" aria-label="<?=tr('Switch to Bengali', 'Switch to English')?>"><?=$lang==='en'?'বাংলা':'English'?></a>
    <a href="store.php?page=wishlist"><?=tr('Wishlist','পছন্দ')?></a>
    <a href="store.php?page=account"><?=tr('Account','অ্যাকাউন্ট')?></a>
    <a href="store.php?page=cart"><?=tr('Bag','ব্যাগ')?> (<?=array_sum($_SESSION['bag'])?>)</a>
  </div>
</header>
<?php if ($navigation['categories'] || $navigation['brands']): ?>
<details class="store-collections" data-collections>
  <summary><?=tr('Explore collections & brands','কালেকশন ও ব্র্যান্ড দেখুন')?> <span aria-hidden="true">⌄</span></summary>
  <div class="store-collections-panel">
    <?php if ($navigation['categories']): ?>
      <section><h2><?=tr('Categories','ক্যাটাগরি')?></h2>
        <div class="collection-menu-grid">
          <?php foreach ($navigation['categories'] as $root): $item = $root['item']; ?>
          <div class="collection-menu-group">
            <a class="collection-menu-root" href="<?=esc(Aelvo\PublicRoutes::localUrl($item['path']))?>"><?=esc($lang==='bn'&&$item['name_bn']!==''?$item['name_bn']:$item['name'])?></a>
            <?php foreach($root['children'] as $child): ?><a href="<?=esc(Aelvo\PublicRoutes::localUrl($child['path']))?>"><?=esc($lang==='bn'&&$child['name_bn']!==''?$child['name_bn']:$child['name'])?></a><?php endforeach; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
    <?php if($navigation['brands']): ?>
    <section><h2><?=tr('Brands', 'ব্র্যান্ড')?></h2><div class="collection-brand-grid">
      <?php foreach ($navigation['brands'] as $brand): ?><a href="<?=esc(Aelvo\PublicRoutes::localUrl($brand['path']))?>"><?=esc($lang==='bn'&&$brand['name_bn']!==''?$brand['name_bn']:$brand['name'])?></a><?php endforeach; ?>
    </div></section>
    <?php endif; ?>
  </div>
</details>
<?php endif; ?>
