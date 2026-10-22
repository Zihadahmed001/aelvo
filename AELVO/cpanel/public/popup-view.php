<?php if(!isset($pdo,$page)||!in_array($page,['home','shop'],true))return;
$popupRecord=Aelvo\Content::get($pdo,'popup');$popup=$popupRecord['value'];if(!Aelvo\Content::active($popup))return;?>
<dialog class="welcome-popup" data-welcome-popup data-revision="<?=(int)$popupRecord['published_version']?>" data-delay="<?=(int)$popup['delay']?>" aria-labelledby="welcome-title"><form method="dialog"><button class="btn secondary small popup-close" aria-label="<?=tr('Close popup','পপআপ বন্ধ করুন')?>">×</button></form>
<?php if($popup['image']):?><img src="media.php?id=<?=esc($popup['image'])?>" alt=""><?php endif;?>
<h2 id="welcome-title"><?=esc(contentText($popup,'heading'))?></h2><p class="preserve-lines"><?=esc(contentText($popup,'subtitle'))?></p><div class="buy-row"><?php contentButtons($popup);?></div></dialog>
