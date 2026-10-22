<?php
declare(strict_types=1);
namespace Aelvo;

use PDO;
use InvalidArgumentException;
use RuntimeException;

/** Durable website-only support conversations. Neither a phone nor an order reference proves identity. */
final class CustomerChat {
    public static function key(string $key): string {
        if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new InvalidArgumentException('Invalid conversation session.');
        return hash('sha256','aelvo-website-chat:'.$key);
    }
    public static function text(array $input): string {return Input::text($input,'message',2000);}
    public static function requestKey(array $input): string {
        $key=Input::text($input,'request_key',64);
        if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new InvalidArgumentException('Reload the chat form.');
        return $key;
    }
    private static function authorized(array $thread,?array $customer,string $guestHash): bool {
        // Account-bound messages cannot be exposed by a phone/email match or original guest session.
        if($thread['customer_id']!==null)return $customer!==null&&(int)$thread['customer_id']===(int)$customer['id'];
        // Guest conversation access ends at the session boundary; no implicit account attachment.
        return $customer===null&&$thread['guest_key_hash']!==null&&hash_equals((string)$thread['guest_key_hash'],$guestHash);
    }
    /** Read-only page view does not create guest database records on GET requests. */
    public static function current(PDO $db,?array $customer,string $guestKey): ?array {
        $hash=self::key($guestKey);
        if($customer){
            $q=$db->prepare("SELECT * FROM aelvo_chat_threads WHERE customer_id=? AND channel='website' AND status='Open' ORDER BY id DESC LIMIT 1");$q->execute([(int)$customer['id']]);
        }else{
            $q=$db->prepare("SELECT * FROM aelvo_chat_threads WHERE customer_id IS NULL AND guest_key_hash=? AND channel='website' AND status='Open' ORDER BY id DESC LIMIT 1");$q->execute([$hash]);
        }
        return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    /** Creation is POST-only and caller must have already passed CSRF and rate limits. */
    public static function open(PDO $db,?array $customer,string $guestKey): array {
        $thread=self::current($db,$customer,$guestKey);
        if($thread)return $thread;
        $hash=self::key($guestKey);
        $q=$db->prepare("INSERT INTO aelvo_chat_threads(channel,customer_id,guest_key_hash,mode,status) VALUES ('website',?,?, 'Human','Open')");
        $q->execute([$customer?(int)$customer['id']:null,$customer?null:$hash]);
        return self::ownThread($db,(int)$db->lastInsertId(),$customer,$guestKey);
    }
    public static function ownThread(PDO $db,int $id,?array $customer,string $guestKey): array {
        $q=$db->prepare("SELECT * FROM aelvo_chat_threads WHERE id=? AND channel='website' LIMIT 1");$q->execute([$id]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||!self::authorized($row,$customer,self::key($guestKey)))throw new RuntimeException('Conversation is unavailable for this account/session.',403);
        return $row;
    }
    public static function timeline(PDO $db,int $id,int $limit=80): array {
        $limit=max(1,min(100,$limit));
        $q=$db->prepare('SELECT id,sender,body,created_at FROM aelvo_chat_messages WHERE thread_id=? ORDER BY id DESC LIMIT '.$limit);
        $q->execute([$id]);return array_reverse($q->fetchAll(PDO::FETCH_ASSOC));
    }
    private static function event(PDO $db,int $id,string $event,?int $actor=null): void {
        $q=$db->prepare('INSERT INTO aelvo_chat_events(thread_id,actor_id,event) VALUES (?,?,?)');$q->execute([$id,$actor,$event]);
    }
    /** Returns generation claim or null. A duplicate request never re-generates. */
    public static function send(PDO $db,int $id,?array $customer,string $guestKey,array $input,bool $aiAvailable): ?array {
        $message=self::text($input);$requestKey=self::requestKey($input);
        $consent=($input['ai_consent']??'0')==='1';
        $db->beginTransaction();
        try {
            $q=$db->prepare("SELECT * FROM aelvo_chat_threads WHERE id=? AND channel='website' FOR UPDATE");$q->execute([$id]);$thread=$q->fetch(PDO::FETCH_ASSOC);
            if(!$thread||!self::authorized($thread,$customer,self::key($guestKey)))throw new RuntimeException('Conversation is unavailable.',403);
            if($thread['status']!=='Open')throw new InvalidArgumentException('This conversation is closed. Start a new one.');
            $q=$db->prepare('SELECT id FROM aelvo_chat_messages WHERE thread_id=? AND client_key=?');$q->execute([$id,$requestKey]);
            if($q->fetchColumn()){$db->commit();return null;}
            $q=$db->prepare("SELECT COUNT(*) FROM aelvo_chat_messages WHERE thread_id=? AND sender='customer'");$q->execute([$id]);$first=(int)$q->fetchColumn()===0;
            $q=$db->prepare("INSERT INTO aelvo_chat_messages(thread_id,sender,client_key,body) VALUES (?,'customer',?,?)");
            $q->execute([$id,$requestKey,$message]);$messageId=(int)$db->lastInsertId();
            // Only a first message with explicit consent can begin automated mode.
            // Any human handoff remains locked against re-enabling AI for this thread.
            $mode=$thread['mode'];
            if($first && $consent && $aiAvailable){$mode='AI';self::event($db,$id,'ai-consent');}
            if(!$consent && $mode==='AI'){$mode='Human';self::event($db,$id,'ai-consent-withdrawn');}
            if($mode==='AI' && !$aiAvailable){$mode='Human';self::event($db,$id,'ai-unavailable');}
            $token=null;
            if($mode==='AI' && ($thread['generation_token']??null)===null){$token=bin2hex(random_bytes(16));}
            $q=$db->prepare('UPDATE aelvo_chat_threads SET mode=?,ai_opt_in=?,generation_token=?,generation_started_at=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?');
            $q->execute([$mode,$consent?1:0,$token,$token?gmdate('Y-m-d H:i:s'):null,$id]);
            $db->commit();
            return $token!==null?['token'=>$token,'message_id'=>$messageId]:null;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Retrieve bounded, redacted conversation context; never query private customer or order data. */
    public static function historyForClaim(PDO $db,int $id,string $token): array {
        $q=$db->prepare("SELECT id FROM aelvo_chat_threads WHERE id=? AND mode='AI' AND status='Open' AND ai_opt_in=1 AND generation_token=?");$q->execute([$id,$token]);
        if(!$q->fetchColumn())return [];
        return self::timeline($db,$id,12);
    }
    /** Prevent AI replies after staff takeover, consent withdrawal, closure or a newer message. */
    public static function completeAi(PDO $db,int $id,string $token,int $originalMessageId,?string $reply): bool {
        $db->beginTransaction();
        try{
            $q=$db->prepare('SELECT mode,status,ai_opt_in,generation_token FROM aelvo_chat_threads WHERE id=? FOR UPDATE');$q->execute([$id]);$t=$q->fetch(PDO::FETCH_ASSOC);
            if(!$t||$t['mode']!=='AI'||$t['status']!=='Open'||(int)$t['ai_opt_in']!==1||!hash_equals((string)($t['generation_token']??''),$token)){$db->commit();return false;}
            $q=$db->prepare('SELECT MAX(id) FROM aelvo_chat_messages WHERE thread_id=?');$q->execute([$id]);$last=(int)$q->fetchColumn();
            if($reply===null||$last!==$originalMessageId){
                $q=$db->prepare("UPDATE aelvo_chat_threads SET mode='Human',generation_token=NULL,generation_started_at=NULL,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?");$q->execute([$id]);
                self::event($db,$id,$reply===null?'ai-error-needs-human':'ai-outdated-needs-human');
                $db->commit();return false;
            }
            $q=$db->prepare("INSERT INTO aelvo_chat_messages(thread_id,sender,body) VALUES (?,'assistant',?)");$q->execute([$id,$reply]);
            $q=$db->prepare('UPDATE aelvo_chat_threads SET generation_token=NULL,generation_started_at=NULL,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?');$q->execute([$id]);
            $db->commit();return true;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function askHuman(PDO $db,int $id,?array $customer,string $guestKey): void {
        $db->beginTransaction();try{
            $q=$db->prepare("SELECT * FROM aelvo_chat_threads WHERE id=? AND channel='website' FOR UPDATE");$q->execute([$id]);$thread=$q->fetch(PDO::FETCH_ASSOC);
            if(!$thread||!self::authorized($thread,$customer,self::key($guestKey)))throw new RuntimeException('Conversation unavailable.',403);
            if($thread['status']==='Open'&&$thread['mode']!=='Human'){
                $q=$db->prepare("UPDATE aelvo_chat_threads SET mode='Human',generation_token=NULL,generation_started_at=NULL,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?");$q->execute([$id]);self::event($db,$id,'customer-handoff');
            }
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function authorizedStaff(PDO $db,array $actor,string $action): void {
        if(!Security::can($db,$actor,'support',$action)||!Security::can($db,$actor,'channel.website',$action))throw new RuntimeException('Support and website channel permissions required.',403);
    }
    public static function staffList(PDO $db,array $actor): array {
        self::authorizedStaff($db,$actor,'view');
        return $db->query("SELECT id,mode,status,assigned_to,customer_id,created_at,updated_at FROM aelvo_chat_threads WHERE channel='website' ORDER BY (status='Open') DESC,updated_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function staffThread(PDO $db,array $actor,int $id): array {
        self::authorizedStaff($db,$actor,'view');$q=$db->prepare("SELECT * FROM aelvo_chat_threads WHERE channel='website' AND id=?");$q->execute([$id]);
        $thread=$q->fetch(PDO::FETCH_ASSOC);if(!$thread)throw new InvalidArgumentException('Chat not found.');return $thread;
    }
    public static function staffAction(PDO $db,array $actor,array $input): void {
        self::authorizedStaff($db,$actor,'edit');
        $id=Input::integer($input['thread_id']??null);$version=Input::integer($input['version']??null,1);
        $action=Input::text($input,'chat_action',20);
        if(!in_array($action,['takeover','reply','close'],true))throw new InvalidArgumentException('Invalid chat action.');
        $body=$action==='reply'?self::text($input):'';
        $db->beginTransaction();try{
            $q=$db->prepare("SELECT * FROM aelvo_chat_threads WHERE id=? AND channel='website' FOR UPDATE");$q->execute([$id]);$thread=$q->fetch(PDO::FETCH_ASSOC);
            if(!$thread)throw new InvalidArgumentException('Chat not found.');
            if((int)$thread['version']!==$version)throw new RuntimeException('Chat changed. Reload before saving.',409);
            if($thread['status']!=='Open')throw new InvalidArgumentException('Chat already closed.');
            if($action==='takeover'){
                if($thread['assigned_to']!==null&&(int)$thread['assigned_to']!==(int)$actor['id']&&!($actor['is_owner']??false))throw new RuntimeException('Chat is already assigned to another agent.',403);
                $q=$db->prepare("UPDATE aelvo_chat_threads SET mode='Human',assigned_to=?,generation_token=NULL,generation_started_at=NULL,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?");$q->execute([$actor['id'],$id]);
                self::event($db,$id,'staff-takeover',(int)$actor['id']);
            }else{
                if($thread['mode']!=='Human'||(int)($thread['assigned_to']??0)!==(int)$actor['id'])throw new RuntimeException('Take over this chat before replying or closing.',403);
                if($action==='reply'){
                    $q=$db->prepare("INSERT INTO aelvo_chat_messages(thread_id,sender,actor_id,body) VALUES (?,'staff',?,?)");$q->execute([$id,$actor['id'],$body]);
                }else{
                    $q=$db->prepare("UPDATE aelvo_chat_threads SET status='Closed',generation_token=NULL,generation_started_at=NULL,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?");$q->execute([$id]);
                    self::event($db,$id,'staff-closed',(int)$actor['id']);
                }
            }
            if($action==='reply'){$q=$db->prepare('UPDATE aelvo_chat_threads SET version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?');$q->execute([$id]);}
            $q=$db->prepare('INSERT INTO aelvo_audit(actor_id,event) VALUES (?,?)');$q->execute([$actor['id'],'chat.'.$action.':'.$id]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
