<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/Security.php';
require dirname(__DIR__).'/app/Support.php';
require dirname(__DIR__).'/app/Reports.php';
require dirname(__DIR__).'/app/Schema.php';
use Aelvo\Support;
use Aelvo\Reports;
use Aelvo\Schema;
function verify(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function invalid(callable $callback,string $message):void {try{$callback();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Expected validation failure: '.$message);}
$base=['topic'=>'bug','customer_name'=>'Example Customer','email'=>'example@example.org','phone'=>'','subject'=>'The product page does not load','message'=>'The page cannot be opened from the shopping bag.','order_reference'=>'AEL-123','request_key'=>str_repeat('a',64)];
$valid=Support::validate($base,null);
verify($valid['topic']==='bug'&&$valid['customer_id']===null,'Guest has no inferred customer ownership');
invalid(fn()=>Support::validate([...$base,'email'=>'','phone'=>''],null),'Guest must supply contact');
invalid(fn()=>Support::validate([...$base,'phone'=>'01700000000','email'=>'bad-address'],null),'Invalid email');
invalid(fn()=>Support::validate([...$base,'topic'=>'payment_success'],null),'Unknown topic');
invalid(fn()=>Support::validate([...$base,'order_reference'=>'../../private'],null),'Reference injection');
invalid(fn()=>Support::validate([...$base,'request_key'=>str_repeat('z',64)],null),'Invalid key');
invalid(fn()=>Support::validate([...$base,'message'=>'short'],null),'Empty problem details');
$valid=Support::validate([...$base,'email'=>'','phone'=>'01700000000'],['id'=>17]);
verify($valid['customer_id']===17&&$valid['phone']==='01700000000','Explicit signed-in identity only');
[$from,$to]=Reports::dates('2026-10-01','2026-10-21');
verify($from==='2026-10-01 00:00:00'&&$to==='2026-10-22 00:00:00','Inclusive UTC reporting window');
invalid(fn()=>Reports::dates('2026-02-30','2026-03-01'),'Invalid calendar date');
invalid(fn()=>Reports::dates('2026-10-22','2026-10-01'),'Backward range');
invalid(fn()=>Reports::dates('2024-01-01','2026-10-01'),'Unbounded report');
verify(Reports::csvCell('=HYPERLINK("http://bad")')[0]==="'",'CSV formula escape');
verify(Reports::csvCell(' @SUM(A1)')[0]==="'",'CSV leading whitespace formula escape');
verify(Reports::csvCell('AEL-100')==='AEL-100','Ordinary order reference unaffected');
$sql=file_get_contents(dirname(__DIR__).'/database/support-inbox.sql');
$parsed=Schema::statements($sql);
verify(count($parsed)===3&&str_contains($parsed[0],'aelvo_support_tickets'),'Support schema parser');
verify(in_array('support-inbox',Schema::FILES,true),'Support schema in ordered migration');
echo "Support validation, reporting dates, CSV safety and support SQL parsing passed (offline; no MySQL runtime).\n";
