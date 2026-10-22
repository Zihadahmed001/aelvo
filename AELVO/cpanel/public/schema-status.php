<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
Aelvo\Security::start((bool)$config['secure_cookies']);
header('X-Robots-Tag: noindex, nofollow');
$actor=Aelvo\Security::actor($pdo);
if(!$actor || ($actor['is_owner']??false)!==true){http_response_code(403);exit('Access denied.');}
$plan=null;$error='';
try{$plan=Aelvo\Schema::plan($pdo);}catch(Throwable $e){$error=$e->getMessage();}
function esc(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AELVO | Schema status</title><link rel="stylesheet" href="backend.css"></head><body><main class="admin-access-main">
<a class="brand" href="index.php?page=settings">AELVO</a><h1>Database schema status</h1>
<p>Read-only Owner diagnostics. This page never applies migrations, edits records or creates a backup.</p>
<?php if($error!==''):?><p role="alert" class="error"><?=esc($error)?></p><p>Stop and inspect a verified restorable database backup before changing this installation. Old databases with no migration ledger require a separate audited conversion; do not reinstall over their data.</p>
<?php elseif($plan):?>
<p>State: <strong><?=esc($plan['mode'])?></strong> · Applied: <?=count($plan['applied'])?> · Pending: <?=count($plan['pending'])?> · Bundled SQL files: <?=count(Aelvo\Schema::FILES)?></p>
<?php if($plan['pending']):?><p>Do not upgrade a populated database without an independently verified restorable backup and isolated staging checks. A checksum-matched migration runner is available for later controlled releases, but its presence does not establish production readiness.</p>
<?php else:?><p>The bundled SQL manifest matches the recorded applied migrations. This does not check table contents, runtime integrations or database health.</p><?php endif;endif;?>
<a href="upgrade-review.php">Owner upgrade preflight</a> · <a href="index.php?page=settings">Back to Admin settings</a></main></body></html>
