<?php
declare(strict_types=1);
namespace Aelvo;
use PDO;
use InvalidArgumentException;
use RuntimeException;
final class ReturnRequests extends Service {
    public static function submit(PDO $db,array $v,array $file): int {
        $customer=Customers::current($db);if(!$customer)throw new InvalidArgumentException('Sign in to request a return.');
        $item=Input::integer($v['order_item_id']??null);$qty=Input::integer($v['qty']??null,1,100);$reason=Input::text($v,'reason',2000);
        $nonce=Input::text($v,'request_key',64);if(!preg_match('/^[a-f0-9]{64}$/D',$nonce))throw new InvalidArgumentException('Reload the return form.');
        $key=hash('sha256',$customer['id'].':'.$nonce);$hash=hash('sha256',json_encode([$item,$qty,$reason],JSON_THROW_ON_ERROR));$target=null;
        $db->beginTransaction();try{
            $q=$db->prepare('SELECT o.id,o.status,i.qty FROM aelvo_order_items i JOIN aelvo_orders o ON o.id=i.order_id JOIN aelvo_customer_orders c ON c.order_id=o.id WHERE i.id=? AND c.customer_id=? FOR UPDATE');$q->execute([$item,$customer['id']]);$order=$q->fetch();
            if(!$order)throw new InvalidArgumentException('Order item unavailable.');
            $q=$db->prepare('SELECT id,request_hash FROM aelvo_customer_return_requests WHERE request_key=?');$q->execute([$key]);$old=$q->fetch();
            if($old){if(!hash_equals($old['request_hash'],$hash))throw new RuntimeException('This request was already submitted with different details.',409);$db->commit();return (int)$old['id'];}
            if($order['status']!=='Delivered')throw new InvalidArgumentException('Only delivered items can be requested for return.');
            // Physical receipts plus unreceived commitments cannot exceed delivered units.
            $q=$db->prepare('SELECT COALESCE(SUM(qty),0) FROM aelvo_returns WHERE order_item_id=?');$q->execute([$item]);$received=(int)$q->fetchColumn();
            $q=$db->prepare("SELECT COALESCE(SUM(r.qty-(SELECT COALESCE(SUM(g.qty),0) FROM aelvo_return_request_receipts l JOIN aelvo_returns g ON g.id=l.return_id WHERE l.request_id=r.id)),0) FROM aelvo_customer_return_requests r WHERE r.order_item_id=? AND r.status IN ('Pending','Accepted','Partial')");$q->execute([$item]);$held=(int)$q->fetchColumn();
            if($received+$held+$qty>(int)$order['qty'])throw new InvalidArgumentException('Quantity exceeds delivered units without an existing return request.');
            $q=$db->prepare("INSERT INTO aelvo_customer_return_requests(customer_id,order_item_id,qty,reason,request_key,request_hash,review_note) VALUES (?,?,?,?,?,?,'')");$q->execute([$customer['id'],$item,$qty,$reason,$key,$hash]);$id=(int)$db->lastInsertId();
            if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                if(($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('Choose a valid evidence photo.');
                $bytes=filesize($file['tmp_name']);$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];$size=@getimagesize($file['tmp_name']);
                if(!$bytes||$bytes>5*1024*1024||!isset($types[$mime])||!$size||$size[0]*$size[1]>25000000)throw new InvalidArgumentException('Use a JPEG, PNG or WebP photo under 5 MB and 25 megapixels.');
                $photo=bin2hex(random_bytes(16));$disk=$photo.'.'.$types[$mime];$dir=dirname(__DIR__).'/storage/return-evidence';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Evidence storage unavailable.');
                $target=$dir.'/'.$disk;if(!move_uploaded_file($file['tmp_name'],$target))throw new RuntimeException('Evidence upload failed.');chmod($target,0600);
                $q=$db->prepare('INSERT INTO aelvo_return_request_photos(id,request_id,disk_name,mime,bytes) VALUES (?,?,?,?,?)');$q->execute([$photo,$id,$disk,$mime,$bytes]);
            }
            self::event($db,$id,'Pending','Request submitted for staff review.');$db->commit();return $id;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();if($target!==null&&is_file($target))unlink($target);throw $e;}
    }
    public static function event(PDO $db,int $id,string $status,string $note,?int $actor=null): void {
        $q=$db->prepare('INSERT INTO aelvo_return_request_events(request_id,status,note,actor_id) VALUES (?,?,?,?)');$q->execute([$id,$status,$note,$actor]);
    }
    public function review(array $v): void {
        $this->allow('returns','edit');$id=Input::integer($v['return_request_id']??null);$version=Input::integer($v['version']??null);$decision=Input::text($v,'decision',20);$note=Input::text($v,'review_note',2000);
        if(!in_array($decision,['Accepted','Rejected'],true))throw new InvalidArgumentException('Invalid decision.');
        $this->transaction(function()use($id,$version,$decision,$note){
            $q=$this->db->prepare('SELECT i.order_id FROM aelvo_customer_return_requests r JOIN aelvo_order_items i ON i.id=r.order_item_id WHERE r.id=?');$q->execute([$id]);$order=$q->fetchColumn();if(!$order)throw new InvalidArgumentException('Request unavailable.');
            $q=$this->db->prepare('SELECT id FROM aelvo_orders WHERE id=? FOR UPDATE');$q->execute([$order]);
            $q=$this->db->prepare("UPDATE aelvo_customer_return_requests SET status=?,review_note=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(),version=version+1 WHERE id=? AND version=? AND status='Pending'");$q->execute([$decision,$note,$this->actor['id'],$id,$version]);
            if($q->rowCount()!==1)throw new RuntimeException('Request changed. Reload before reviewing.',409);
            self::event($this->db,$id,$decision,$note,(int)$this->actor['id']);$this->audit('return-request.reviewed:'.$id.':'.$decision);
        });
    }
    public static function forCustomer(PDO $db,int $customer): array {
        $q=$db->prepare('SELECT r.*,o.reference,i.product_name,i.variant_label,(SELECT id FROM aelvo_return_request_photos p WHERE p.request_id=r.id LIMIT 1) AS photo_id,(SELECT COALESCE(SUM(g.qty),0) FROM aelvo_return_request_receipts l JOIN aelvo_returns g ON g.id=l.return_id WHERE l.request_id=r.id) AS received_qty FROM aelvo_customer_return_requests r JOIN aelvo_order_items i ON i.id=r.order_item_id JOIN aelvo_orders o ON o.id=i.order_id WHERE r.customer_id=? ORDER BY r.id DESC LIMIT 100');$q->execute([$customer]);return $q->fetchAll();
    }
}
