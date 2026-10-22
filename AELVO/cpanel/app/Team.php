<?php
declare(strict_types=1);
namespace Aelvo;
use InvalidArgumentException;
final class Team extends Service {
    public const MODULES=['dashboard','products','inventory','orders','courier','settings','finance','purchases','reports','content','support','channel.website','reviews','seo','coupons','payments','returns'];
    public function create(array $v): int {
        $this->owner();$name=Input::text($v,'name',120);$email=strtolower(Input::text($v,'email',254));$phone=Input::phone(Input::text($v,'phone',60));$password=Input::text($v,'password',72);$notes=Input::text($v,'notes',4000,false);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12) throw new InvalidArgumentException('Use a valid email and a password of at least 12 characters.');
        $hash=password_hash($password,PASSWORD_DEFAULT);
        return $this->transaction(function() use($name,$email,$phone,$hash,$notes) {
            $q=$this->db->prepare('INSERT INTO aelvo_users(name,email,password_hash) VALUES (?,?,?)');$q->execute([$name,$email,$hash]);$id=(int)$this->db->lastInsertId();
            $q=$this->db->prepare('INSERT INTO aelvo_staff_profiles(user_id,phone,notes) VALUES (?,?,?)');$q->execute([$id,$phone,$notes]);
            $q=$this->db->prepare('INSERT INTO aelvo_roles(name) VALUES (?)');$q->execute(['Staff permissions #'.$id]);$role=(int)$this->db->lastInsertId();
            $q=$this->db->prepare('INSERT INTO aelvo_user_roles(user_id,role_id) VALUES (?,?)');$q->execute([$id,$role]);
            $q=$this->db->prepare('INSERT INTO aelvo_staff_session_revisions(user_id,revision) VALUES (?,1)');$q->execute([$id]);
            $this->audit('staff.created:'.$id);return $id;
        });
    }
    public function permissions(int $user,array $grants): void {
        $this->owner();
        foreach($grants as $grant) { if(!is_string($grant) || !preg_match('/^([a-z.]+):(view|create|edit|delete|cancel|approve)$/D',$grant,$m) || !in_array($m[1],self::MODULES,true) || (($m[2]==='cancel' && $m[1]!=='orders') || ($m[2]==='approve' && !in_array($m[1],['courier','payments'],true)))) throw new InvalidArgumentException('Unknown permission'); }
        $this->transaction(function() use($user,$grants) {
            $q=$this->db->prepare('SELECT id FROM aelvo_installation WHERE owner_id=? FOR UPDATE');$q->execute([$user]);if($q->fetch()) throw new InvalidArgumentException('Owner access cannot be changed.');
            $q=$this->db->prepare('SELECT ur.role_id FROM aelvo_user_roles ur JOIN aelvo_roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.name=? FOR UPDATE');$q->execute([$user,'Staff permissions #'.$user]);$role=$q->fetchColumn();if(!$role) throw new InvalidArgumentException('Staff not found');
            $q=$this->db->prepare('DELETE FROM aelvo_role_permissions WHERE role_id=?');$q->execute([$role]);
            $q=$this->db->prepare('INSERT INTO aelvo_role_permissions(role_id,module,action) VALUES (?,?,?)');foreach(array_unique($grants) as $grant) { [$module,$action]=explode(':',$grant);$q->execute([$role,$module,$action]); }
            $this->revokeStaffSessions($user);
            $this->audit('staff.permissions-updated:'.$user);
        });
    }
    /** Prevent edits to the immutable Owner and to accounts not provisioned as staff. */
    public function setActive(int $user,bool $enable): void {
        $this->owner();
        $this->transaction(function()use($user,$enable){
            $q=$this->db->prepare('SELECT u.id,u.status,i.owner_id FROM aelvo_users u JOIN aelvo_staff_profiles p ON p.user_id=u.id CROSS JOIN aelvo_installation i WHERE i.id=1 AND u.id=? FOR UPDATE');
            $q->execute([$user]);$old=$q->fetch(\PDO::FETCH_ASSOC);
            if(!$old)throw new InvalidArgumentException('Staff account not found.');
            if((int)$old['owner_id']===$user)throw new InvalidArgumentException('Owner identity is protected.');
            if(!in_array($old['status'],['Active','Disabled'],true))throw new InvalidArgumentException('Unexpected account state; review manually.');
            $new=$enable?'Active':'Disabled';
            if($old['status']===$new)throw new InvalidArgumentException('Staff account already '.$new.'.');
            $q=$this->db->prepare('UPDATE aelvo_users SET status=? WHERE id=?');$q->execute([$new,$user]);
            $this->revokeStaffSessions($user);
            $this->audit(($enable?'staff.reenabled:':'staff.disabled:').$user);
        });
    }
    private function revokeStaffSessions(int $user): void {
        $q=$this->db->prepare('INSERT INTO aelvo_staff_session_revisions(user_id,revision) VALUES (?,1) ON DUPLICATE KEY UPDATE revision=revision+1');
        $q->execute([$user]);
    }
    public function disable(int $user): void {$this->setActive($user,false);}
    public function enable(int $user): void {$this->setActive($user,true);}
}
