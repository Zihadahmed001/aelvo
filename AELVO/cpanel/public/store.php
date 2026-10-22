<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
Aelvo\Security::start((bool)$config['secure_cookies']);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
function esc(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$lang=($_GET['lang']??$_SESSION['language']??'en')==='bn'?'bn':'en';$_SESSION['language']=$lang;
function tr(string $en,string $bn):string{global $lang;return $lang==='bn'?$bn:$en;}
function nameOf(array $p):string{global $lang;return $lang==='bn'&&$p['name_bn']!==''?$p['name_bn']:$p['name'];}
function photo(array $p,string $colour=''):string{foreach($p['images'] as $m)if($colour===''||$m['colour']===$colour)return 'media.php?id='.$m['media_id'];return $p['images']?'media.php?id='.$p['images'][0]['media_id']:'assets/placeholder.svg';}
function photoAlt(array $p,string $colour=''):string{
 global $lang;
 $fallback=nameOf($p);
 foreach($p['images'] as $m)if($colour===''||$m['colour']===$colour)return ($lang==='bn'&&($m['alt_bn']??'')!==''?$m['alt_bn']:($m['alt_en']??''))?:$fallback;
 $m=$p['images'][0]??null;
 return $m?(($lang==='bn'&&($m['alt_bn']??'')!==''?$m['alt_bn']:($m['alt_en']??''))?:$fallback):$fallback;
}

$page=is_string($_GET['page']??null)?$_GET['page']:'home';if(!in_array($page,['home','shop','product','cart','checkout','account','info','tracking','wishlist','support','chat'],true))$page='not-found';
// Apache rewrites public clean paths here. Query parameters never choose a
// product by phone/session or grant access to unpublished products.
$publicPath=is_string($_GET['public_path']??null)?$_GET['public_path']:null;
if($publicPath!==null){
    try {
        $publicPath=Aelvo\PublicRoutes::path($publicPath);
        // Canonical policy: lowercase, no trailing slash on product paths.
        // Never carry arbitrary inbound query parameters through this 301.
        $urlPath=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        if (is_string($urlPath) && str_ends_with($urlPath,'/') && in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)) {
            header('Location: '.Aelvo\PublicRoutes::localUrl($publicPath),true,301);exit;
        }
        $resolved=Aelvo\PublicRoutes::resolve($pdo,$publicPath);
        if($resolved && $resolved['type']==='redirect'){
            if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)) {http_response_code(409);exit('This URL has moved. Reload its new location.');}
            header('Location: '.Aelvo\PublicRoutes::localUrl($resolved['destination']),true,301);exit;
        }
        if($resolved && $resolved['type']==='product'){$page='product';$_GET['id']=$resolved['id'];}
        elseif($resolved && $resolved['type']==='cms'){$page='cms';$_GET['cms_id']=$resolved['id'];}
        elseif($resolved && in_array($resolved['type'],['category','brand'],true)){$page=$resolved['type'];$_GET['entity_id']=$resolved['id'];}
        else $page='not-found';
    } catch(InvalidArgumentException $e){$page='not-found';$publicPath='invalid';}
} elseif($page==='product' && in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)) {
    // Old numeric URLs are still accepted, but must not compete with clean
    // canonical product URLs in search results or shared links.
    $legacyId=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);
    if($legacyId && $legacyId>0){
        $legacyProduct=Aelvo\PublicRoutes::liveProduct($pdo,(int)$legacyId);
        if($legacyProduct){
            $canonicalPath=Aelvo\PublicRoutes::productPath($legacyProduct['slug']);
            $canonicalRoute=Aelvo\PublicRoutes::resolve($pdo,$canonicalPath);
            if ($canonicalRoute && $canonicalRoute['type']==='product' && $canonicalRoute['id']===(int)$legacyProduct['id']) {
                $suffix=($_GET['lang']??'')==='bn'?'?lang=bn':'';
                header('Location: '.Aelvo\PublicRoutes::localUrl($canonicalPath).$suffix,true,301);exit;
            }
        }
    }
}
// A trusted, site-local base makes existing theme assets and navigation work
// when the browser address is /product/slug rather than store.php.
header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
$_SESSION['chat_guest_key']??=bin2hex(random_bytes(32));$_SESSION['bag']??=[];$_SESSION['checkout_identity']??=bin2hex(random_bytes(32));$error='';
$customer=Aelvo\Customers::current($pdo);Aelvo\SavedBag::hydrate($pdo,$customer);
$taxonomyPage=null;$cmsPage=null;$collectionFilters=[];
if($page==='cms'){
    $cmsPage=Aelvo\CmsPages::published($pdo,(int)($_GET['cms_id']??0));
    if(!$cmsPage)$page='not-found';
}
if(in_array($page,['category','brand'],true)){
    $taxonomyPage=Aelvo\Taxonomy::page($pdo,$page,(int)($_GET['entity_id']??0));
    if(!$taxonomyPage)$page='not-found';
    elseif($page==='category')$collectionFilters=$taxonomyPage['parent_id']===null?['category'=>$taxonomyPage['root_code']]:['category_id'=>$taxonomyPage['id']];
    else $collectionFilters=['brand_id'=>$taxonomyPage['id']];
    $requestedPage=is_string($_GET['p']??null)?$_GET['p']:'1';$collectionFilters['p']=$requestedPage;
}
$filters=[];foreach(['q','category','category_id','brand_id','min','max','stock','sort','p'] as $key)if(is_string($_GET[$key]??null))$filters[$key]=$_GET[$key];
try{$products=Aelvo\Catalogue::publicProducts($pdo,$page==='shop'?$filters:($page==='home'?['sort'=>'merchandising']:(in_array($page,['category','brand'],true)?$collectionFilters:[])));$productCount=Aelvo\Catalogue::count($pdo,$page==='shop'?$filters:($page==='home'?['sort'=>'merchandising']:(in_array($page,['category','brand'],true)?$collectionFilters:[])));}catch(InvalidArgumentException $e){$filters=[];$products=[];$productCount=0;$error=$e->getMessage();}
// Do not generate unlimited 200 pages for non-existent pagination or
// invalid storefront filters. The ordinary empty first page may still render.
if (in_array($page,['shop','category','brand'],true)) {
    $pageNumber=filter_var(($page==='shop'?$filters:$collectionFilters)['p']??'1',FILTER_VALIDATE_INT);
    $lastPage=max(1,(int)ceil($productCount/24));
    if ($error!=='' || $pageNumber===false || $pageNumber<1 || $pageNumber>$lastPage) $page='not-found';
}
$extra=[];$requested=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);if($requested&&$requested>0)$extra[]=$requested;
$bagVariants=array_unique([...array_keys($_SESSION['bag']),...array_keys($_SESSION['buy_now']??[])]);
if($bagVariants){$marks=implode(',',array_fill(0,count($bagVariants),'?'));$q=$pdo->prepare('SELECT DISTINCT product_id FROM aelvo_variants WHERE id IN ('.$marks.')');$q->execute($bagVariants);$extra=[...$extra,...$q->fetchAll(PDO::FETCH_COLUMN)];}
$byId=[];$variants=[];foreach([...$products,...Aelvo\Catalogue::publicProducts($pdo,[],$extra)] as $p){$byId[$p['id']]=$p;foreach($p['variants'] as $v)$variants[$v['id']]=[$p,$v];}
$customer=Aelvo\Customers::current($pdo);
$wishlistIds=$customer?Aelvo\Wishlist::ids($pdo,(int)$customer['id']):[];
$customers=new Aelvo\Customers($pdo,$_SESSION['checkout_identity']);
$tracking=new Aelvo\Tracking($pdo,$_SESSION['checkout_identity']);
$checkout=new Aelvo\Checkout($pdo,$_SESSION['checkout_identity']);
$checkoutId=is_string($_GET['checkout']??null)?$_GET['checkout']:'';
if($_SERVER['REQUEST_METHOD']==='POST'){
 Aelvo\Security::csrf();
 try{
  $action=Aelvo\Input::text($_POST,'action',30);
  if($action==='chat-send'||$action==='chat-human'){
   $page='chat';
   if(!Aelvo\Security::throttle($pdo,'chat-ip:'.($_SERVER['REMOTE_ADDR']??'unknown'),28)||!Aelvo\Security::throttle($pdo,'chat-session:'.hash('sha256',$_SESSION['chat_guest_key']),22))throw new InvalidArgumentException(tr('Too many chat requests. Try again later.','অনেক চ্যাট অনুরোধ হয়েছে। পরে চেষ্টা করুন।'));
   $thread=Aelvo\CustomerChat::open($pdo,$customer,$_SESSION['chat_guest_key']);
   if($action==='chat-human')Aelvo\CustomerChat::askHuman($pdo,(int)$thread['id'],$customer,$_SESSION['chat_guest_key']);
   else{
    $aiSettings=Aelvo\CustomerAi::settings($pdo,$config['encryption_key']??'');
    $claim=Aelvo\CustomerChat::send($pdo,(int)$thread['id'],$customer,$_SESSION['chat_guest_key'],$_POST,$aiSettings!==null);
    if($claim!==null&&$aiSettings!==null){
     $history=Aelvo\CustomerChat::historyForClaim($pdo,(int)$thread['id'],$claim['token']);
     if($history){
      try{$answer=Aelvo\CustomerAi::reply($aiSettings,$history);}catch(\Throwable $aiError){error_log('AELVO customer AI reply unavailable: '.get_class($aiError));$answer=null;}
      Aelvo\CustomerChat::completeAi($pdo,(int)$thread['id'],$claim['token'],$claim['message_id'],$answer);
     }
    }
    $_SESSION['chat_request_key']=bin2hex(random_bytes(32));
   }
   header('Location: store.php?page=chat',true,303);exit;
  }
  if($action==='support-submit'){
   $page='support';$ip=$_SERVER['REMOTE_ADDR']??'unknown';
   if(!Aelvo\Security::throttle($pdo,'support-ip:'.$ip,12))throw new InvalidArgumentException(tr('Too many requests. Try later.','অতিরিক্ত অনুরোধ। পরে চেষ্টা করুন।'));
   if(!Aelvo\Security::throttle($pdo,'support-session:'.session_id(),8))throw new InvalidArgumentException(tr('Too many requests from this session.','এই সেশন থেকে অনেক অনুরোধ করা হয়েছে।'));
   $ticketId=Aelvo\Support::submit($pdo,$_POST,$_FILES['screenshot']??[],$customer);
   $_SESSION['support_success']=$ticketId;
   $_SESSION['support_form_token']=bin2hex(random_bytes(32));
   header('Location: store.php?page=support',true,303);exit;
  }
  if($action==='wishlist-save'){
   $page='wishlist';Aelvo\Wishlist::save($pdo,$_POST);header('Location: store.php?page=wishlist',true,303);exit;
  }
  if($action==='order-cancel-request'){
   $page='account';
   if(!$customer)throw new InvalidArgumentException(tr('Sign in to request cancellation.','বাতিলের অনুরোধ করতে লগইন করুন।'));
   if(!Aelvo\Security::throttle($pdo,'cancel-request-customer:'.$customer['id'],12))throw new InvalidArgumentException(tr('Too many cancellation requests. Try again later.','অনেক অনুরোধ হয়েছে। পরে চেষ্টা করুন।'));
   Aelvo\OrderCancellationRequests::request($pdo,$_POST);
   $_SESSION['account_notice']=tr('Cancellation request sent for staff review. Your order is not yet cancelled.','বাতিলের অনুরোধ রিভিউয়ের জন্য পাঠানো হয়েছে। অর্ডার এখনও বাতিল হয়নি।');
   header('Location: store.php?page=account#account-orders',true,303);exit;
  }
  if($action==='return-request'){
   $page='account';
   if(!Aelvo\Security::throttle($pdo,'return-request:'.($_SERVER['REMOTE_ADDR']??'unknown'),20))throw new InvalidArgumentException('Too many return submissions. Try later.');
   Aelvo\ReturnRequests::submit($pdo,$_POST,$_FILES['evidence']??[]);$_SESSION['account_notice']='Return request submitted for review. No stock or refund has been posted.';header('Location: store.php?page=account',true,303);exit;
  }

  if(in_array($action,['customer-login','customer-signup','customer-verify','customer-logout','customer-phone','customer-reset','address-save','address-delete','address-use','review-submit','customer-profile','customer-password'],true)){
   $page='account';$ip=$_SERVER['REMOTE_ADDR']??'unknown';
   if($action==='customer-profile')$customers->updateProfile($_POST);
   if($action==='customer-password')$customers->changePassword($_POST,$ip);
   if($action==='customer-login')$customers->login($_POST,$ip);
   if($action==='customer-logout')Aelvo\Customers::logout();
   if($action==='address-save')$customers->saveAddress($_POST);
   if($action==='address-delete')$customers->deleteAddress($_POST);
   if($action==='address-use'){$a=$customers->address(Aelvo\Input::integer($_POST['address_id']??null));$_SESSION['checkout_form']=['customer_name'=>$a['recipient'],'phone'=>$a['phone'],'address'=>$a['address'],'zone'=>$a['zone']];header('Location: store.php?page=checkout',true,303);exit;}
   if(in_array($action,['customer-signup','customer-phone','customer-reset'],true)){
    $sms=new Aelvo\Sms($pdo,$config['encryption_key']??'');
    $_SESSION['customer_challenge']=$customers->challenge($_POST,match($action){'customer-signup'=>'signup','customer-reset'=>'reset',default=>'phone'},$ip,[$sms,'send']);
   }
   if($action==='customer-verify')$customers->verify($_SESSION['customer_challenge']??'',Aelvo\Input::text($_POST,'code',20));
   if($action==='review-submit'){
    if(!$customer)throw new InvalidArgumentException('Sign in before reviewing.');
    if(!Aelvo\Security::throttle($pdo,'review-submit:'.$customer['id'],15))throw new InvalidArgumentException('Too many review submissions. Try later.');
    Aelvo\Reviews::submit($pdo,(int)$customer['id'],$_POST,$_FILES['photos']??[]);$_SESSION['account_notice']='Review submitted for moderation.';
   }
   header('Location: store.php?page=account',true,303);exit;
  }
  if(in_array($action,['tracking-request','tracking-verify'],true)){
   $page='tracking';
   if($action==='tracking-request'){$sms=new Aelvo\Sms($pdo,$config['encryption_key']??'');$_SESSION['tracking_challenge']=$tracking->request($_POST,$_SERVER['REMOTE_ADDR']??'unknown',[$sms,'send']);}
   else $tracking->verify($_SESSION['tracking_challenge']??'',Aelvo\Input::text($_POST,'code',20));
   header('Location: store.php?page=tracking',true,303);exit;
  }
  if($action==='payment-proof'){
   $orderId=0;$proofCheckout=Aelvo\Input::text($_POST,'checkout_id',64,false);
   if($proofCheckout!==''){$owned=$checkout->get($proofCheckout);$orderId=(int)($owned['order_id']??0);}
   elseif($customer){$q=$pdo->prepare('SELECT order_id FROM aelvo_customer_orders WHERE order_id=? AND customer_id=?');$q->execute([Aelvo\Input::integer($_POST['order_id']??null),$customer['id']]);$orderId=(int)$q->fetchColumn();}
   if(!$orderId)throw new InvalidArgumentException('Sign in to the order account or use its original checkout session.');
   if(!Aelvo\Security::throttle($pdo,'payment-proof:'.($_SERVER['REMOTE_ADDR']??'unknown'),15))throw new InvalidArgumentException('Too many uploads. Try again later.');
   Aelvo\Payments::proof($pdo,$orderId,$_POST,$_FILES['proof']??[]);
   $_SESSION['account_notice']='Payment evidence submitted. It does not mark the order paid until independently verified.';
   header('Location: '.($proofCheckout!==''?'store.php?page=checkout&checkout='.$proofCheckout:'store.php?page=account'),true,303);exit;
  }
  if($action==='quote'){
   $_SESSION['checkout_form']=[];foreach(['customer_name','phone','address','zone','coupon'] as $field)if(is_string($_POST[$field]??null))$_SESSION['checkout_form'][$field]=substr($_POST[$field],0,$field==='address'?2000:120);
   if(!Aelvo\Security::throttle($pdo,'checkout-quote:'.($_SERVER['REMOTE_ADDR']??'unknown'),30))throw new InvalidArgumentException('Too many checkout attempts. Please try later.');
   $direct=isset($_SESSION['buy_now']);$id=$checkout->quote($_POST,$direct?$_SESSION['buy_now']:$_SESSION['bag'],$direct);
   header('Location: store.php?page=checkout&checkout='.$id,true,303);exit;
  }
  if(in_array($action,['send-code','verify-code','place-order'],true)){
   $id=Aelvo\Input::text($_POST,'checkout_id',64);$checkoutId=$id;
   if($action==='send-code'){$sms=new Aelvo\Sms($pdo,$config['encryption_key']??'');$checkout->sendCode($id,$_SERVER['REMOTE_ADDR']??'unknown',[$sms,'send']);}
   if($action==='verify-code')$checkout->verify($id,Aelvo\Input::text($_POST,'code',20));
   if($action==='place-order'){
    if(($_POST['confirm']??'')!=='1')throw new InvalidArgumentException('Confirm the order details first.');
    $checkout->place($id);$completed=$checkout->get($id);
    // Clean up once; a replay must never subtract a newly added bag item.
    if(!isset($_SESSION['checkout_cleaned'][$id])){
     if(!$customer&&!$completed['data']['direct'])foreach($completed['data']['items'] as $line){$variant=$line['variant_id'];$_SESSION['bag'][$variant]=max(0,($_SESSION['bag'][$variant]??0)-$line['qty']);if($_SESSION['bag'][$variant]===0)unset($_SESSION['bag'][$variant]);}
     if($customer)$_SESSION['bag']=Aelvo\SavedBag::read($pdo,(int)$customer['id']);
     unset($_SESSION['buy_now'],$_SESSION['checkout_form']);$_SESSION['checkout_cleaned'][$id]=true;
    }
   }
   header('Location: store.php?page=checkout&checkout='.$id,true,303);exit;
  }
  if($action==='remove'){$removed=Aelvo\Input::integer($_POST['variant_id']??null);if($customer)Aelvo\SavedBag::mutate($pdo,$removed,0,true);else unset($_SESSION['bag'][$removed]);header('Location: store.php?page=cart',true,303);exit;}
  if($action==='bag-quantity'){
   $page='cart';
   $variant=Aelvo\Input::integer($_POST['variant_id']??null);
   $quantity=Aelvo\Input::integer($_POST['qty']??null,1,100);
   if(!isset($_SESSION['bag'][$variant]))throw new InvalidArgumentException(tr('This item is no longer in your bag.','পণ্যটি আর আপনার ব্যাগে নেই।'));
   if($customer)Aelvo\SavedBag::replaceQuantity($pdo,$variant,$quantity);
   else{
    if(!isset($variants[$variant]))throw new InvalidArgumentException(tr('This variant is currently unavailable. Remove it from your bag.','পণ্যটি এখন পাওয়া যাচ্ছে না। ব্যাগ থেকে সরিয়ে দিন।'));
    Aelvo\SavedBag::validateQuantity($quantity,(int)$variants[$variant][1]['available']);
    $_SESSION['bag'][$variant]=$quantity;
   }
   unset($_SESSION['buy_now']);
   $_SESSION['bag_updated_notice']=true;
   header('Location: store.php?page=cart',true,303);exit;
  }

  if(!in_array($action,['bag','buy-now'],true))throw new InvalidArgumentException('Invalid action');
  $id=Aelvo\Input::integer($_POST['variant_id']??null);$qty=Aelvo\Input::integer($_POST['qty']??null,1,100);
  if(!isset($variants[$id]))throw new InvalidArgumentException(tr('This variant is unavailable.','এই ভ্যারিয়েন্টটি পাওয়া যাচ্ছে না।'));
  $requestedQty=$action==='bag'?($_SESSION['bag'][$id]??0)+$qty:$qty;
  if($requestedQty>100||$requestedQty>(int)$variants[$id][1]['available'])throw new InvalidArgumentException(tr('Not enough stock for this quantity.','এই পরিমাণ stock নেই।'));
  if($action==='bag'){
    if($customer)Aelvo\SavedBag::mutate($pdo,$id,$qty);
    else{if(!isset($_SESSION['bag'][$id])&&count($_SESSION['bag'])>=30)throw new InvalidArgumentException('Keep up to 30 variants in your bag.');$_SESSION['bag'][$id]=$requestedQty;}
    unset($_SESSION['buy_now']);
    // Flash notice is displayed only on a server-confirmed bag mutation.
    $_SESSION['bag_notice']=true;
    $product=$variants[$id][0];
    header('Location: '.Aelvo\PublicRoutes::localUrl(Aelvo\PublicRoutes::productPath($product['slug'])),true,303);exit;
  }
  $_SESSION['buy_now']=[$id=>$qty];
  header('Location: store.php?page=checkout',true,303);exit;
 }catch(InvalidArgumentException|RuntimeException $e){$error=$e instanceof PDOException?tr('Unable to complete checkout. Please retry.','চেকআউট সম্পন্ন হয়নি। আবার চেষ্টা করুন।'):$e->getMessage();}
}
if($page!=='checkout')unset($_SESSION['buy_now']);
function wishlistButton(int $id):void{global $wishlistIds; $saved=in_array($id,$wishlistIds,true);echo '<form method="post" class="wishlist-action"><input type="hidden" name="csrf" value="'.esc($_SESSION['csrf']).'"><input type="hidden" name="action" value="wishlist-save"><input type="hidden" name="product_id" value="'.$id.'"><input type="hidden" name="mode" value="'.($saved?'remove':'save').'"><button class="btn secondary full">'.($saved?tr('Remove from wishlist','পছন্দের তালিকা থেকে বাদ দিন'):tr('Save to wishlist','পছন্দের তালিকায় রাখুন')).'</button></form>';}
function card(array $p):void{global $pdo;require __DIR__.'/product-card-view.php';}
$languageQuery=['page'=>$page,'lang'=>$lang==='en'?'bn':'en'];if($page==='product'&&$requested)$languageQuery['id']=$requested;if($page==='checkout'&&$checkoutId!=='')$languageQuery['checkout']=$checkoutId;if($page==='info'&&is_string($_GET['key']??null))$languageQuery['key']=$_GET['key'];if($page==='shop')$languageQuery=[...$languageQuery,...$filters];
if($page==='product'&&!isset($byId[$requested]))$page='not-found';
if($page==='info'){$infoKey=is_string($_GET['key']??null)?$_GET['key']:'';if(!in_array($infoKey,['about','delivery-returns','privacy','terms'],true)||!Aelvo\Content::active(Aelvo\Content::get($pdo,$infoKey)['value']))$page='not-found';}
if($page==='not-found'){
 http_response_code(404);
 // Aggregate only route identifiers, never raw URLs or arbitrary query strings.
 $badPage=is_string($_GET['page']??null)?$_GET['page']:'';
 $safePage=preg_match('/^[a-z0-9-]{1,40}$/D',$badPage)?$badPage:'invalid';
 $route=$publicPath??'store.php?page='.$safePage;
 if($publicPath===null && $safePage==='product')$route.='&id='.(($requested&&$requested>0&&$requested<=100000000)?$requested:'invalid');
 if($publicPath===null && $safePage==='info'){$badKey=is_string($_GET['key']??null)?$_GET['key']:'';$route.='&key='.(preg_match('/^[a-z0-9-]{1,40}$/D',$badKey)?$badKey:'invalid');}
 // An absent/outdated monitor table must not transform a real 404 into a 503.
 try{Aelvo\Seo::record404($pdo,$route);}catch(PDOException $e){error_log('AELVO SEO 404 monitor database error');}
}
$languageHref=$publicPath!==null && in_array($page,['product','category','brand','cms'],true)
    ?Aelvo\PublicRoutes::localUrl($publicPath).'?lang='.($lang==='en'?'bn':'en')
    :'store.php?'.http_build_query($languageQuery);
$navigation=Aelvo\StorefrontNavigation::build(Aelvo\Taxonomy::listings($pdo,'category'),Aelvo\Taxonomy::listings($pdo,'brand'));
$seo=Aelvo\Seo::metadata($pdo,$page,$byId[$requested]??null,$lang,in_array($page,['category','brand'],true)?$collectionFilters:$filters,$taxonomyPage,$cmsPage);if($page==='chat'){$seo['robots']='noindex, nofollow';$seo['canonical']='';}header('X-Robots-Tag: '.$seo['robots']);
?><!doctype html><html lang="<?=esc($lang)?>"><head><meta charset="utf-8"><base href="<?=esc(Aelvo\PublicRoutes::localUrl())?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=esc($seo['title'])?></title><meta name="description" content="<?=esc($seo['description'])?>"><meta name="robots" content="<?=esc($seo['robots'])?>"><?php if($seo['canonical']!==''&&!in_array($page,['account','checkout','cart','wishlist','tracking','support','chat'],true)):?><link rel="canonical" href="<?=esc($seo['canonical'])?>"><?php endif;?><meta property="og:title" content="<?=esc($seo['og_title'])?>"><meta property="og:description" content="<?=esc($seo['og_description'])?>"><meta property="og:type" content="<?=esc($seo['og_type'])?>"><?php if($seo['og_url']!==''):?><meta property="og:url" content="<?=esc($seo['og_url'])?>"><?php endif;?><?php if($seo['og_image']!==''):?><meta property="og:image" content="<?=esc($seo['og_image'])?>"><?php endif;?><meta name="twitter:card" content="<?=($seo['og_image']!==''?'summary_large_image':'summary')?>"><meta name="twitter:title" content="<?=esc($seo['og_title'])?>"><meta name="twitter:description" content="<?=esc($seo['og_description'])?>"><?php if($seo['og_image']!==''):?><meta name="twitter:image" content="<?=esc($seo['og_image'])?>"><?php endif;?><?php if($page==='product' && isset($byId[$requested])):$schema=Aelvo\Seo::productStructuredData($byId[$requested],$seo,(string)(Aelvo\Seo::config($pdo)['base_url']??''));if($schema!==''):?><script type="application/ld+json"><?=$schema?></script><?php endif;endif;?><?php if($page==='cms' && $cmsPage):$faqSchema=Aelvo\CmsBlocks::faqJsonLd($cmsPage['value']['blocks']??[],$lang);if($faqSchema!==''):?><script type="application/ld+json"><?=$faqSchema?></script><?php endif;endif;?><link rel="stylesheet" href="assets/styles.css"><link rel="stylesheet" href="theme.php"><link rel="stylesheet" href="customer.css"><script src="store.js" defer></script></head><body><?php require __DIR__.'/storefront-header-view.php';?><main id="store-main" tabindex="-1">
<?php if($error):?><div role="alert" class="notice"><?=esc($error)?></div><?php endif;?>
<?php if(!empty($_SESSION['bag_notice'])): unset($_SESSION['bag_notice']); ?>
<div class="bag-confirmation" role="status" aria-live="polite" data-bag-confirmation>
  <span class="bag-confirmation-check" aria-hidden="true">✓</span>
  <span><?=tr('Added to your bag.','ব্যাগে যোগ হয়েছে।')?></span>
  <a class="btn small" href="store.php?page=cart"><?=tr('View bag','ব্যাগ দেখুন')?></a>
  <button type="button" class="bag-confirmation-close" data-dismiss-bag aria-label="<?=tr('Dismiss confirmation','বার্তা বন্ধ করুন')?>">×</button>
</div>
<?php endif;?>
<?php if($page==='not-found')require __DIR__.'/not-found-view.php';?>
<?php if($page!=='not-found')require __DIR__.'/content-view.php';?>
<?php if($page==='tracking')require __DIR__.'/tracking-view.php';?>
<?php if($page==='account')require __DIR__.'/account-view.php';?>
<?php if($page==='wishlist')require __DIR__.'/wishlist-view.php';?>
<?php if($page==='support')require __DIR__.'/support-view.php';?>
<?php if($page==='chat')require __DIR__.'/chat-view.php';?>
<?php if($page==='home'): $heroRecord=Aelvo\Content::get($pdo,'hero');$hero=$heroRecord['value'];if(Aelvo\Content::active($hero)){renderHero($hero);}elseif(!$heroRecord['published_version']){?><section class="hero"><img src="assets/shoe.png" alt="White sneaker on a stone pedestal"><div class="hero-copy"><div class="eyebrow"><?=tr('The everyday collection / 2026','প্রতিদিনের কালেকশন / ২০২৬')?></div><h1><?=tr('Quiet style.<br>Everyday confidence.','নিজস্ব স্টাইল।<br>প্রতিদিনের আত্মবিশ্বাস।')?></h1><p><?=tr('Shoes and accessories that feel like you. Nothing more. Nothing less.','আপনার পছন্দের জুতা ও অ্যাকসেসরিজ।')?></p><a class="btn" href="store.php?page=shop"><?=tr('Explore the collection','কালেকশন দেখুন')?></a></div><div class="hero-foot"><?=tr('Made for your everyday rotation','আপনার প্রতিদিনের জন্য')?></div></section><?php }?><div class="strip"><span><?=tr('Delivery across Bangladesh','সারা বাংলাদেশে ডেলিভারি')?></span><span><?=tr('Cash on delivery available','ক্যাশ অন ডেলিভারি')?></span><span><?=tr('A considered shopping experience','সতর্ক ও যত্নশীল শপিং অভিজ্ঞতা')?></span></div><?php endif;?>
<?php if(in_array($page,['category','brand'],true) && $taxonomyPage):?>
<section class="section collection-section"><nav aria-label="Breadcrumb"><a href="./"><?=tr('Home','হোম')?></a> / <a href="store.php?page=shop"><?=tr('Shop','শপ')?></a> / <?=esc($lang==='bn'&&$taxonomyPage['name_bn']!==''?$taxonomyPage['name_bn']:$taxonomyPage['name'])?></nav>
<h1><?=esc($lang==='bn'&&$taxonomyPage['name_bn']!==''?$taxonomyPage['name_bn']:$taxonomyPage['name'])?></h1>
<?php $collectionDescription=$lang==='bn'&&$taxonomyPage['description_bn']!==''?$taxonomyPage['description_bn']:$taxonomyPage['description'];if($collectionDescription!==''):?><p class="preserve-lines"><?=esc($collectionDescription)?></p><?php endif;?>
<?php if($page==='category'&&$taxonomyPage['parent_id']===null):?>
<nav class="collection-links" aria-label="<?=tr('Subcategories','সাবক্যাটাগরি')?>">
<?php foreach(Aelvo\Taxonomy::listings($pdo,'category') as $child):if($child['status']==='Active' && (int)$child['parent_id']===(int)$taxonomyPage['id']):?><a class="btn secondary" href="<?=esc(Aelvo\PublicRoutes::localUrl(Aelvo\PublicRoutes::entityPath('category',$child['slug'])))?>"><?=esc($lang==='bn'&&$child['name_bn']!==''?$child['name_bn']:$child['name'])?></a><?php endif;endforeach;?></nav>
<?php endif;?>
<p><?=((int)$productCount)?> <?=tr('products','টি পণ্য')?></p><div class="product-grid"><?php foreach($products as $p)card($p);if(!$products)echo '<p>'.tr('No products are currently published in this collection.','এই কালেকশনে এখন কোনো প্রকাশিত পণ্য নেই।').'</p>';?></div>
<?php $current=(int)($collectionFilters['p']??1);$pages=(int)ceil($productCount/24);$path=Aelvo\PublicRoutes::localUrl(Aelvo\PublicRoutes::entityPath($page,$taxonomyPage['slug']));?>
<nav class="pagination" aria-label="<?=tr('Collection pages','কালেকশনের পৃষ্ঠা')?>"><?php if($current>1):?><a class="btn secondary" href="<?=esc($path.($current-1===1?'':'?p='.($current-1)))?>"><?=tr('Previous','আগের')?></a><?php endif;?><?php if($pages>0):?><span><?=$current?> / <?=$pages?></span><?php endif;?><?php if($current<$pages):?><a class="btn secondary" href="<?=esc($path.'?p='.($current+1))?>"><?=tr('Next','পরের')?></a><?php endif;?></nav></section>
<?php endif;?>
<?php if($page==='home'):require __DIR__.'/storefront-home-view.php';
elseif($page==='shop'):?><section class="section"><div class="section-heading"><h1><?=tr('All essentials.','সব পণ্য।')?></h1></div><?php require __DIR__.'/filters-view.php';?>
<?php require __DIR__.'/storefront-collections-view.php';?><div class="product-grid"><?php foreach($products as $p)card($p);if(!$products)echo '<p>'.tr('The collection is being prepared. Please check back soon.','কালেকশন প্রস্তুত হচ্ছে। আবার দেখুন।').'</p>';?></div><?php require __DIR__.'/pagination-view.php';?></section>
<?php elseif($page==='product'):
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);$p=$byId[$id]??null;
if(!$p){require __DIR__.'/not-found-view.php';}else{?>
<section class="page product-page"><nav class="product-breadcrumb" aria-label="<?=tr('Breadcrumb','পথ নির্দেশনা')?>"><a href="./"><?=tr('Home','হোম')?></a><span aria-hidden="true">/</span><a href="store.php?page=shop"><?=tr('Shop','শপ')?></a><span aria-hidden="true">/</span><?php if(!empty($p['category_slug'])):?><a href="<?=esc(Aelvo\PublicRoutes::localUrl(Aelvo\PublicRoutes::entityPath('category',$p['category_slug'])))?>"><?=esc($p['category'])?></a><span aria-hidden="true">/</span><?php endif;?><strong aria-current="page"><?=esc(nameOf($p))?></strong></nav><div class="product-detail"><?php require __DIR__ . '/product-gallery-view.php'; ?><div class="detail-copy"><span class="eyebrow"><?php if(!empty($p['category_slug'])):?><a href="<?=esc(Aelvo\PublicRoutes::localUrl(Aelvo\PublicRoutes::entityPath('category',$p['category_slug'])))?>"><?=esc($p['category'])?></a><?php else:?><?=esc($p['category'])?><?php endif;?></span><?php if(!empty($p['brand_slug'])):?><p><a href="<?=esc(Aelvo\PublicRoutes::localUrl(Aelvo\PublicRoutes::entityPath('brand',$p['brand_slug'])))?>"><?=esc($p['brand_name'])?></a></p><?php endif;?><h1><?=esc(nameOf($p))?></h1><p class="detail-price" data-product-price data-currency-prefix="৳" aria-live="polite"><?=($p['variant_price_range']['min']??$p['price_minor'])!==($p['variant_price_range']['max']??$p['price_minor'])?tr('From','শুরু'):''?> ৳<?=number_format((int)($p['variant_price_range']['min']??$p['price_minor'])/100,2)?></p><p data-product-regular hidden></p><p class="description preserve-lines"><?=esc($lang==='bn'&&$p['description_bn']?$p['description_bn']:$p['description'])?></p><?php require __DIR__.'/product-facts-view.php';?><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><?php require __DIR__.'/variant-picker-view.php';?><label><?=tr('Quantity','পরিমাণ')?><input name="qty" type="number" value="1" min="1" max="100" required></label><div class="buy-row"><button class="btn secondary" name="action" value="bag" <?=array_sum(array_column($p['variants'],'available'))<1?'disabled':''?>><?=tr('Add to bag','ব্যাগে যোগ করুন')?></button><button class="btn" name="action" value="buy-now" <?=array_sum(array_column($p['variants'],'available'))<1?'disabled':''?>><?=tr('Buy it now','এখনই কিনুন')?></button></div></form><?php wishlistButton((int)$p['id']);?><h2><?=tr('Product information','পণ্যের তথ্য')?></h2><p class="preserve-lines"><?=esc($p['details'])?></p></div></div><?php require __DIR__.'/reviews-view.php';?><section class="product-section"><h2><?=tr('You may also like','আপনার পছন্দ হতে পারে')?></h2><div class="product-grid"><?php foreach(Aelvo\Catalogue::relatedProducts($pdo,(int)$p['id'],(string)$p['category'],3) as $related)card($related); ?></div></section></section>
<?php } elseif($page==='checkout' && $checkoutId!==''): require __DIR__.'/checkout-view.php';
elseif($page==='cart'||$page==='checkout'): require __DIR__.'/bag-checkout-view.php'; endif;?>
<?php require __DIR__.'/popup-view.php';?>
</main><?php require __DIR__.'/storefront-footer-view.php';?><?php require __DIR__.'/navigation-view.php';?></body></html>
