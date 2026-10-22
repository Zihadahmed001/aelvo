<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
use Aelvo\{Customers,Checkout,Reviews,Settings,Content,Orders,Catalogue};
if(!str_ends_with($config['db']['name'],'_test'))throw new RuntimeException("Use a dedicated empty _test database.\n");
$tables=['aelvo_review_photos','aelvo_bag_merges','aelvo_customer_bag','aelvo_wishlist','aelvo_return_request_events','aelvo_return_request_photos','aelvo_return_request_receipts','aelvo_customer_return_requests','aelvo_refund_requests','aelvo_return_dispositions','aelvo_refunds','aelvo_returns','aelvo_order_receipts','aelvo_payment_proofs','aelvo_order_finance','aelvo_customer_addresses','aelvo_customer_security','aelvo_tracking_challenges','aelvo_order_events','aelvo_coupon_redemptions','aelvo_coupons','aelvo_reviews','aelvo_customer_orders','aelvo_customer_challenges','aelvo_customers','aelvo_checkouts','aelvo_movements','aelvo_order_items','aelvo_orders','aelvo_variants','aelvo_product_media','aelvo_products','aelvo_content_media','aelvo_media','aelvo_content','aelvo_settings','aelvo_audit','aelvo_login_attempts','aelvo_users'];
foreach($tables as $table)if((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException("Test database must be empty.\n");
// Buffer test output so session ID rotations can be exercised before headers are sent.
ob_start();session_start();$_SESSION=['csrf'=>bin2hex(random_bytes(32)),'checkout_identity'=>'initial-test-session'];
function check(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);echo 'PASS '.$m."\n";}
function denied(callable $fn,string $m):void{try{$fn();}catch(InvalidArgumentException|RuntimeException $e){check(true,$m);return;}throw new RuntimeException('Expected rejection: '.$m);}
try{
 $pdo->exec("INSERT INTO aelvo_users(name,email,password_hash) VALUES ('Owner','owner@example.invalid','unused')");$owner=['id'=>(int)$pdo->lastInsertId(),'status'=>'Active','is_owner'=>true];
 (new Settings($pdo,$owner))->delivery(['version'=>0,'cod_enabled'=>'1','dhaka'=>'60','outside'=>'120']);
 $pdo->exec("INSERT INTO aelvo_products(name,slug,category,description,description_bn,details,price_minor,status) VALUES ('Test shoe','customer-test','Shoes','','','',10000,'Active')");$product=(int)$pdo->lastInsertId();
 $q=$pdo->prepare("INSERT INTO aelvo_variants(product_id,sku,colour,colour_hex,size,stock) VALUES (?,'CUSTOMER-TEST','White','#ffffff','42',20)");$q->execute([$product]);$variant=(int)$pdo->lastInsertId();
 $sent='';$sender=function($phone,$code)use(&$sent){$sent=$code;};$customers=new Customers($pdo,$_SESSION['checkout_identity']);
 $signup=['name'=>'Customer','phone'=>'01700000000','password'=>'Customer password 123!','confirm'=>'Customer password 123!'];
 $challenge=$customers->challenge($signup,'signup','test-ip',$sender);
 check((int)$pdo->query('SELECT COUNT(*) FROM aelvo_customers')->fetchColumn()===0,'Signup does not create account before OTP');
 $wrong=$sent==='111111'?'222222':'111111';denied(fn()=> $customers->verify($challenge,$wrong),'Wrong signup OTP rejected');
 check((int)$pdo->query('SELECT attempts FROM aelvo_customer_challenges')->fetchColumn()===1,'Wrong signup attempt persists');
 denied(fn()=> (new Customers($pdo,'other-session'))->verify($challenge,$sent),'Another session cannot use signup OTP');
 $customers->verify($challenge,$sent);$buyer=Customers::current($pdo);check($buyer!==null,'Verified signup creates a usable customer');
 denied(fn()=> $customers->verify($challenge,$sent),'Signup OTP replay rejected');
 $checkout=new Checkout($pdo,$_SESSION['checkout_identity']);$input=['customer_name'=>'Customer','phone'=>'01700000000','address'=>'Test address','zone'=>'dhaka'];
 $id=$checkout->quote($input,[$variant=>1],false);check($checkout->verified($checkout->get($id)),'Signed-in matching verified phone needs no new OTP');
 $order=$checkout->place($id);check($checkout->place($id)===$order,'Account order is idempotent');
 check(count($customers->orders((int)$buyer['id']))===1,'Only authenticated checkout creates account order link');
 $alternate=$checkout->quote([...$input,'phone'=>'01800000000'],[$variant=>1],false);check(!$checkout->verified($checkout->get($alternate)),'Alternative contact requires checkout OTP');
 denied(fn()=> $checkout->place($alternate),'Unverified alternative contact cannot place order');
 $checkout->sendCode($alternate,'test-ip',$sender);$checkout->verify($alternate,$sent);$checkout->place($alternate);
 check(Customers::current($pdo)['phone']==='01700000000','Alternative contact does not mutate primary phone');
 $item=(int)$pdo->query('SELECT MIN(id) FROM aelvo_order_items')->fetchColumn();
 denied(fn()=> Reviews::submit($pdo,(int)$buyer['id'],['order_item_id'=>$item,'rating'=>'5','body'=>'Good']),'Undelivered order cannot be reviewed');
 $orders=new Orders($pdo,$owner);$orders->transition($order,'Confirmed');$orders->transition($order,'Dispatched');$orders->transition($order,'Delivered');
 $q=$pdo->prepare("INSERT INTO aelvo_customers(name,phone,password_hash,verified_at) VALUES ('Other','01900000000','unused',?)");$q->execute([time()]);$other=(int)$pdo->lastInsertId();
 denied(fn()=> Reviews::submit($pdo,$other,['order_item_id'=>$item,'rating'=>'5','body'=>'Not my order']),'Another customer cannot review the order');
 Reviews::submit($pdo,(int)$buyer['id'],['order_item_id'=>$item,'rating'=>'5','body'=>'Original customer words']);
 check(Reviews::published($pdo,$product)===[],'Pending review is not public');
 $review=(int)$pdo->query('SELECT id FROM aelvo_reviews')->fetchColumn();
 (new Reviews($pdo,$owner))->moderate(['id'=>$review,'version'=>1,'status'=>'Approved','reply'=>'Thank you']);
 check(Reviews::published($pdo,$product)[0]['body']==='Original customer words','Moderation preserves customer text');
 (new Reviews($pdo,$owner))->moderate(['id'=>$review,'version'=>2,'status'=>'Hidden','reply'=>'Thank you']);check(Reviews::published($pdo,$product)===[],'Hidden review removed from public results');
 // 14 October extension. Prepared for the final test stage; not executed in development.
 Aelvo\Wishlist::save($pdo,['product_id'=>$product,'mode'=>'save']);
 Aelvo\Wishlist::save($pdo,['product_id'=>$product,'mode'=>'save']);
 check(Aelvo\Wishlist::ids($pdo,(int)$buyer['id'])===[$product],'Wishlist repeated save is idempotent');
 check(Aelvo\Wishlist::ids($pdo,$other)===[],'Wishlist is isolated by customer');
 Aelvo\Wishlist::save($pdo,['product_id'=>$product,'mode'=>'remove']);
 check(Aelvo\Wishlist::ids($pdo,(int)$buyer['id'])===[],'Wishlist removes only owned association');
 $_SESSION['bag']=[$variant=>2];unset($_SESSION['bag_owner']);
 Aelvo\SavedBag::hydrate($pdo,$buyer);unset($_SESSION['bag_owner']);
 Aelvo\SavedBag::hydrate($pdo,$buyer);
 check(Aelvo\SavedBag::read($pdo,(int)$buyer['id'])===[$variant=>2],'Interrupted guest merge cannot double the saved bag');
 Aelvo\SavedBag::mutate($pdo,$variant,1);
 check(Aelvo\SavedBag::read($pdo,(int)$buyer['id'])===[$variant=>3],'Saved bag mutation persists');
 $pdo->beginTransaction();Aelvo\SavedBag::lock($pdo,(int)$buyer['id']);Aelvo\SavedBag::purchased($pdo,(int)$buyer['id'],[['variant_id'=>$variant,'qty'=>1]]);$pdo->rollBack();
 check(Aelvo\SavedBag::read($pdo,(int)$buyer['id'])===[$variant=>3],'Failed order transaction restores saved bag');
 $requestData=['order_item_id'=>$item,'qty'=>1,'reason'=>'Wrong fit','request_key'=>str_repeat('a',64)];
 $requestId=Aelvo\ReturnRequests::submit($pdo,$requestData,[]);
 check(Aelvo\ReturnRequests::submit($pdo,$requestData,[])===$requestId,'Return request replay uses original request');
 denied(fn()=>Aelvo\ReturnRequests::submit($pdo,[...$requestData,'request_key'=>str_repeat('b',64)],[]),'Pending return prevents excess quantity commitment');
 $before=(int)$pdo->query('SELECT stock FROM aelvo_variants WHERE id='.$variant)->fetchColumn();
 (new Aelvo\ReturnRequests($pdo,$owner))->review(['return_request_id'=>$requestId,'version'=>1,'decision'=>'Accepted','review_note'=>'Return the item for inspection.']);
 check((int)$pdo->query('SELECT stock FROM aelvo_variants WHERE id='.$variant)->fetchColumn()===$before,'Accepting request does not restock');
 denied(fn()=>(new Aelvo\ReturnRequests($pdo,$owner))->review(['return_request_id'=>$requestId,'version'=>1,'decision'=>'Rejected','review_note'=>'Stale review']),'Stale request review rejected');
 $receive=['order_item_id'=>$item,'return_request_id'=>$requestId,'qty'=>1,'sellable_qty'=>1,'received_variant_id'=>$variant,'reason'=>'Wrong fit','inspection'=>'Correct item received in sellable condition.','confirmed'=>'1','request_key'=>str_repeat('c',64)];
 $received=(new Aelvo\Returns($pdo,$owner))->receive($receive);
 check((new Aelvo\Returns($pdo,$owner))->receive($receive)===$received,'Linked physical receipt is idempotent');
 check(Aelvo\ReturnRequests::forCustomer($pdo,(int)$buyer['id'])[0]['status']==='Received','Physical receipt completes exact accepted request');
 check((int)$pdo->query('SELECT stock FROM aelvo_variants WHERE id='.$variant)->fetchColumn()===$before,'Physical receipt does not automatically restock');
 (new Aelvo\Returns($pdo,$owner))->restock($received);(new Aelvo\Returns($pdo,$owner))->restock($received);
 check((int)$pdo->query('SELECT stock FROM aelvo_variants WHERE id='.$variant)->fetchColumn()===$before+1,'Inspected stock is posted once');
 check((int)$pdo->query('SELECT COUNT(*) FROM aelvo_refunds')->fetchColumn()===0,'Customer request and receipt never create a refund');
 $accountId=(int)$buyer['id'];Customers::logout();$guest=new Checkout($pdo,$_SESSION['checkout_identity']);$guestId=$guest->quote($input,[$variant=>1],false);
 check(!$guest->verified($guest->get($guestId)),'Guest using a registered phone still requires OTP');
 $guest->sendCode($guestId,'guest-ip',$sender);$guest->verify($guestId,$sent);$guest->place($guestId);
 check(count($customers->orders($accountId))===2,'Guest order does not merge into matching phone account');
 $content=new Content($pdo,$owner);$draft=['content_key'=>'hero','version'=>0,'enabled'=>'1','heading'=>'Original','destination'=>'store.php?page=shop','delay'=>3];
 $content->save($draft);check(Content::get($pdo,'hero')['value']===[],'Saving draft does not publish content');
 denied(fn()=> $content->publish(['content_key'=>'hero','version'=>1]),'Publication requires explicit confirmation');
 $content->publish(['content_key'=>'hero','version'=>1,'confirm'=>'1']);
 $content->save([...$draft,'version'=>2,'heading'=>'New draft']);check(Content::get($pdo,'hero')['value']['heading']==='Original','Draft changes leave published banner intact');
 denied(fn()=> $content->publish(['content_key'=>'hero','version'=>2,'confirm'=>'1']),'Stale publish cannot replace a newer draft');
 denied(fn()=> Content::destination('javascript:alert(1)'),'Unsafe banner destination rejected');
 denied(fn()=> $content->save([...$draft,'version'=>3,'media_type'=>'video']),'Enabled video requires poster and uploaded video');
 check(Catalogue::count($pdo,['q'=>'Test','category'=>'Shoes','stock'=>'1'])===1,'Search filters combine with stock and category');
 check(Catalogue::count($pdo,['q'=>'%'])===0,'Search wildcard is treated literally');
 echo "Customer/content tests complete. No real SMS sent.\n";
}finally{
 foreach($tables as $table)$pdo->exec('DELETE FROM '.$table);
 session_destroy();ob_end_flush();
}
