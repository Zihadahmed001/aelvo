<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Aelvo\Security;
Security::start((bool)$config['secure_cookies']);
header('X-Robots-Tag: noindex, nofollow');
function h(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function go(string $page): never { header('Location: ?page='.$page, true,303); exit; }
$page=is_string($_GET['page']??null)?$_GET['page']:'dashboard';
$error='';
$pages=['dashboard','products','inventory','orders','settings','finance','purchases','team','reviews','content','seo','coupons','payments','returns','support','reports','courier','owner-ai','activity'];
if(!in_array($page,$pages,true)) $page='dashboard'; $installed=(bool)$pdo->query('SELECT owner_id FROM aelvo_installation WHERE id=1')->fetchColumn();
$actor=Security::actor($pdo);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    Security::csrf();
    foreach ($_POST as $key=>$value) { if (!is_string($value) && !($key==='grants' && is_array($value)) && !($key==='blocks' && ($_POST['action']??'')==='cms-page-save' && is_array($value))) { http_response_code(400); exit('Invalid form input.'); } }
    $action=$_POST['action']??'';
    $ip=$_SERVER['REMOTE_ADDR']??'unknown';
    if ($action==='logout') {
        if ($actor) {
            $q=$pdo->prepare('INSERT INTO aelvo_audit(actor_id,event) VALUES (?,?)');
            $q->execute([(int)$actor['id'],'auth.logout']);
        }
        Security::logout(); go('login');
    }
    $operations=['return-request-review','return-restock','refund-propose','refund-review','return-receive','refund-record','payment-receive','payment-reject','payment-instructions','coupon-save','seo-save','seo-redirect-add','seo-redirect-toggle','seo-404-review','seo-product-save','seo-image-alt-save','taxonomy-save','review-moderate','content-save','content-publish','content-upload','cms-page-save','cms-page-publish','product-save','product-delete','variant-create','variant-price','variant-attributes','product-image','product-primary-image','inventory-adjust','quarantine-record','order-create','order-status','order-cancellation-review','theme-save','delivery-save','tax-save','provider-save','bulksmsbd-save','staff-create','staff-permissions','staff-disable','staff-enable','account-create','finance-post','purchase-create','purchase-receive','supplier-save','supplier-payment','support-update','chat-staff','customer-ai-save','steadfast-save','courier-book','courier-sync','courier-collect','courier-remit','courier-resolution-propose','courier-resolution-review','courier-exception-propose','courier-exception-review','courier-clearance-propose','courier-clearance-review','sslcommerz-save','gateway-payout-batch','gateway-exception-propose','gateway-exception-review','smtp-save','owner-email-request','owner-email-verify','owner-ai-request','owner-ai-verify','owner-ai-propose','owner-ai-approve','owner-ai-revoke'];
    if(in_array($action,$operations,true)) {
        if(!$actor) { http_response_code(401); exit('Sign in before making changes.'); }
        try {
            $catalogue=new Aelvo\Catalogue($pdo,$actor);
            switch($action) {
                case 'smtp-save': (new Aelvo\Settings($pdo,$actor))->smtp($_POST,$config['encryption_key']??'');go('settings');
                case 'owner-email-request':
                case 'owner-email-verify':
                case 'owner-ai-request':
                case 'owner-ai-verify':
                case 'owner-ai-propose':
                case 'owner-ai-approve':
                case 'owner-ai-revoke':
                    Aelvo\OwnerAiConsole::handle($pdo,$actor,$action,$_POST,$config['encryption_key']??'');go('owner-ai');

                case 'steadfast-save': (new Aelvo\Settings($pdo,$actor))->steadfast($_POST,$config['encryption_key']??'');go('settings');
                case 'sslcommerz-save': (new Aelvo\Settings($pdo,$actor))->sslCommerz($_POST,$config['encryption_key']??'');go('settings');
                case 'gateway-exception-propose':
                    $v=$_POST;
                    $v['document_id']=(new Aelvo\Uploads($pdo,$actor))->document($_FILES['evidence']??[],'payments');
                    (new Aelvo\GatewayExceptions($pdo,$actor))->propose($v);go('payments');
                case 'gateway-exception-review':
                    (new Aelvo\GatewayExceptions($pdo,$actor))->review($_POST);go('payments');
                case 'gateway-payout-batch':
                    $v=$_POST;
                    $v['document_id']=(new Aelvo\Uploads($pdo,$actor))->document($_FILES['document']??[],'payments');
                    (new Aelvo\GatewayPayoutBatches($pdo,$actor))->record($v);go('payments');
                case 'courier-book': (new Aelvo\Courier($pdo,$actor))->book($_POST,$config['encryption_key']??'');go('courier');
                case 'courier-collect':
                    $v=$_POST;$v['document_id']=(new Aelvo\Uploads($pdo,$actor))->document($_FILES['document']??[],'courier-collection');
                    (new Aelvo\CourierAccounting($pdo,$actor))->collect($v);go('courier');
                case 'courier-remit':
                    $v=$_POST;$v['document_id']=(new Aelvo\Uploads($pdo,$actor))->document($_FILES['document']??[],'courier-remittance');
                    (new Aelvo\CourierAccounting($pdo,$actor))->remit($v);go('courier');
                case 'courier-sync': (new Aelvo\Courier($pdo,$actor))->sync($_POST,$config['encryption_key']??'');go('courier');
                case 'courier-resolution-propose':
                    $v=$_POST;$v['document_id']=(new Aelvo\Uploads($pdo,$actor))->document($_FILES['evidence']??[],'courier-resolution');
                    (new Aelvo\CourierResolution($pdo,$actor))->propose($v);go('courier');
                case 'courier-resolution-review':
                    (new Aelvo\CourierResolution($pdo,$actor))->review($_POST);go('courier');
                case 'courier-exception-propose':
                    $v=$_POST;$v['document_id']=(new Aelvo\Uploads($pdo,$actor))->document($_FILES['evidence']??[],'courier-exceptions');
                    (new Aelvo\CourierExceptions($pdo,$actor))->propose($v);go('courier');
                case 'courier-exception-review':
                    (new Aelvo\CourierExceptions($pdo,$actor))->review($_POST);go('courier');
                case 'courier-clearance-propose':
                    $v=$_POST;$v['document_id']=(new Aelvo\Uploads($pdo,$actor))->document($_FILES['evidence']??[],'courier-exceptions');
                    (new Aelvo\CourierExceptions($pdo,$actor))->proposeClearance($v);go('courier');
                case 'courier-clearance-review':
                    (new Aelvo\CourierExceptions($pdo,$actor))->reviewClearance($_POST);go('courier');
                case 'customer-ai-save': (new Aelvo\Settings($pdo,$actor))->customerAi($_POST,$config['encryption_key']??'');go('settings');
                case 'chat-staff':
                    Aelvo\CustomerChat::staffAction($pdo,$actor,$_POST);
                    header('Location: ?page=support&mode=chat&id='.Aelvo\Input::integer($_POST['thread_id']??null),true,303);exit;
                case 'support-update': (new Aelvo\Support($pdo,$actor))->update($_POST);header('Location: ?page=support&id='.Aelvo\Input::integer($_POST['id']??null),true,303);exit;
                case 'return-request-review': (new Aelvo\ReturnRequests($pdo,$actor))->review($_POST);go('returns');
                case 'return-restock': (new Aelvo\Returns($pdo,$actor))->restock(Aelvo\Input::integer($_POST['return_id']??null));go('returns');
                case 'refund-propose': (new Aelvo\Returns($pdo,$actor))->proposeRefund($_POST);go('returns');
                case 'refund-review': (new Aelvo\Returns($pdo,$actor))->reviewRefund($_POST);go('returns');
                case 'return-receive': (new Aelvo\Returns($pdo,$actor))->receive($_POST);go('returns');
                case 'refund-record':
                    if(!Security::can($pdo,$actor,'finance','edit'))throw new RuntimeException('Permission denied',403);
                    $v=$_POST;$v['document_id']=(new Aelvo\Uploads($pdo,$actor))->document($_FILES['document']??[],'returns');
                    (new Aelvo\Returns($pdo,$actor))->refund($v);go('returns');
                case 'payment-receive': (new Aelvo\Payments($pdo,$actor))->receive($_POST);go('payments');
                case 'payment-reject': (new Aelvo\Payments($pdo,$actor))->reject($_POST);go('payments');
                case 'payment-instructions': (new Aelvo\Settings($pdo,$actor))->paymentInstructions($_POST);go('settings');
                case 'coupon-save': (new Aelvo\Coupons($pdo,$actor))->save($_POST);go('coupons');
                case 'seo-save': (new Aelvo\Seo($pdo,$actor))->save($_POST);go('seo');
                case 'taxonomy-save': (new Aelvo\Taxonomy($pdo,$actor))->save($_POST);go('seo');
                case 'seo-redirect-add': (new Aelvo\PublicRoutes($pdo,$actor))->addRedirect($_POST);go('seo');
                case 'seo-redirect-toggle': (new Aelvo\PublicRoutes($pdo,$actor))->toggleRedirect($_POST);go('seo');
                case 'seo-404-review': (new Aelvo\Seo($pdo,$actor))->review404($_POST);go('seo');
                case 'seo-product-save':
                    (new Aelvo\Seo($pdo,$actor))->saveProduct($_POST);
                    header('Location: ?page='.((($_POST['return_to']??'')==='seo')?'seo&product_id=':'products&id=').Aelvo\Input::integer($_POST['product_id']??null),true,303);exit;
                case 'seo-image-alt-save':
                    (new Aelvo\Seo($pdo,$actor))->saveImageAlt($_POST);
                    header('Location: ?page=products&id='.Aelvo\Input::integer($_POST['product_id']??null),true,303);exit;
                case 'review-moderate': (new Aelvo\Reviews($pdo,$actor))->moderate($_POST);go('reviews');
                case 'content-save': (new Aelvo\Content($pdo,$actor))->save($_POST);header('Location: ?page=content&key='.rawurlencode($_POST['content_key']),true,303);exit;
                case 'content-publish': (new Aelvo\Content($pdo,$actor))->publish($_POST);go('content');
                case 'cms-page-save':
                    $cmsId=(new Aelvo\CmsPages($pdo,$actor))->save($_POST);
                    header('Location: ?page=content&mode=pages&id='.$cmsId,true,303);exit;
                case 'cms-page-publish':
                    $cmsId=(new Aelvo\CmsPages($pdo,$actor))->publish($_POST);
                    header('Location: ?page=content&mode=pages&id='.$cmsId,true,303);exit;
                case 'content-upload':
                    (new Aelvo\Uploads($pdo,$actor))->content($_FILES['media']??[]);
                    if(($_POST['return_to']??'')==='cms'){
                        $cmsId=Aelvo\Input::integer($_POST['cms_id']??'0',0);
                        header('Location: ?page=content&mode=pages'.($cmsId?'&id='.$cmsId:''),true,303);exit;
                    }
                    go('content');
                case 'product-save': $id=$catalogue->save($_POST); header('Location: ?page=products&id='.$id,true,303);exit;
                case 'product-delete': $catalogue->archive(Aelvo\Input::integer($_POST['id']??null));go('products');
                case 'variant-create': $catalogue->variant($_POST);header('Location: ?page=products&id='.Aelvo\Input::integer($_POST['product_id']??null).'#product-editor',true,303);exit;
                case 'variant-price': $productId=$catalogue->variantPrice($_POST);header('Location: ?page=products&id='.$productId.'#product-editor',true,303);exit;
                case 'variant-attributes': $productId=$catalogue->variantAttributes($_POST);header('Location: ?page=products&id='.$productId.'#product-editor',true,303);exit;
                case 'product-primary-image': $productId=(new Aelvo\Uploads($pdo,$actor))->makePrimary($_POST);header('Location: ?page=products&id='.$productId.'#product-editor',true,303);exit;
                case 'product-image': (new Aelvo\Uploads($pdo,$actor))->product($_FILES['image']??[],Aelvo\Input::integer($_POST['product_id']??null),Aelvo\Input::text($_POST,'colour',80,false));go('products');
                case 'inventory-adjust': $catalogue->adjust($_POST);go('inventory');
                case 'quarantine-record': (new Aelvo\Quarantine($pdo,$actor))->record($_POST);go('inventory');
                case 'order-create': $v=$_POST;$v['items']=isset($_POST['lines'])?Aelvo\Orders::parseOrderLines(Aelvo\Input::text($_POST,'lines',2500)):[['variant_id'=>$_POST['variant_id']??null,'qty'=>$_POST['qty']??null]];(new Aelvo\Orders($pdo,$actor))->create($v);go('orders');
                case 'order-cancellation-review':
                    (new Aelvo\OrderCancellationRequests($pdo,$actor))->review($_POST);
                    header('Location: ?page=orders&mode=cancellations',true,303);exit;
                case 'order-status':
                    $orderId=Aelvo\Input::integer($_POST['id']??null);
                    (new Aelvo\Orders($pdo,$actor))->transition($orderId,Aelvo\Input::text($_POST,'status',30),Aelvo\Input::text($_POST,'expected_status',30));
                    go('orders&id='.$orderId);
                case 'theme-save': (new Aelvo\Settings($pdo,$actor))->theme($_POST);go('settings');
                case 'delivery-save': (new Aelvo\Settings($pdo,$actor))->delivery($_POST);go('settings');
                case 'tax-save': (new Aelvo\Settings($pdo,$actor))->tax($_POST);go('settings');
                case 'provider-save': (new Aelvo\Settings($pdo,$actor))->provider($_POST,$config['encryption_key']??'');go('settings');
                case 'bulksmsbd-save': (new Aelvo\Settings($pdo,$actor))->bulkSmsBd($_POST,$config['encryption_key']??'');go('settings');
                case 'staff-create': (new Aelvo\Team($pdo,$actor))->create($_POST);go('team');
                case 'staff-permissions': (new Aelvo\Team($pdo,$actor))->permissions(Aelvo\Input::integer($_POST['user_id']??null),$_POST['grants']??[]);go('team');
                case 'account-create': (new Aelvo\Finance($pdo,$actor))->account($_POST);go('finance');
                case 'finance-post': $v=$_POST;$v['document_id']='';if(($_FILES['document']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)$v['document_id']=(new Aelvo\Uploads($pdo,$actor))->document($_FILES['document'],'finance');(new Aelvo\Finance($pdo,$actor))->post($v);go('finance');
                case 'purchase-create': $v=$_POST;if(isset($_POST['lines']))$v['items']=Aelvo\Purchases::parsePurchaseLines(Aelvo\Input::text($_POST,'lines',3000));$v['receipt_id']=(new Aelvo\Uploads($pdo,$actor))->document($_FILES['document']??[],'purchases');(new Aelvo\Purchases($pdo,$actor))->create($v);go('purchases');
                case 'purchase-receive': (new Aelvo\Purchases($pdo,$actor))->receive(Aelvo\Input::integer($_POST['id']??null));go('purchases');
                case 'supplier-save': (new Aelvo\Suppliers($pdo,$actor))->save($_POST);go('purchases');
                case 'supplier-payment':
                    $v=$_POST; $v['document_id']=(new Aelvo\Uploads($pdo,$actor))->document($_FILES['document']??[],'supplier-payments');
                    (new Aelvo\Purchases($pdo,$actor))->recordPayment($v);go('purchases');
                case 'staff-disable':
                case 'staff-enable':
                    if(($_POST['confirm']??'')!=='1')throw new InvalidArgumentException('Confirm the staff access change.');
                    (new Aelvo\Team($pdo,$actor))->setActive(Aelvo\Input::integer($_POST['user_id']??null),$action==='staff-enable');go('team');
            }
        } catch(InvalidArgumentException|DomainException $e) { $error=$e->getMessage(); }
        catch(PDOException $e) { $error='Unable to save. Check duplicate values or reload and try again.'; }
        catch(RuntimeException $e) { if(in_array($e->getCode(),[403,409],true))http_response_code($e->getCode());$error=$e->getMessage(); }
    }

    if ($action==='install' && !$installed) {
        if (!Security::throttle($pdo,'install:'.$ip,5)) { http_response_code(429); $error='Too many attempts. Wait 15 minutes.'; }
        elseif (!is_string($_POST['token']??null) || !preg_match('/^[a-f0-9]{64}$/D',$config['install_token']) || !hash_equals($config['install_token'],$_POST['token'])) $error='Invalid installation token.';
        else {
            $name=trim((string)($_POST['name']??'')); $email=strtolower(trim((string)($_POST['email']??''))); $password=(string)($_POST['password']??'');
            if ($name==='' || strlen($name)>120 || strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12 || strlen($password)>72 || $password!==($_POST['confirm']??'')) $error='Enter a name, valid email and matching password (12–72 bytes).';
            else {
                $hash=password_hash($password,PASSWORD_DEFAULT);
                $pdo->beginTransaction();
                try {
                    $row=$pdo->query('SELECT owner_id FROM aelvo_installation WHERE id=1 FOR UPDATE')->fetch();
                    if (!$row || $row['owner_id']!==null) throw new RuntimeException('Installation locked');
                    $q=$pdo->prepare('INSERT INTO aelvo_users(name,email,password_hash) VALUES (?,?,?)'); $q->execute([$name,$email,$hash]);
                    $id=$pdo->lastInsertId();
                    $q=$pdo->prepare('UPDATE aelvo_installation SET owner_id=?,installed_at=UTC_TIMESTAMP() WHERE id=1'); $q->execute([$id]);
                    $q=$pdo->prepare('INSERT INTO aelvo_audit(actor_id,event) VALUES (?,?)'); $q->execute([$id,'owner.installed']);
                    $pdo->commit(); go('login');
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error='Installation could not complete. Check whether an Owner already exists.'; }
            }
        }
    } elseif ($action==='login' && $installed) {
        $email=strtolower(trim((string)($_POST['email']??''))); $password=(string)($_POST['password']??'');
        $ipAllowed=Security::throttle($pdo,'login-ip:'.$ip,50);
        $accountAllowed=Security::throttle($pdo,'login-email:'.$email,10);
        if (!$ipAllowed || !$accountAllowed) { http_response_code(429); $error='Too many attempts. Wait 15 minutes.'; }
        else {
            $q=$pdo->prepare('SELECT u.*,COALESCE(v.revision,0) AS session_revision FROM aelvo_users u LEFT JOIN aelvo_staff_session_revisions v ON v.user_id=u.id WHERE u.email=? LIMIT 1'); $q->execute([$email]); $u=$q->fetch();
            $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $valid=password_verify($password,$u['password_hash']??$dummy);
            if (!$u || !$valid || $u['status']!=='Active') $error='Email or password is incorrect.';
            else {
                Security::logout(); $_SESSION['user_id']=(int)$u['id']; $_SESSION['staff_revision']=(int)$u['session_revision']; $_SESSION['login_at']=$_SESSION['last_seen']=time();
                $q=$pdo->prepare('INSERT INTO aelvo_audit(actor_id,event) VALUES (?,?)'); $q->execute([$u['id'],'auth.login']); go('dashboard');
            }
        }
    }
}
if (!$installed) $page='install';
elseif (!$actor) $page='login';
// Authenticated requests retain their authorized page.
if ($actor && ((in_array($page,['team','owner-ai','activity'],true) && !$actor['is_owner']) || (!in_array($page,['team','owner-ai','activity'],true) && !Security::can($pdo,$actor,$page,'view')))) { http_response_code(403); exit('You do not have permission to view this page.'); }
// Reject malformed register filters with HTTP 400 before rendering or executing queries.
if ($actor && $page==='returns') {
    try {
        foreach (['requests','receipts','items'] as $queue) Aelvo\ReturnOperations::filters($_GET,$queue);
        // The refund register is accessible only with both finance and returns view permissions.
        if (Security::can($pdo,$actor,'finance','view')) Aelvo\ReturnOperations::filters($_GET,'refunds');
    } catch (InvalidArgumentException $e) { http_response_code(400); exit('Invalid returns filter. Return to the module and try again.'); }
}
if ($actor && $page==='purchases') {
    try { Aelvo\PurchaseRegister::filters($_GET); Aelvo\SupplierRegister::filters($_GET);
        if(isset($_GET['supplier_id'])) Aelvo\Input::integer($_GET['supplier_id']);
        if(isset($_GET['purchase_supplier_id'])) Aelvo\Input::integer($_GET['purchase_supplier_id']);
        if(isset($_GET['payment_purchase_id'])) Aelvo\Input::integer($_GET['payment_purchase_id']); }
    catch (InvalidArgumentException $e) { http_response_code(400); exit('Invalid purchase filters. Return to Purchases and try again.'); }
}
if ($actor && $page==='activity') {
    try { Aelvo\AuditLog::filters($_GET); }
    catch (InvalidArgumentException $e) { http_response_code(400); exit('Invalid audit filters. Return to Activity & audit and try again.'); }
}
if ($actor && in_array($page,['products','inventory'],true)) {
    try { Aelvo\AdminWorkspace::filters($_GET,$page); }
    catch (InvalidArgumentException $e) { http_response_code(400); exit('Invalid filter. Return to the module and try again.'); }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AELVO — Store administration</title>
<link rel="stylesheet" href="backend.css"><script src="cms-blocks.js" defer></script><script src="admin-mobile.js" defer></script>
</head>
<body class="<?=$actor?'admin-authenticated':'admin-access'?>">
<?php if (!$actor): ?>
<main class="admin-access-main">
<a class="brand" href="?">AELVO <small>FASHION</small></a>
<?php if ($error): ?><p role="alert" class="error"><?=h($error)?></p><?php endif; ?>
<?php if ($page==='install'): ?>
<h1>Create your Owner account</h1><p>This runs once. The Owner identity is stored in your database.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="install">
<label>Installation token<input name="token" type="password" required autocomplete="off"></label>
<label>Your name<input name="name" maxlength="120" required autocomplete="name"></label>
<label>Email<input name="email" type="email" maxlength="254" required autocomplete="email"></label>
<label>Password<input name="password" type="password" minlength="12" maxlength="72" required autocomplete="new-password"></label>
<label>Confirm password<input name="confirm" type="password" minlength="12" maxlength="72" required autocomplete="new-password"></label>
<button>Create Owner account</button></form>
<?php else: ?>
<h1>Sign in</h1><p>Use your store account.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="login">
<label>Email<input name="email" type="email" required autocomplete="username"></label>
<label>Password<input name="password" type="password" required autocomplete="current-password"></label>
<button>Sign in</button></form>
<?php endif; ?>
</main>
<?php else: ?>
<a class="admin-skip" href="#admin-content">Skip to content</a>
<div class="admin-shell">
<aside class="admin-sidebar" aria-label="Admin sidebar">
  <a class="admin-logo" href="?page=dashboard"><span class="wordmark">AELVO<small>FASHION</small></span></a>
  <?php Aelvo\AdminView::navigation($pdo,$actor,$page); ?>
  <div class="admin-profile"><span class="admin-avatar" aria-hidden="true"><?=h(strtoupper(substr((string)$actor['name'],0,1)))?></span><div><strong><?=h($actor['name'])?></strong><small><?=!empty($actor['is_owner'])?'Owner':'Team member'?></small></div></div>
</aside>
<div class="admin-main">
<header class="admin-topbar">
  <div class="admin-breadcrumb"><span>Admin</span><span aria-hidden="true"> / </span><strong><?=h(ucwords(str_replace(['-','_'],' ',(string)$page)))?></strong></div>
  <div class="admin-top-actions">
    <?php Aelvo\AdminView::moduleFinder($pdo,$actor); ?>
    <a class="action" href="./store.php" target="_blank" rel="noopener noreferrer">View store</a>
    <?php if(!empty($actor['is_owner'])):?><a href="schema-status.php">Schema status</a> <a href="upgrade-review.php">Upgrade preflight</a><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="logout"><button type="submit" class="admin-signout">Sign out</button></form>
  </div>
</header>

<main class="admin-content" id="admin-content">
<?php if ($error): ?><p role="alert" class="error"><?=h($error)?></p><?php endif; ?>
<?php Aelvo\AdminView::render($pdo,$actor,$page,$config['encryption_key']??''); ?>
</main>
<footer class="admin-footer">AELVO Fashion · Admin workspace · Live database records, not demo data</footer>
</div>
</div>
<?php Aelvo\AdminView::mobileNavigation($pdo,$actor,$page); ?>
<?php endif; ?>
</body></html>
