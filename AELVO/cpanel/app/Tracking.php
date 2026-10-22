<?php
declare(strict_types=1);
namespace Aelvo;
use PDO;
use InvalidArgumentException;
final class Tracking {
    public function __construct(private PDO $db,private string $session) {}
    public function request(array $input,string $ip,callable $send): string {
        $reference=strtoupper(Input::text($input,'reference',40));$phone=Input::phone(Input::text($input,'phone',60));
        if(!preg_match('/^AEL-[0-9]{6}-[A-F0-9]{10}$/D',$reference))throw new InvalidArgumentException('Enter the complete order reference from your confirmation.');
        $q=$this->db->prepare('SELECT created_at FROM aelvo_tracking_challenges WHERE session_hash=? ORDER BY created_at DESC LIMIT 1');$q->execute([hash('sha256',$this->session)]);$last=$q->fetchColumn();
        if($last&&strtotime($last.' UTC')>time()-60)throw new InvalidArgumentException('Wait 60 seconds before requesting another code.');
        $a=Security::throttle($this->db,'tracking-sms-ip:'.$ip,15);$b=Security::throttle($this->db,'tracking-sms-phone:'.$phone,5);
        if(!$a||!$b)throw new InvalidArgumentException('Too many requests. Try again in 15 minutes.');
        $id=bin2hex(random_bytes(32));$code=(string)random_int(100000,999999);
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('UPDATE aelvo_tracking_challenges SET otp_hash=NULL,expires_at=0,verified_at=NULL WHERE session_hash=?');$q->execute([hash('sha256',$this->session)]);
            $q=$this->db->prepare('INSERT INTO aelvo_tracking_challenges(id,session_hash,reference,phone,expires_at) VALUES (?,?,?,?,?)');$q->execute([$id,hash('sha256',$this->session),$reference,$phone,time()+300]);$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        // Do not reveal whether a reference/number combination exists before verifying possession.
        $send($phone,$code);
        $q=$this->db->prepare('UPDATE aelvo_tracking_challenges SET otp_hash=? WHERE id=? AND expires_at>?');$q->execute([password_hash($code,PASSWORD_DEFAULT),$id,time()]);return $id;
    }
    public function verify(string $id,string $code): void {
        $this->db->beginTransaction();$valid=false;
        try{
            $q=$this->db->prepare('SELECT * FROM aelvo_tracking_challenges WHERE id=? AND session_hash=? FOR UPDATE');$q->execute([$id,hash('sha256',$this->session)]);$r=$q->fetch();
            if($r&&$r['otp_hash']&&(int)$r['expires_at']>time()&&(int)$r['attempts']<5){
                $correct=preg_match('/^[0-9]{6}$/D',$code)===1&&password_verify($code,$r['otp_hash']);
                $q=$this->db->prepare('UPDATE aelvo_tracking_challenges SET attempts=attempts+1 WHERE id=?');$q->execute([$id]);
                if($correct){
                    $q=$this->db->prepare('SELECT id FROM aelvo_orders WHERE reference=? AND phone=?');$q->execute([$r['reference'],$r['phone']]);$order=$q->fetchColumn();$valid=$order!==false;
                    $q=$this->db->prepare('UPDATE aelvo_tracking_challenges SET otp_hash=NULL,verified_at=?,order_id=?,expires_at=? WHERE id=?');$q->execute([$valid?time():null,$valid?$order:null,$valid?time()+900:0,$id]);
                }
            }$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        if(!$valid)throw new InvalidArgumentException('Could not verify this order. Check the reference, phone and code.');
    }
    public function status(string $id): ?array {
        $q=$this->db->prepare('SELECT o.id,o.reference,o.status,o.payment_status,o.total_minor,o.created_at FROM aelvo_tracking_challenges t JOIN aelvo_orders o ON o.id=t.order_id WHERE t.id=? AND t.session_hash=? AND t.verified_at IS NOT NULL AND t.expires_at>?');$q->execute([$id,hash('sha256',$this->session),time()]);$order=$q->fetch();
        if(!$order)return null;
        $q=$this->db->prepare('SELECT status,created_at FROM aelvo_order_events WHERE order_id=? ORDER BY id');$q->execute([$order['id']]);$order['events']=$q->fetchAll();
        $q=$this->db->prepare("SELECT tracking_code,remote_status,cod_minor FROM aelvo_courier_shipments WHERE order_id=? AND booking_state='Booked'");
        $q->execute([$order['id']]);$order['courier']=$q->fetch()?:null;
        unset($order['id']);return $order;
    }
}
