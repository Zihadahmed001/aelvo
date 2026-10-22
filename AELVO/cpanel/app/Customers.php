<?php
declare(strict_types=1);
namespace Aelvo;
use PDO;
use InvalidArgumentException;
final class Customers {
    public function __construct(private PDO $db,private string $session) {}
    public static function current(PDO $db): ?array {
        if(!isset($_SESSION['customer_id']))return null;
        if(time()-($_SESSION['customer_login_at']??0)>28800 || time()-($_SESSION['customer_last_seen']??0)>1800){self::logout();return null;}
        $q=$db->prepare("SELECT c.id,c.name,c.phone,c.verified_at,COALESCE(s.session_version,0) AS session_version FROM aelvo_customers c LEFT JOIN aelvo_customer_security s ON s.customer_id=c.id WHERE c.id=? AND c.status='Active'");$q->execute([$_SESSION['customer_id']]);$r=$q->fetch();
        if(!$r||(int)$r['session_version']!==(int)($_SESSION['customer_session_version']??0)){self::logout();return null;}$_SESSION['customer_last_seen']=time();return $r;
    }
    private static function establish(int $id,int $version=0): void {
        session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));
        $_SESSION['customer_id']=$id;$_SESSION['customer_session_version']=$version;$_SESSION['customer_login_at']=$_SESSION['customer_last_seen']=time();
        // Quotes from another account/guest session cannot cross an authentication boundary.
        $_SESSION['checkout_identity']=bin2hex(random_bytes(32));unset($_SESSION['customer_challenge']);
    }
    public static function logout(): void {
        if(isset($_SESSION['bag_owner'])){$_SESSION['bag']=[];unset($_SESSION['bag_owner']);}
        unset($_SESSION['buy_now'],$_SESSION['checkout_form']);
        unset($_SESSION['customer_session_version'],$_SESSION['customer_id'],$_SESSION['customer_login_at'],$_SESSION['customer_last_seen'],$_SESSION['customer_challenge']);
        session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));$_SESSION['checkout_identity']=bin2hex(random_bytes(32));
    }
    private static function password(array $v,string $key): string {
        $password=$v[$key]??null;if(!is_string($password)||$password===''||strlen($password)>72||str_contains($password,"\0"))throw new InvalidArgumentException('Invalid password.');return $password;
    }
    public function login(array $v,string $ip): void {
        $phone=Input::phone(Input::text($v,'phone',60));$password=self::password($v,'password');
        $a=Security::throttle($this->db,'customer-login-ip:'.$ip,40);$b=Security::throttle($this->db,'customer-login-phone:'.$phone,10);
        if(!$a||!$b)throw new InvalidArgumentException('Too many attempts. Try again in 15 minutes.');
        $q=$this->db->prepare('SELECT c.*,COALESCE(s.session_version,0) AS session_version FROM aelvo_customers c LEFT JOIN aelvo_customer_security s ON s.customer_id=c.id WHERE c.phone=?');$q->execute([$phone]);$r=$q->fetch();
        $valid=password_verify($password,$r['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if(!$r||!$valid||$r['status']!=='Active')throw new InvalidArgumentException('Phone or password is incorrect.');
        self::establish((int)$r['id'],(int)$r['session_version']);
    }
    public function challenge(array $v,string $purpose,string $ip,callable $send): string {
        if(!in_array($purpose,['signup','phone','reset'],true))throw new InvalidArgumentException('Invalid verification purpose.');
        $current=self::current($this->db);if($purpose==='phone'&&!$current)throw new InvalidArgumentException('Sign in first.');
        $phone=Input::phone(Input::text($v,'phone',60));
        $name=$purpose==='signup'?Input::text($v,'name',120):($current['name']??'Password recovery');
        $password=in_array($purpose,['signup','reset'],true)?self::password($v,'password'):'';
        if(in_array($purpose,['signup','reset'],true)&&(strlen($password)<12||$password!==self::password($v,'confirm')))throw new InvalidArgumentException('Use matching passwords of 12–72 bytes.');
        $q=$this->db->prepare('SELECT created_at FROM aelvo_customer_challenges WHERE session_hash=? ORDER BY created_at DESC LIMIT 1');$q->execute([hash('sha256',$this->session)]);$sent=$q->fetchColumn();
        if($sent&&strtotime($sent.' UTC')>time()-60)throw new InvalidArgumentException('Wait 60 seconds before requesting another code.');
        $a=Security::throttle($this->db,'account-sms-ip:'.$ip,15);$b=Security::throttle($this->db,'account-sms-phone:'.$phone,5);
        if(!$a||!$b)throw new InvalidArgumentException('Too many requests. Try again in 15 minutes.');
        $target=$purpose==='phone'?$current['id']:null;
        if($purpose==='reset'){$q=$this->db->prepare("SELECT id FROM aelvo_customers WHERE phone=? AND status='Active'");$q->execute([$phone]);$target=$q->fetchColumn()?:null;}
        $id=bin2hex(random_bytes(32));$code=(string)random_int(100000,999999);
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('UPDATE aelvo_customer_challenges SET consumed_at=? WHERE session_hash=? AND consumed_at IS NULL');$q->execute([time(),hash('sha256',$this->session)]);
            $q=$this->db->prepare('INSERT INTO aelvo_customer_challenges(id,session_hash,purpose,customer_id,phone,name,password_hash,expires_at) VALUES (?,?,?,?,?,?,?,?)');
            $q->execute([$id,hash('sha256',$this->session),$purpose,$target,$phone,$name,$password!==''?password_hash($password,PASSWORD_DEFAULT):'',time()+300]);$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        // Every valid phone receives the same response; account existence is not disclosed before OTP.
        $send($phone,$code);
        $q=$this->db->prepare('UPDATE aelvo_customer_challenges SET otp_hash=? WHERE id=? AND consumed_at IS NULL');$q->execute([password_hash($code,PASSWORD_DEFAULT),$id]);return $id;
    }
    public function verify(string $id,string $code): void {
        $current=self::current($this->db);$this->db->beginTransaction();$valid=false;$customer=null;$sessionVersion=0;
        try{
            $q=$this->db->prepare('SELECT * FROM aelvo_customer_challenges WHERE id=? AND session_hash=? FOR UPDATE');$q->execute([$id,hash('sha256',$this->session)]);$r=$q->fetch();
            if($r&&!$r['consumed_at']&&$r['otp_hash']&&(int)$r['expires_at']>time()&&(int)$r['attempts']<5){
                $valid=preg_match('/^[0-9]{6}$/D',$code)===1&&password_verify($code,$r['otp_hash']);
                if($r['purpose']==='phone'&&(!$current||(int)$current['id']!==(int)$r['customer_id']))$valid=false;
                $q=$this->db->prepare('UPDATE aelvo_customer_challenges SET attempts=attempts+1 WHERE id=?');$q->execute([$id]);
                if($valid){
                    $q=$this->db->prepare("SELECT id FROM aelvo_customers WHERE phone=? FOR UPDATE");$q->execute([$r['phone']]);$existing=$q->fetchColumn();
                    if($existing && !($r['purpose']==='reset') && !($r['purpose']==='phone'&&(int)$existing===(int)$r['customer_id'])){
                        $q=$this->db->prepare('UPDATE aelvo_customer_challenges SET consumed_at=?,otp_hash=NULL WHERE id=?');$q->execute([time(),$id]);$this->db->commit();
                        throw new InvalidArgumentException('This verified number already has an account. Sign in to that account.');
                    }
                    if($r['purpose']==='reset'){
                        if(!$existing||(int)$existing!==(int)$r['customer_id']){
                            $q=$this->db->prepare('UPDATE aelvo_customer_challenges SET consumed_at=?,otp_hash=NULL,password_hash=\'\' WHERE id=?');$q->execute([time(),$id]);$this->db->commit();throw new InvalidArgumentException('No eligible account for this verified number. Create an account or contact support.');
                        }
                        $customer=(int)$existing;$q=$this->db->prepare("UPDATE aelvo_customers SET password_hash=? WHERE id=? AND phone=? AND status='Active'");$q->execute([$r['password_hash'],$customer,$r['phone']]);if($q->rowCount()!==1)throw new InvalidArgumentException('Account unavailable.');
                        self::revokeSessions($this->db,$customer);
                    }elseif($r['purpose']==='signup'){
                        $q=$this->db->prepare('INSERT INTO aelvo_customers(name,phone,password_hash,verified_at) VALUES (?,?,?,?)');$q->execute([$r['name'],$r['phone'],$r['password_hash'],time()]);$customer=(int)$this->db->lastInsertId();
                    }else{
                        $q=$this->db->prepare("UPDATE aelvo_customers SET phone=?,verified_at=? WHERE id=? AND status='Active'");$q->execute([$r['phone'],time(),$r['customer_id']]);$customer=(int)$r['customer_id'];self::revokeSessions($this->db,$customer);
                    }
                    $q=$this->db->prepare('UPDATE aelvo_customer_challenges SET consumed_at=?,otp_hash=NULL,password_hash=\'\' WHERE id=?');$q->execute([time(),$id]);
                }
            }
            if($valid&&$customer!==null){$q=$this->db->prepare('SELECT session_version FROM aelvo_customer_security WHERE customer_id=?');$q->execute([$customer]);$sessionVersion=(int)$q->fetchColumn();}
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        if(!$valid)throw new InvalidArgumentException('Invalid or expired verification code.');
        self::establish($customer,$sessionVersion);
    }
    private static function revokeSessions(PDO $db,int $customer): void {
        $q=$db->prepare('INSERT INTO aelvo_customer_security(customer_id,session_version) VALUES (?,1) ON DUPLICATE KEY UPDATE session_version=session_version+1,changed_at=UTC_TIMESTAMP()');$q->execute([$customer]);
        $q=$db->prepare("UPDATE aelvo_customer_challenges SET consumed_at=?,otp_hash=NULL,password_hash='' WHERE customer_id=? AND consumed_at IS NULL");$q->execute([time(),$customer]);
    }
    public function addresses(): array {
        $current=self::current($this->db);if(!$current)throw new InvalidArgumentException('Sign in first.');
        $q=$this->db->prepare('SELECT * FROM aelvo_customer_addresses WHERE customer_id=? ORDER BY id DESC');$q->execute([$current['id']]);return $q->fetchAll();
    }
    public function address(int $id): array {
        $current=self::current($this->db);if(!$current)throw new InvalidArgumentException('Sign in first.');
        $q=$this->db->prepare('SELECT * FROM aelvo_customer_addresses WHERE id=? AND customer_id=?');$q->execute([$id,$current['id']]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Address unavailable.');return $r;
    }
    public function saveAddress(array $v): void {
        $current=self::current($this->db);if(!$current)throw new InvalidArgumentException('Sign in first.');
        $label=Input::text($v,'label',80);$name=Input::text($v,'recipient',120);$phone=Input::phone(Input::text($v,'phone',60));$address=Input::text($v,'address',2000);$zone=Input::text($v,'zone',20);
        if(!in_array($zone,['dhaka','outside'],true))throw new InvalidArgumentException('Choose a delivery area.');
        $id=($v['address_id']??'')===''?null:Input::integer($v['address_id']);
        $this->db->beginTransaction();try{
            $q=$this->db->prepare('SELECT id FROM aelvo_customers WHERE id=? FOR UPDATE');$q->execute([$current['id']]);
            if($id!==null){$version=Input::integer($v['version']??null);$q=$this->db->prepare('UPDATE aelvo_customer_addresses SET label=?,recipient=?,phone=?,address=?,zone=?,version=version+1 WHERE id=? AND customer_id=? AND version=?');$q->execute([$label,$name,$phone,$address,$zone,$id,$current['id'],$version]);if($q->rowCount()!==1)throw new InvalidArgumentException('Address changed or is unavailable. Reload before editing.');}
            else{$q=$this->db->prepare('SELECT COUNT(*) FROM aelvo_customer_addresses WHERE customer_id=?');$q->execute([$current['id']]);if((int)$q->fetchColumn()>=10)throw new InvalidArgumentException('Keep up to ten saved addresses.');$q=$this->db->prepare('INSERT INTO aelvo_customer_addresses(customer_id,label,recipient,phone,address,zone) VALUES (?,?,?,?,?,?)');$q->execute([$current['id'],$label,$name,$phone,$address,$zone]);}
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function deleteAddress(array $v): void {
        $current=self::current($this->db);if(!$current)throw new InvalidArgumentException('Sign in first.');
        $id=Input::integer($v['address_id']??null);$version=Input::integer($v['version']??null);
        $q=$this->db->prepare('DELETE FROM aelvo_customer_addresses WHERE id=? AND customer_id=? AND version=?');$q->execute([$id,$current['id'],$version]);if($q->rowCount()!==1)throw new InvalidArgumentException('Address changed or is unavailable. Reload first.');
    }
    public function updateProfile(array $v): void {
        $current=self::current($this->db);if(!$current)throw new InvalidArgumentException('Sign in first.');
        $name=Input::text($v,'name',120);$previous=Input::text($v,'previous_name',120);
        $this->db->beginTransaction();try{
            $q=$this->db->prepare("SELECT name FROM aelvo_customers WHERE id=? AND status='Active' FOR UPDATE");$q->execute([$current['id']]);$stored=$q->fetchColumn();
            if($stored!==$previous)throw new InvalidArgumentException('Your profile changed. Reload before saving.');
            $q=$this->db->prepare('UPDATE aelvo_customers SET name=? WHERE id=?');$q->execute([$name,$current['id']]);$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        $_SESSION['account_notice']='Profile updated. Past order details are unchanged.';
    }
    public function changePassword(array $v,string $ip): void {
        $current=self::current($this->db);if(!$current)throw new InvalidArgumentException('Sign in first.');
        $old=self::password($v,'current_password');$new=self::password($v,'password');
        if(strlen($new)<12||$new!==self::password($v,'confirm'))throw new InvalidArgumentException('Use matching passwords of 12–72 bytes.');
        $a=Security::throttle($this->db,'password-change-ip:'.$ip,30);$b=Security::throttle($this->db,'password-change-customer:'.$current['id'],8);
        if(!$a||!$b)throw new InvalidArgumentException('Too many attempts. Try again in 15 minutes.');
        $this->db->beginTransaction();try{
            $q=$this->db->prepare("SELECT password_hash FROM aelvo_customers WHERE id=? AND status='Active' FOR UPDATE");$q->execute([$current['id']]);$hash=$q->fetchColumn();
            if(!$hash||!password_verify($old,$hash))throw new InvalidArgumentException('Current password is incorrect.');
            if(password_verify($new,$hash))throw new InvalidArgumentException('Choose a different new password.');
            $q=$this->db->prepare('UPDATE aelvo_customers SET password_hash=? WHERE id=?');$q->execute([password_hash($new,PASSWORD_DEFAULT),$current['id']]);self::revokeSessions($this->db,(int)$current['id']);
            $q=$this->db->prepare('SELECT session_version FROM aelvo_customer_security WHERE customer_id=?');$q->execute([$current['id']]);$version=(int)$q->fetchColumn();$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        self::establish((int)$current['id'],$version);$_SESSION['account_notice']='Password changed. Other sessions have been signed out.';
    }
    public function ordersCount(int $customer): int {
        $q=$this->db->prepare('SELECT COUNT(*) FROM aelvo_customer_orders WHERE customer_id=?');
        $q->execute([$customer]);return (int)$q->fetchColumn();
    }
    public function orders(int $customer,int $page=1): array {
        // Ten at a time: the customer account may contain return and review
        // actions for every line; rendering 100 orders at once is excessive.
        $page=max(1,min(100000,$page));
        $offset=($page-1)*10;
        $q=$this->db->prepare('SELECT o.* FROM aelvo_orders o JOIN aelvo_customer_orders c ON c.order_id=o.id WHERE c.customer_id=? ORDER BY o.id DESC LIMIT 10 OFFSET '.$offset);
        $q->execute([$customer]);return $q->fetchAll();
    }
}
