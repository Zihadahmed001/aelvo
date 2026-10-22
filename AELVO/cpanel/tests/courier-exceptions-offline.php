<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/CourierExceptions.php';
require dirname(__DIR__).'/app/Schema.php';
use Aelvo\CourierExceptions;
use Aelvo\Schema;
function insist(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message); }
function rejects(array $input): void {
    try {CourierExceptions::fields($input);}catch(InvalidArgumentException){return;}
    throw new RuntimeException('Invalid courier exception accepted');
}
$valid=['exception_type'=>'delivery_failed','portal_reference'=>'CASE-12345','exception_notes'=>'Attempt logged in merchant portal; confirm actual return separately.','portal_checked'=>'1','no_financial_action'=>'1'];
insist(CourierExceptions::fields($valid)[0]==='delivery_failed','Valid exception fields lost');
insist(CourierExceptions::fields(array_replace($valid,['exception_notes'=>"Portal reviewed.\nCustomer not reached."]))[0]==='delivery_failed','Staff notes should allow newlines');
foreach(['unsupported','cancellation_completed','returned_to_inventory'] as $kind)rejects(array_replace($valid,['exception_type'=>$kind]));
rejects(array_diff_key($valid,['no_financial_action'=>true]));
rejects(array_diff_key($valid,['portal_checked'=>true]));
rejects(array_replace($valid,['portal_reference'=>"BAD\nREF"]));
rejects(array_replace($valid,['exception_notes'=>str_repeat('x',1501)]));
rejects(array_replace($valid,['exception_type'=>'provider_cancelled']));
insist(CourierExceptions::fields(array_replace($valid,['exception_type'=>'provider_cancelled','final_status_checked'=>'1']))[0]==='provider_cancelled','Verified provider cancellation rejected');
foreach(['Dispatched','Delivered'] as $target){
    insist(!CourierExceptions::permitted($target,'Unknown','pending',false),'Unknown booking must block fulfillment');
    insist(!CourierExceptions::permitted($target,'Prepared','pending',false),'Interrupted booking must block fulfillment');
    insist(!CourierExceptions::permitted($target,'Booked','cancelled',false),'Cancelled shipment must block fulfillment');
    insist(!CourierExceptions::permitted($target,'Booked','cancelled_approval_pending',false),'Pending cancellation must block fulfillment');
    insist(!CourierExceptions::permitted($target,'Booked','delivered',true),'Open reviewed exception must block fulfillment');
    insist(!CourierExceptions::permitted($target,'Booked','partial_delivered',false),'Partial delivery requires manual reconciliation');
}
insist(CourierExceptions::permitted('Dispatched','Booked','pending',false),'Booked pending shipment may be dispatched');
insist(!CourierExceptions::permitted('Delivered','Booked','pending',false),'Cannot deliver without provider delivered confirmation');
insist(CourierExceptions::permitted('Delivered','Booked','delivered',false),'Confirmed delivered shipment with no exception may fulfill');
insist(CourierExceptions::permitted('Cancelled','Unknown','',true),'Only dedicated cancellation safeguards may handle cancellation');
$schema=file_get_contents(dirname(__DIR__).'/database/courier-exceptions.sql');
insist(count(Schema::statements($schema))===2,'Exception schema has invalid statement count');
insist(in_array('courier-exceptions',Schema::FILES,true),'Courier exception migration still registered');
$files=['app/bootstrap.php'=>'CourierExceptions','app/Orders.php'=>'CourierExceptions::assertFulfillment','app/CourierAccounting.php'=>'Active courier failure/cancellation exception','public/index.php'=>"case 'courier-exception-review'",'public/document.php'=>'aelvo_courier_exception_cases','app/Uploads.php'=>"'courier-exceptions'",'app/AdminView.php'=>'courier-exception-propose'];
foreach($files as $path=>$piece)insist(str_contains(file_get_contents(dirname(__DIR__).'/'.$path),$piece),'Missing wired component '.$path);
$service=file_get_contents(dirname(__DIR__).'/app/CourierExceptions.php');
foreach(['proposal_actor_id','review_actor_id','FOR UPDATE','request_hash','courier','approve'] as $piece)insist(str_contains($service,$piece),'Missing exception review safeguard '.$piece);
insist(!str_contains($service,'Steadfast::request('),'Exception handling must not invoke remote cancellation or booking');
foreach(['aelvo_ledger','aelvo_movements','UPDATE aelvo_orders','UPDATE aelvo_variants'] as $piece)insist(!str_contains($service,$piece),'Exception evidence must not modify money, orders, or inventory');
insist(str_contains(file_get_contents(dirname(__DIR__).'/public/index.php'),"case 'courier-clearance-review'"),'Clearance review missing route');
echo "Courier exceptions: validation, fulfillment holds, two-person review wiring and safe SQL parsing passed (offline only).\n";
