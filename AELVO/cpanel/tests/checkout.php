<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
use Aelvo\Checkout;
use Aelvo\Settings;
use Aelvo\Orders;
if(!str_ends_with($config['db']['name'],'_test'))throw new RuntimeException("Use an isolated database ending in _test.\n");
foreach(['aelvo_users','aelvo_products','aelvo_orders','aelvo_settings','aelvo_checkouts','aelvo_login_attempts','aelvo_audit','aelvo_roles','aelvo_accounts','aelvo_media','aelvo_purchases'] as $table)if((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException("Use an EMPTY test database. Fixtures are removed after the test.\n");
function ok(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);echo 'PASS '.$message."\n";}
function rejects(callable $fn,string $message):void{try{$fn();}catch(InvalidArgumentException $e){ok(true,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
$owner=null;$product=null;$variant=null;
try{
 $q=$pdo->prepare('INSERT INTO aelvo_users(name,email,password_hash) VALUES (?,?,?)');$q->execute(['Test owner','checkout@example.invalid',password_hash('Unused-test-password-123',PASSWORD_DEFAULT)]);$owner=(int)$pdo->lastInsertId();
 $actor=['id'=>$owner,'status'=>'Active','is_owner'=>true];
 (new Settings($pdo,$actor))->delivery(['version'=>0,'cod_enabled'=>'1','dhaka'=>'60','outside'=>'120']);
 $pdo->exec("INSERT INTO aelvo_products(name,slug,category,description,description_bn,details,price_minor,status) VALUES ('Test shoe','checkout-fixture','Shoes','','','',10000,'Active')");$product=(int)$pdo->lastInsertId();
 $q=$pdo->prepare("INSERT INTO aelvo_variants(product_id,sku,colour,colour_hex,size,stock) VALUES (?,'CHECKOUT-TEST','White','#ffffff','42',5)");$q->execute([$product]);$variant=(int)$pdo->lastInsertId();
 $checkout=new Checkout($pdo,'test-session');$other=new Checkout($pdo,'other-session');
 $input=['customer_name'=>'Test buyer','phone'=>'+8801700000000','address'=>'Test address','zone'=>'dhaka'];
 $new=fn()=> $checkout->quote($input,[$variant=>2],false);
 $id=$new();$r=$checkout->get($id);
 ok((int)$r['total_minor']===26000,'Quote calculates server prices and delivery');
 ok($r['data']['phone']==='01700000000','Phone normalized');
 rejects(fn()=> $other->get($id),'Another session cannot inspect checkout');
 rejects(fn()=> $checkout->place($id),'Unverified checkout cannot reserve stock');
 rejects(fn()=> Checkout::lines([$variant=>101]),'Excessive quantity rejected');
 $code='';$send=function($phone,$value)use(&$code){$code=$value;};
 $checkout->sendCode($id,'test-ip',$send);
 ok(strlen($code)===6,'OTP provided only to sender adapter');
 rejects(fn()=> $checkout->sendCode($id,'test-ip',$send),'Resend cooldown enforced');
 $wrong=$code==='111111'?'222222':'111111';
 rejects(fn()=> $checkout->verify($id,$wrong),'Wrong OTP rejected');
 ok((int)$checkout->get($id)['otp_attempts']===1,'Wrong OTP attempt persists despite exception');
 $checkout->verify($id,$code);ok($checkout->get($id)['otp_hash']===null,'Verified OTP hash consumed');
 $order=$checkout->place($id);ok($checkout->place($id)===$order,'Repeated confirmation returns same order');
 ok((int)$pdo->query('SELECT COUNT(*) FROM aelvo_orders')->fetchColumn()===1,'No duplicate order inserted');
 ok((int)$pdo->query('SELECT reserved FROM aelvo_variants')->fetchColumn()===2,'Inventory reserved exactly once');
 ok($pdo->query('SELECT payment_status FROM aelvo_orders')->fetchColumn()==='Unpaid','COD confirmation does not mark payment settled');
 (new Orders($pdo,$actor))->transition($order,'Cancelled');
 ok((int)$pdo->query('SELECT reserved FROM aelvo_variants')->fetchColumn()===0,'Cancellation releases reservation');
 $second=$new();$checkout->sendCode($second,'test-ip',$send);
 $wrong=$code==='111111'?'222222':'111111';
 for($i=0;$i<5;$i++)rejects(fn()=> $checkout->verify($second,$wrong),'Incorrect verification rejected');
 rejects(fn()=> $checkout->verify($second,$code),'Correct code blocked after attempt limit');
 $third=$new();$checkout->sendCode($third,'test-ip',$send);$checkout->verify($third,$code);
 $pdo->exec('UPDATE aelvo_products SET price_minor=20000');
 rejects(fn()=> $checkout->place($third),'Changed price requires new customer review');
 ok((int)$pdo->query('SELECT reserved FROM aelvo_variants')->fetchColumn()===0,'Rejected order leaves stock unchanged');
 $pdo->exec('UPDATE aelvo_products SET price_minor=10000');
 $fourth=$new();$checkout->sendCode($fourth,'test-ip',$send);$checkout->verify($fourth,$code);
 $pdo->exec('UPDATE aelvo_variants SET stock=1');
 rejects(fn()=> $checkout->place($fourth),'Stock loss before confirmation rejects order');
 $pdo->exec('UPDATE aelvo_variants SET stock=5');
 $fifth=$new();$checkout->sendCode($fifth,'test-ip',$send);$checkout->verify($fifth,$code);
 (new Settings($pdo,$actor))->delivery(['version'=>1,'cod_enabled'=>'1','dhaka'=>'70','outside'=>'120']);
 rejects(fn()=> $checkout->place($fifth),'Delivery fee change requires new review');
 $q=$pdo->prepare('UPDATE aelvo_checkouts SET expires_at=0 WHERE id=?');$q->execute([$fifth]);
 rejects(fn()=> $checkout->get($fifth),'Expired checkout rejected');
 $pdo->exec('UPDATE aelvo_login_attempts SET expires_at=0');
 $sixth=$new();$checkout->sendCode($sixth,'test-ip',$send);
 $q=$pdo->prepare('UPDATE aelvo_checkouts SET otp_expires_at=0 WHERE id=?');$q->execute([$sixth]);
 rejects(fn()=> $checkout->verify($sixth,$code),'Expired OTP cannot verify checkout');
 echo "Checkout checks complete. No real SMS was sent.\n";
}finally{
 // Guard above ensures a dedicated empty database; all rows below are test fixtures.
 foreach(['aelvo_refund_requests','aelvo_return_dispositions','aelvo_refunds','aelvo_returns','aelvo_order_receipts','aelvo_payment_proofs','aelvo_order_finance','aelvo_customer_addresses','aelvo_customer_security','aelvo_tracking_challenges','aelvo_order_events','aelvo_coupon_redemptions','aelvo_coupons','aelvo_checkouts','aelvo_movements','aelvo_order_items','aelvo_orders','aelvo_variants','aelvo_products','aelvo_settings','aelvo_audit','aelvo_login_attempts','aelvo_users'] as $table)$pdo->exec('DELETE FROM '.$table);
}
