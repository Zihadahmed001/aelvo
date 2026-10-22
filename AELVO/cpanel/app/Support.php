<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/** Staff-only inbox; public requests are not verified orders or proof of identity. */
final class Support extends Service {
    public const TOPICS=['contact'=>'General contact','order'=>'Order question','product'=>'Product question','bug'=>'Report a website problem'];
    public const STATUSES=['Open','In Progress','Waiting','Resolved','Closed'];

    public static function validate(array $v,?array $customer): array {
        $topic=Input::text($v,'topic',24);
        if(!isset(self::TOPICS[$topic]))throw new InvalidArgumentException('Choose a valid help topic.');
        $name=Input::text($v,'customer_name',120);
        $email=strtolower(Input::text($v,'email',254,false));
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email.');
        $rawPhone=Input::text($v,'phone',50,false);
        $phone=$rawPhone!==''?Input::phone($rawPhone):'';
        // Signed-in users can read their own submitted tickets in Account.
        if(!$customer && $email==='' && $phone==='')throw new InvalidArgumentException('Please provide an email or Bangladesh mobile number for contact.');
        $subject=Input::text($v,'subject',180);
        $message=Input::text($v,'message',6000);
        if(strlen($message)<15)throw new InvalidArgumentException('Please describe the issue in at least 15 characters.');
        $order=Input::text($v,'order_reference',40,false);
        if($order!==''&&!preg_match('/^[a-zA-Z0-9\-_]{1,40}$/D',$order))throw new InvalidArgumentException('Use a valid order reference, without spaces.');
        $key=Input::text($v,'request_key',64);
        if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new InvalidArgumentException('Invalid request token. Reload the form.');
        return ['topic'=>$topic,'customer_name'=>$name,'email'=>$email,'phone'=>$phone,'subject'=>$subject,'message'=>$message,'order_reference'=>$order,'request_key'=>$key,'customer_id'=>$customer?(int)$customer['id']:null];
    }

    /** Does not send email, assert order ownership or create an account from a matching phone. */
    public static function submit(PDO $db,array $input,array $file,?array $customer): int {
        $v=self::validate($input,$customer);
        $hasFile=($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
        $mime='';$bytes=0;$digest='';$id='';$disk='';$target='';
        if($hasFile){
            if(($file['error']??-1)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('Screenshot upload failed.');
            $bytes=filesize($file['tmp_name']);
            if(!$bytes||$bytes>4*1024*1024)throw new InvalidArgumentException('Screenshot must be under 4 MB.');
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if(!isset($types[$mime])||!getimagesize($file['tmp_name']))throw new InvalidArgumentException('Upload a valid JPG, PNG or WebP screenshot.');
            $digest=hash_file('sha256',$file['tmp_name']);
            $id=bin2hex(random_bytes(16));$disk=$id.'.'.$types[$mime];
        }
        $hash=hash('sha256',json_encode([$v['topic'],$v['customer_name'],$v['email'],$v['phone'],$v['subject'],$v['message'],$v['order_reference'],$v['customer_id'],$digest],JSON_THROW_ON_ERROR));
        $find=$db->prepare('SELECT id,request_hash,customer_id FROM aelvo_support_tickets WHERE request_key=?');
        $find->execute([$v['request_key']]);$existing=$find->fetch(PDO::FETCH_ASSOC);
        if($existing)return self::sameRequest($existing,$hash,$v['customer_id']);
        if($hasFile){
            $dir=dirname(__DIR__).'/storage/support-evidence';
            if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Private support storage is unavailable.');
            $target=$dir.'/'.$disk;
            if(!move_uploaded_file($file['tmp_name'],$target))throw new RuntimeException('Screenshot could not be stored.');
            chmod($target,0600);
        }
        try {
            $db->beginTransaction();
            try {
                $q=$db->prepare('INSERT INTO aelvo_support_tickets(request_key,request_hash,customer_id,topic,customer_name,email,phone,subject,message,order_reference) VALUES (?,?,?,?,?,?,?,?,?,?)');
                $q->execute([$v['request_key'],$hash,$v['customer_id'],$v['topic'],$v['customer_name'],$v['email'],$v['phone'],$v['subject'],$v['message'],$v['order_reference']]);
            }catch(PDOException $e){
                // Concurrency-safe replay: a unique request key can insert only once.
                if(($e->errorInfo[0]??'')!=='23000')throw $e;
                $db->rollBack();$find->execute([$v['request_key']]);$existing=$find->fetch(PDO::FETCH_ASSOC);
                if(!$existing)throw $e;
                return self::sameRequest($existing,$hash,$v['customer_id']);
            }
            $ticket=(int)$db->lastInsertId();
            $q=$db->prepare("INSERT INTO aelvo_support_events(ticket_id,actor_id,status,note) VALUES (?,NULL,'Open','Customer request received; identity and order reference unverified')");$q->execute([$ticket]);
            if($hasFile){
                $q=$db->prepare('INSERT INTO aelvo_support_attachments(id,ticket_id,disk_name,mime,bytes,original_name) VALUES (?,?,?,?,?,?)');
                $q->execute([$id,$ticket,$disk,$mime,$bytes,substr(basename((string)($file['name']??'screenshot')),0,180)]);
            }
            $db->commit();return $ticket;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        finally{if($target!=='' && is_file($target)){// Retain only successfully committed uploads.
            $q=$db->prepare('SELECT id FROM aelvo_support_attachments WHERE id=?');$q->execute([$id]);if(!$q->fetchColumn())unlink($target);
        }}
    }
    private static function sameRequest(array $old,string $hash,?int $customer): int {
        if(!hash_equals((string)$old['request_hash'],$hash)||($old['customer_id']===null?null:(int)$old['customer_id'])!==$customer)throw new InvalidArgumentException('This request token was already used for different details. Reload the form.');
        return (int)$old['id'];
    }
    public static function ownTickets(PDO $db,int $customer,int $limit=20): array {
        $q=$db->prepare('SELECT id,topic,subject,status,created_at FROM aelvo_support_tickets WHERE customer_id=? ORDER BY id DESC LIMIT '.max(1,min(50,$limit)));
        $q->execute([$customer]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Customer visibility is tied only to the original logged-in account ID. */
    public static function ownDetail(PDO $db,int $customerId,int $ticketId): ?array {
        if($customerId<1||$ticketId<1)return null;
        $q=$db->prepare('SELECT id,topic,subject,message,status,created_at FROM aelvo_support_tickets WHERE id=? AND customer_id=? LIMIT 1');
        $q->execute([$ticketId,$customerId]);$ticket=$q->fetch(PDO::FETCH_ASSOC);
        if(!$ticket)return null;
        $q=$db->prepare('SELECT status,body,created_at FROM aelvo_support_public_updates WHERE ticket_id=? ORDER BY id DESC LIMIT 100');
        $q->execute([$ticketId]);$ticket['updates']=array_reverse($q->fetchAll(PDO::FETCH_ASSOC));
        return $ticket;
    }
    /** Never infer account ownership from phone/email or order references. */
    public static function publicMessage(array $v): string {
        return Input::text($v,'public_message',2000,false);
    }
    public function list(string $status='',int $page=1): array {
        $this->allow('support','view');
        if($status!==''&&!in_array($status,self::STATUSES,true))throw new InvalidArgumentException('Invalid status.');
        $page=max(1,min(10000,$page));$offset=($page-1)*25;
        $where=$status!==''?' WHERE t.status=?':'';
        $q=$this->db->prepare('SELECT COUNT(*) FROM aelvo_support_tickets t'.$where);$q->execute($status!==''?[$status]:[]);$total=(int)$q->fetchColumn();
        $q=$this->db->prepare('SELECT t.id,t.customer_name,t.subject,t.topic,t.status,t.version,t.created_at FROM aelvo_support_tickets t'.$where.' ORDER BY t.id DESC LIMIT 25 OFFSET '.$offset);
        $q->execute($status!==''?[$status]:[]);
        return ['rows'=>$q->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'page'=>$page];
    }
    public function detail(int $id): array {
        $this->allow('support','view');
        $q=$this->db->prepare('SELECT * FROM aelvo_support_tickets WHERE id=?');$q->execute([$id]);$ticket=$q->fetch(PDO::FETCH_ASSOC);
        if(!$ticket)throw new InvalidArgumentException('Support ticket not found.');
        $q=$this->db->prepare('SELECT a.id,a.original_name,a.mime,a.bytes FROM aelvo_support_attachments a WHERE ticket_id=? ORDER BY a.created_at');$q->execute([$id]);$ticket['attachments']=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$this->db->prepare('SELECT e.*,u.name AS actor_name FROM aelvo_support_events e LEFT JOIN aelvo_users u ON u.id=e.actor_id WHERE e.ticket_id=? ORDER BY e.id');$q->execute([$id]);$ticket['events']=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$this->db->prepare('SELECT status,body,created_at FROM aelvo_support_public_updates WHERE ticket_id=? ORDER BY id DESC LIMIT 100');$q->execute([$id]);$ticket['public_updates']=array_reverse($q->fetchAll(PDO::FETCH_ASSOC));
        return $ticket;
    }
    public function update(array $v): void {
        $this->allow('support','edit');
        $id=Input::integer($v['id']??null);$version=Input::integer($v['version']??null);
        $status=Input::text($v,'status',20);if(!in_array($status,self::STATUSES,true))throw new InvalidArgumentException('Invalid ticket status.');
        $note=Input::text($v,'note',2000);
        $assign=Input::text($v,'assigned_to',20,false);
        $staff=$assign!==''?Input::integer($assign):null;
        $publicMessage=self::publicMessage($v);
        $this->transaction(function()use($id,$version,$status,$note,$staff,$publicMessage){
            $q=$this->db->prepare('SELECT status,version,assigned_to,customer_id FROM aelvo_support_tickets WHERE id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);
            if(!$old)throw new InvalidArgumentException('Ticket not found.');
            if((int)$old['version']!==$version)throw new RuntimeException('Ticket changed. Refresh before saving.',409);
            if($publicMessage!==''&&$old['customer_id']===null)throw new InvalidArgumentException('Guest tickets have no verified customer inbox. Use an internal note, not a public update.');
            if($staff!==null){
                $q=$this->db->prepare("SELECT 1 FROM aelvo_users WHERE id=? AND status='Active'");$q->execute([$staff]);
                if(!$q->fetchColumn())throw new InvalidArgumentException('Assigned staff must be active.');
                $q=$this->db->prepare('SELECT 1 FROM aelvo_installation WHERE owner_id=? AND id=1');$q->execute([$staff]);
                if(!$q->fetchColumn()&&!Security::can($this->db,$this->loadActor($staff),'support','view'))throw new InvalidArgumentException('Assigned staff needs support view permission.');
            }
            $q=$this->db->prepare('UPDATE aelvo_support_tickets SET status=?,assigned_to=?,version=version+1 WHERE id=? AND version=?');$q->execute([$status,$staff,$id,$version]);
            $q=$this->db->prepare('INSERT INTO aelvo_support_events(ticket_id,actor_id,status,note) VALUES (?,?,?,?)');$q->execute([$id,$this->actor['id'],$status,$note]);
            if($publicMessage!==''){
                $q=$this->db->prepare('INSERT INTO aelvo_support_public_updates(ticket_id,actor_id,status,body) VALUES (?,?,?,?)');
                $q->execute([$id,$this->actor['id'],$status,$publicMessage]);
            }
            $this->audit('support.ticket-updated:'.$id);
        });
    }
    private function loadActor(int $id): array {
        $q=$this->db->prepare('SELECT u.*,i.owner_id FROM aelvo_users u CROSS JOIN aelvo_installation i WHERE u.id=? AND i.id=1');$q->execute([$id]);$actor=$q->fetch(PDO::FETCH_ASSOC);
        if(!$actor)throw new InvalidArgumentException('Assignee not found.');
        $actor['is_owner']=((int)$actor['id']===(int)$actor['owner_id']);return $actor;
    }
}
