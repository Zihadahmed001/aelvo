<?php
declare(strict_types=1);

namespace Aelvo;

use Closure;
use DomainException;
use PDO;
use Throwable;

/** Requires real login, email and transactional business adapters; no demo authentication. */
final class OwnerAiService
{
    public function __construct(
        private PDO $db,
        private int $ownerId,
        private string $otpKey,
        private Closure $sendEmail,
        private array $handlers = [],
        private ?string $accessPasswordHash = null
    ) {
        if (strlen($otpKey) < 32) { throw new DomainException('Owner AI secret is not configured.'); }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    private function query(string $sql, array $args = []): \PDOStatement
    {
        $q=$this->db->prepare($sql);$q->execute($args);return $q;
    }

    private function audit(string $event, ?string $proposalId = null): void
    {
        $this->query('INSERT INTO aelvo_owner_ai_audit (owner_id,event,proposal_id,created_at) VALUES (?,?,?,?)',
            [$this->ownerId,$event,$proposalId,time()]);
    }

    public function requestCode(array $actor): void
    {
        OwnerAiPolicy::owner($actor,$this->ownerId);
        $now=time();$code=(string)random_int(100000,999999);$salt=bin2hex(random_bytes(16));
        $this->db->beginTransaction();
        try {
            $this->query('INSERT IGNORE INTO aelvo_owner_ai_challenges (owner_id,window_start,sends,sent_at,attempts,expires_at,salt,code_hash) VALUES (?,0,0,0,0,0,?,?)',[$this->ownerId,'','']);
            $row=$this->query('SELECT * FROM aelvo_owner_ai_challenges WHERE owner_id=? FOR UPDATE',[$this->ownerId])->fetch(PDO::FETCH_ASSOC);
            $sends=(int)$row['window_start']+3600>$now?(int)$row['sends']:0;
            if ((int)$row['sent_at']+60>$now || $sends>=5) { throw new DomainException('Email request limit reached. Try later.'); }
            $window=$sends?(int)$row['window_start']:$now;
            $this->query('UPDATE aelvo_owner_ai_challenges SET window_start=?,sends=?,sent_at=?,attempts=0,expires_at=?,salt=?,code_hash=? WHERE owner_id=?',
                [$window,$sends+1,$now,$now+300,$salt,hash_hmac('sha256',$salt.$code,$this->otpKey),$this->ownerId]);
            $this->audit('email_code_requested');$this->db->commit();
        } catch(Throwable $e) { if($this->db->inTransaction())$this->db->rollBack();throw $e; }
        try { $sent=($this->sendEmail)($actor['email'],$code); } catch(Throwable $e) { $sent=false; }
        if ($sent!==true) {
            $this->query('UPDATE aelvo_owner_ai_challenges SET expires_at=0,code_hash=? WHERE owner_id=? AND salt=?',['',$this->ownerId,$salt]);
            $this->audit('email_delivery_failed');
            throw new DomainException('The email service could not accept the code. Verification remains locked.');
        }
    }

    public function verify(array $actor, string $code, string $password = ''): array
    {
        OwnerAiPolicy::owner($actor,$this->ownerId);$now=time();$ok=false;
        $this->db->beginTransaction();
        try {
            $r=$this->query('SELECT * FROM aelvo_owner_ai_challenges WHERE owner_id=? FOR UPDATE',[$this->ownerId])->fetch(PDO::FETCH_ASSOC);
            if ($r && (int)$r['expires_at']>$now && (int)$r['attempts']<5) {
                $this->query('UPDATE aelvo_owner_ai_challenges SET attempts=attempts+1 WHERE owner_id=?',[$this->ownerId]);
                $ok=preg_match('/\A[0-9]{6}\z/',$code)===1 &&
                    hash_equals($r['code_hash'],hash_hmac('sha256',$r['salt'].$code,$this->otpKey)) &&
                    ($this->accessPasswordHash===null || password_verify($password,$this->accessPasswordHash));
                if($ok)$this->query('UPDATE aelvo_owner_ai_challenges SET expires_at=0,code_hash=? WHERE owner_id=?',['',$this->ownerId]);
            }
            $this->audit($ok?'owner_verified':'verification_failed');$this->db->commit();
        } catch(Throwable $e) { if($this->db->inTransaction())$this->db->rollBack();throw $e; }
        if(!$ok)throw new DomainException('Invalid or expired verification. Request a new code when permitted.');
        return ['owner_id'=>$this->ownerId,'nonce'=>bin2hex(random_bytes(32)),'verified_at'=>$now,'last_seen_at'=>$now,'expires_at'=>$now+900];
    }

    private function guard(array $actor, array $grant): void
    {
        OwnerAiPolicy::owner($actor,$this->ownerId);OwnerAiPolicy::grant($grant,$this->ownerId,time());
    }

    public function propose(array $actor, array $grant, string $operation, array $payload, string $baseVersion): array
    {
        $this->guard($actor,$grant);OwnerAiPolicy::payload($operation,$payload);
        if($baseVersion===''||strlen($baseVersion)>128)throw new DomainException('A current record/source version is required.');
        $id=bin2hex(random_bytes(16));$digest=OwnerAiPolicy::digest($operation,$payload,$baseVersion);$expires=min(time()+300,$grant['expires_at']);
        $this->db->beginTransaction();
        try {
            $this->query('INSERT INTO aelvo_owner_ai_proposals (id,owner_id,grant_hash,operation,payload,base_version,digest,status,expires_at) VALUES (?,?,?,?,?,?,?,?,?)',
                [$id,$this->ownerId,hash('sha256',$grant['nonce']),$operation,OwnerAiPolicy::canonical($payload),$baseVersion,$digest,'proposed',$expires]);
            $this->audit('proposal_created',$id);$this->db->commit();
        } catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        return ['id'=>$id,'operation'=>$operation,'payload'=>$payload,'base_version'=>$baseVersion,'digest'=>$digest,'expires_at'=>$expires,'status'=>'proposed'];
    }

    private function lockedProposal(array $grant, string $id, string $digest, string $status): array
    {
        $p=$this->query('SELECT * FROM aelvo_owner_ai_proposals WHERE id=? AND owner_id=? FOR UPDATE',[$id,$this->ownerId])->fetch(PDO::FETCH_ASSOC);
        if(!$p || $p['status']!==$status || (int)$p['expires_at']<=time() ||
            !hash_equals($p['grant_hash'],hash('sha256',$grant['nonce'])) || !hash_equals($p['digest'],$digest)) {
            throw new DomainException('Proposal expired, changed, already used or belongs to another verification session.');
        }
        $payload=json_decode($p['payload'],true,512,JSON_THROW_ON_ERROR);
        OwnerAiPolicy::payload($p['operation'],$payload);
        if(!hash_equals($digest,OwnerAiPolicy::digest($p['operation'],$payload,$p['base_version'])))throw new DomainException('Proposal integrity check failed.');
        $p['decoded_payload']=$payload;return $p;
    }

    /** Only called from the Owner's explicit confirmation UI, never from model tool calls. */
    public function approve(array $actor, array $grant, string $id, string $digest): void
    {
        $this->guard($actor,$grant);$this->db->beginTransaction();
        try {
            $this->lockedProposal($grant,$id,$digest,'proposed');
            $this->query('UPDATE aelvo_owner_ai_proposals SET status=? WHERE id=?',['approved',$id]);
            $this->audit('owner_approved_exact_change',$id);$this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function execute(array $actor, array $grant, string $id, string $digest): array
    {
        $this->guard($actor,$grant);$this->db->beginTransaction();
        try {
            $p=$this->lockedProposal($grant,$id,$digest,'approved');
            // File releases require a separately provisioned backup/test/rollback runner.
            // No arbitrary PHP, SQL or shell evaluator exists in this request path.
            if($p['operation']==='code.release')throw new DomainException('Live code execution is locked until the verified release runner is connected.');
            $handler=$this->handlers[$p['operation']]??null;
            if(!($handler instanceof TransactionalOwnerChange))throw new DomainException('This business operation is not connected yet.');
            // Adapter must lock/check base_version and validate every field; all writes use this PDO transaction.
            $result=$handler->apply($this->db,$p['decoded_payload'],$p['base_version']);
            $this->query('UPDATE aelvo_owner_ai_proposals SET status=?,result=? WHERE id=?',['executed',OwnerAiPolicy::canonical($result),$id]);
            $this->audit('change_executed',$id);$this->db->commit();return $result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}

interface TransactionalOwnerChange
{
    /** No external I/O, implicit commits or unapproved side effects. Throw on stale baseVersion. */
    public function apply(PDO $db, array $payload, string $baseVersion): array;
}
