<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/Security.php';
require dirname(__DIR__).'/app/Support.php';
require dirname(__DIR__).'/app/Team.php';
require dirname(__DIR__).'/app/Schema.php';
use Aelvo\Support;
use Aelvo\Schema;
use Aelvo\Security;
function check(bool $ok,string $note):void{if(!$ok)throw new RuntimeException($note);}
function rejects(callable $f,string $message):void{try{$f();}catch(InvalidArgumentException){return;}throw new RuntimeException('Accepted invalid '.$message);}
check(Support::publicMessage(['public_message'=>'  Your order is being reviewed. '])==='Your order is being reviewed.','Trim public message');
check(Support::publicMessage([])==='','Optional message');
rejects(fn()=>Support::publicMessage(['public_message'=>str_repeat('X',2001)]),'2001-byte message');
rejects(fn()=>Support::publicMessage(['public_message'=>['wrong'=>'type']]),'non-string message');
check(Security::sessionIsCurrent(['staff_revision'=>2],['session_revision'=>2]),'Current signed-in staff session');
check(!Security::sessionIsCurrent(['staff_revision'=>2],['session_revision'=>3]),'Revoked staff session');
check(!Security::sessionIsCurrent([],['session_revision'=>0]),'Unversioned legacy session must log in');
check(!Security::sessionIsCurrent(['staff_revision'=>'2'],['session_revision'=>2]),'Reject untyped session revision');
$sql=file_get_contents(dirname(__DIR__).'/database/support-public-updates.sql');
$statements=Schema::statements($sql);
check(count($statements)===1&&str_contains($statements[0],'aelvo_support_public_updates'),'Public-update table SQL boundaries');
check(str_contains($statements[0],'FOREIGN KEY (ticket_id)')&&str_contains($statements[0],'FOREIGN KEY (actor_id)'),'Reference integrity');
$sql=file_get_contents(dirname(__DIR__).'/database/staff-sessions.sql');
$statements=Schema::statements($sql);
check(count($statements)===1&&str_contains($statements[0],'aelvo_staff_session_revisions'),'Staff-session table');
check(in_array('support-public-updates',Schema::FILES,true)&&in_array('staff-sessions',Schema::FILES,true),'New tables included in migrations');
check(array_search('staff-sessions',Schema::FILES,true)>array_search('core',Schema::FILES,true),'Dependent table migration order');
check(array_search('support-public-updates',Schema::FILES,true)>array_search('support-inbox',Schema::FILES,true),'Support FK migration order');
// Static boundary assertions supplement the pure checks above. No DB tests are claimed.
$source=file_get_contents(dirname(__DIR__).'/app/Support.php');
check(str_contains($source,'WHERE id=? AND customer_id=? LIMIT 1'),'Owner-scoped customer ticket lookup');
check(!str_contains(substr($source,strpos($source,'public static function ownDetail'),strpos($source,'public function list')-strpos($source,'public static function ownDetail')),'aelvo_support_events'),'Customer view does not fetch internal notes');
$source=file_get_contents(dirname(__DIR__).'/app/Team.php');
check(str_contains($source,'aelvo_staff_profiles')&&str_contains($source,'aelvo_installation'),'Staff-only mutation with protected Owner');
check(str_contains($source,'revokeStaffSessions($user)'),'Revocation on staff updates');
echo "Staff/support input and migration checks passed (offline; no PDO MySQL acceptance).\n";
