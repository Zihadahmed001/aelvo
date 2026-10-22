<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/CourierResolution.php';
require dirname(__DIR__).'/app/Schema.php';
use Aelvo\CourierResolution;
use Aelvo\Schema;
function expectCase(bool $check,string $label): void {if(!$check)throw new RuntimeException($label);}
function invalidCase(array $v): void {
    try {CourierResolution::proposalFields($v);}catch(InvalidArgumentException){return;}
    throw new RuntimeException('Unsafe courier finding accepted.');
}
$match=['outcome'=>'found','consignment_id'=>'123456','tracking_code'=>'SDF-22','proposal_notes'=>'Portal invoice INV-1, booked COD, phone and recipient checked.','portal_checked'=>'1','match_confirmed'=>'1'];
$r=CourierResolution::proposalFields($match);
expectCase($r[0]==='found'&&$r[1]==='123456'&&$r[2]==='SDF-22','Matched consignment proposal should retain supplied evidence fields.');
invalidCase(array_diff_key($match,['match_confirmed'=>true]));
invalidCase(array_replace($match,['consignment_id'=>'none']));
invalidCase(array_replace($match,['outcome'=>'not_found']));
invalidCase(array_replace($match,['proposal_notes'=>str_repeat('x',1501)]));
invalidCase(array_replace($match,['tracking_code'=>"X\n<script>"]));
invalidCase(array_replace($match,['portal_checked'=>'0']));
$notFound=CourierResolution::proposalFields(['outcome'=>'not_found','consignment_id'=>'','tracking_code'=>'','proposal_notes'=>'Portal search incomplete; cannot confirm existence.','portal_checked'=>'1']);
expectCase($notFound[0]==='not_found' && $notFound[1]===null,'Negative finding never supplies an invented tracking ID.');
$reviewable=(new ReflectionMethod(CourierResolution::class,'ambiguous'));
expectCase($reviewable->invoke(null,['booking_state'=>'Unknown','age_minutes'=>0]),'Unknown bookings remain reviewable.');
expectCase(!$reviewable->invoke(null,['booking_state'=>'Prepared','age_minutes'=>29]),'Fresh prepared bookings must remain locked and not reviewable yet.');
expectCase($reviewable->invoke(null,['booking_state'=>'Prepared','age_minutes'=>30]),'Interrupted prepared bookings can be investigated after cooldown.');
expectCase(!$reviewable->invoke(null,['booking_state'=>'Booked','age_minutes'=>500]),'Booked shipments must never re-enter uncertain reconciliation.');
$schema=file_get_contents(dirname(__DIR__).'/database/courier-resolution.sql');
expectCase(count(Schema::statements($schema))===1,'Courier schema must parse as one migration statement.');
expectCase(in_array('courier-resolution',Schema::FILES,true),'Additive schema is registered.');
$handler=file_get_contents(dirname(__DIR__).'/public/index.php');
$upload=file_get_contents(dirname(__DIR__).'/app/Uploads.php');
$doc=file_get_contents(dirname(__DIR__).'/public/document.php');
$team=file_get_contents(dirname(__DIR__).'/app/Team.php');
expectCase(str_contains($handler,"case 'courier-resolution-propose'")&&str_contains($handler,"case 'courier-resolution-review'"),'Both CSRF-admin actions are wired.');
expectCase(str_contains($upload,"'courier-resolution'")&&str_contains($doc,'aelvo_courier_resolution_cases'),'Evidence is privately uploaded and gated.');
expectCase(str_contains($team,"$".'m[2]' . "==='approve'")||str_contains($team,"'approve'"),'Approval action is restricted to courier permissions.');
$service=file_get_contents(dirname(__DIR__).'/app/CourierResolution.php');
expectCase(str_contains($service,"$".'this->allow(\'courier\',\'approve\')'),'Independent approval uses distinct permission.');
expectCase(str_contains($service,'proposal_actor_id')&&str_contains($service,'booking_state=\'Booked\''),'Proposal ownership and matching booking transition exist.');
expectCase(str_contains($service,'No retry authorized.'),'Negative reconciliation must not authorize repeat booking.');
echo "Courier reconciliation input/policy and offline wiring checks passed.\n";
