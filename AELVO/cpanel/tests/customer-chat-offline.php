<?php
declare(strict_types=1);
// Pure constraints only. No MySQL or real AI calls, no user messages sent externally.
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/CustomerAi.php';
require dirname(__DIR__).'/app/CustomerChat.php';
require dirname(__DIR__).'/app/Schema.php';
use Aelvo\CustomerAi;
use Aelvo\CustomerChat;
use Aelvo\Schema;
function chatCheck(bool $ok,string $description): void {if(!$ok)throw new RuntimeException('Customer-chat offline test failed: '.$description);}
function chatReject(callable $task,string $description): void {try{$task();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Accepted invalid chat input: '.$description);}
chatCheck(in_array('customer-chat',Schema::FILES,true),'Additive schema registered');
chatCheck(count(Schema::statements((string)file_get_contents(dirname(__DIR__).'/database/customer-chat.sql')))===3,'All three chat tables parse');
chatCheck(CustomerChat::key(str_repeat('a',64))===CustomerChat::key(str_repeat('a',64)),'Identity hash is deterministic');
chatCheck(CustomerChat::key(str_repeat('a',64))!==CustomerChat::key(str_repeat('b',64)),'Distinct guest sessions isolated');
chatReject(fn()=>CustomerChat::key('short'),'Reject invalid guest identity');
chatCheck(CustomerChat::text(['message'=>'  hello  '])==='hello','Trim outgoing message');
chatReject(fn()=>CustomerChat::text(['message'=>str_repeat('X',2001)]),'Bound outgoing message');
chatReject(fn()=>CustomerChat::requestKey(['request_key'=>'unsafe']),'Reject invalid idempotency key');
chatCheck(CustomerAi::validModel('gpt-4.1-mini'),'Allow a supported model');
chatCheck(!CustomerAi::validModel('https://evil.example/any'),'Never configure arbitrary model endpoint');
chatCheck(CustomerAi::redact('Send to sale@example.com at +8801712345678')==='Send to [email withheld] at [phone withheld]','Redact common personal identifiers');
$completed=['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>'আপনি সাহায্য পাবেন।']]]]];
chatCheck(CustomerAi::extract($completed)==='আপনি সাহায্য পাবেন।','Extract completed model text');
chatCheck(CustomerAi::extract([...$completed,'status'=>'incomplete'])===null,'Reject incomplete response');
chatCheck(CustomerAi::extract(['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'refusal','text'=>'No']]]]])===null,'Reject missing text');
$index=file_get_contents(dirname(__DIR__).'/public/index.php');$store=file_get_contents(dirname(__DIR__).'/public/store.php');$admin=file_get_contents(dirname(__DIR__).'/app/AdminView.php');$staff=file_get_contents(dirname(__DIR__).'/app/Team.php');
chatCheck(str_contains($store,"$".'action===\'chat-send\'')&&str_contains($store,'Security::throttle')&&str_contains($store,'completeAi'),'Customer POST endpoint limits and checks generation');
chatCheck(str_contains($index,"case 'chat-staff'")&&str_contains($admin,"'channel.website','view'")&&str_contains($staff,'channel.website'),'Admin permission wiring');
chatCheck(str_contains(file_get_contents(dirname(__DIR__).'/app/CustomerChat.php'),"generation_token=NULL")&&str_contains(file_get_contents(dirname(__DIR__).'/app/CustomerChat.php'),"mode='Human'"),'Human takeover invalidates generation');
echo "Customer chat offline validation passed. No PDO/MySQL, real provider, browser or concurrency test was performed.\n";
