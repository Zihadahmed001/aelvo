<?php
if(!isset($pdo,$page)){http_response_code(404);exit;}
function contentText(array $v,string $key): string{global $lang;return $lang==='bn'&&!empty($v[$key.'_bn'])?$v[$key.'_bn']:($v[$key]??'');}
function contentButtons(array $v):void{foreach(['','2'] as $n)if(!empty($v['destination'.$n])&&contentText($v,'button'.$n)!=='')echo '<a class="btn '.($n?'secondary':'').'" href="'.esc($v['destination'.$n]).'">'.esc(contentText($v,'button'.$n)).'</a>';}
function renderHero(array $v):void{?>
<section class="hero custom-hero">
<?php if(!empty($v['image'])):?><img src="media.php?id=<?=esc($v['image'])?>" alt=""><?php endif;?>
<?php if(($v['media_type']??'')==='video'&&!empty($v['video'])):?><video class="hero-video" muted playsinline loop preload="none" poster="media.php?id=<?=esc($v['image'])?>" data-hero-video><source src="media.php?id=<?=esc($v['video'])?>"></video><button class="btn secondary video-toggle" type="button" data-video-toggle hidden><?=tr('Play video','ভিডিও চালান')?></button><?php endif;?>
<div class="hero-copy"><h1><?=esc(contentText($v,'heading'))?></h1><p class="preserve-lines"><?=esc(contentText($v,'subtitle'))?></p><div class="buy-row"><?php contentButtons($v);?></div></div><div class="hero-foot"><?=esc(contentText($v,'tagline'))?></div></section>
<?php }
if($page==='info'){
$key=is_string($_GET['key']??null)?$_GET['key']:'';$v=in_array($key,['about','delivery-returns','privacy','terms'],true)?Aelvo\Content::get($pdo,$key)['value']:[];
if(!Aelvo\Content::active($v)){http_response_code(404);echo '<section class="page"><h1>'.tr('Page not found','পেজ পাওয়া যায়নি').'</h1></section>';}else{echo '<section class="page"><h1>'.esc(contentText($v,'heading')).'</h1><p class="preserve-lines">'.esc(contentText($v,'body')).'</p></section>';}
}

if($page==='cms' && $cmsPage):$v=$cmsPage['value'];?>
<section class="page cms-page">
 <nav aria-label="<?=tr('Breadcrumb','পথ নির্দেশনা')?>"><a href="./"><?=tr('Home','হোম')?></a> / <?=esc(contentText($v,'heading'))?></nav>
 <h1><?=esc(contentText($v,'heading'))?></h1>
 <div class="preserve-lines"><?=esc(contentText($v,'body'))?></div>
 <div class="cms-reading"><?=Aelvo\CmsBlocks::render($v['blocks']??[],$lang)?></div>
</section>
<?php endif;?>
