<?php
declare(strict_types=1);
namespace Aelvo;

use DomainException;
use PDO;
use Throwable;

/** Email ownership proof; distinct from Owner AI short-lived reauthorization. */
final class OwnerEmailVerification {
    public static function identity(array $actor): void {
        if(($actor['is_owner']??false)!==true||($actor['status']??'')!=='Active'||!filter_var($actor['email']??'',FILTER_VALIDATE_EMAIL)|| (int)($actor['id']??0)<1||(int)($actor['owner_id']??0)!==(int)$actor['id'])throw new DomainException('Authenticated original Owner required.');
    }
    public static function request(PDO $db,array $actor,string $keyHex,callable $send): void {
        self::identity($actor);
        if(!empty($actor['email_verified_at']))throw new DomainException('Owner email is already verified.');
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$keyHex))throw new DomainException('Private installation encryption key is missing.');
        $id=(int)$actor['id'];$now=time();$code=(string)random_int(100000,999999);$salt=bin2hex(random_bytes(16));
        $hash=self::codeHash($keyHex,$id,(string)$actor['email'],$salt,$code);
        $db->beginTransaction();
        try {
            $q=$db->prepare('INSERT IGNORE INTO aelvo_owner_email_challenges (owner_id,email,window_start,sends,sent_at,attempts,expires_at,salt,code_hash) VALUES (?,?,0,0,0,0,0,?,?)');
            $q->execute([$id,$actor['email'],'','']);
            $q=$db->prepare('SELECT * FROM aelvo_owner_email_challenges WHERE owner_id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);
            $windowStart=(int)$old['window_start'];$sends=$windowStart+3600>$now?(int)$old['sends']:0;
            if((int)$old['sent_at']+60>$now||$sends>=5)throw new DomainException('Email verification rate limit reached.');
            $window=$sends?$windowStart:$now;
            $q=$db->prepare('UPDATE aelvo_owner_email_challenges SET email=?,window_start=?,sends=?,sent_at=?,attempts=0,expires_at=?,salt=?,code_hash=? WHERE owner_id=?');
            $q->execute([$actor['email'],$window,$sends+1,$now,$now+300,$salt,$hash,$id]);
            self::audit($db,$id,'owner_email_code_requested');$db->commit();
        } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        try{$ok=$send((string)$actor['email'],$code)===true;}catch(Throwable){$ok=false;}
        if(!$ok){
            $q=$db->prepare('UPDATE aelvo_owner_email_challenges SET expires_at=0,code_hash=? WHERE owner_id=? AND salt=?');$q->execute(['',$id,$salt]);
            self::audit($db,$id,'owner_email_delivery_failed');
            throw new DomainException('SMTP did not accept the verification message. Email stays unverified.');
        }
    }
    public static function verify(PDO $db,array $actor,string $keyHex,string $code): void {
        self::identity($actor);
        if(!empty($actor['email_verified_at']))throw new DomainException('Owner email is already verified.');
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$keyHex))throw new DomainException('Private installation encryption key is missing.');
        $id=(int)$actor['id'];$now=time();$db->beginTransaction();
        try{
            $q=$db->prepare('SELECT * FROM aelvo_owner_email_challenges WHERE owner_id=? FOR UPDATE');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
            $ok=$r && (int)$r['expires_at']>$now&&(int)$r['attempts']<5 &&hash_equals((string)$r['email'],(string)$actor['email']) && preg_match('/\A[0-9]{6}\z/D',$code)===1 && hash_equals((string)$r['code_hash'],self::codeHash($keyHex,$id,(string)$actor['email'],(string)$r['salt'],$code));
            if($r){$q=$db->prepare('UPDATE aelvo_owner_email_challenges SET attempts=attempts+1 WHERE owner_id=?');$q->execute([$id]);}
            if($ok){
                $q=$db->prepare('UPDATE aelvo_users SET email_verified_at=UTC_TIMESTAMP() WHERE id=? AND email=? AND status=? AND email_verified_at IS NULL');$q->execute([$id,$actor['email'],'Active']);
                $ok=$q->rowCount()===1;
                if($ok){$q=$db->prepare('UPDATE aelvo_owner_email_challenges SET expires_at=0,code_hash=? WHERE owner_id=?');$q->execute(['',$id]);}
            }
            self::audit($db,$id,$ok?'owner_email_verified':'owner_email_verify_failed');$db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        if(!$ok)throw new DomainException('Invalid or expired code. Verification was not completed.');
    }
    private static function codeHash(string $key,int $id,string $email,string $salt,string $code): string {
        return hash_hmac('sha256',$id.'|'.strtolower($email).'|'.$salt.'|'.$code,hex2bin($key));
    }
    private static function audit(PDO $db,int $id,string $event): void {
        $q=$db->prepare('INSERT INTO aelvo_audit(actor_id,event) VALUES (?,?)');$q->execute([$id,$event]);
    }
}
