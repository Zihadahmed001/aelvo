<?php
declare(strict_types=1);
namespace Aelvo;
final class Security {
    public static function start(bool $secure): void {
        if ($secure && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
            http_response_code(400); exit('HTTPS is required. Use HTTP only with local development configuration.');
        }
        ini_set('session.use_strict_mode','1');
        ini_set('session.use_only_cookies','1');
        session_name('aelvo_backend');
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
        session_start();
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }
    public static function csrf(): void {
        if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
            http_response_code(403); exit('Session expired. Reload the page and try again.');
        }
    }
    /** Compare the revision captured at login to the current server-side staff revision. */
    public static function sessionIsCurrent(array $session,array $actor): bool {
        return is_int($session['staff_revision']??null) && $session['staff_revision']===(int)($actor['session_revision']??-1);
    }
    public static function actor(\PDO $db): ?array {
        if (!isset($_SESSION['user_id'])) return null;
        if (time()-($_SESSION['last_seen']??0)>1800 || time()-($_SESSION['login_at']??0)>28800) {
            self::logout(); return null;
        }
        $q=$db->prepare('SELECT u.*, i.owner_id,COALESCE(v.revision,0) AS session_revision FROM aelvo_users u CROSS JOIN aelvo_installation i LEFT JOIN aelvo_staff_session_revisions v ON v.user_id=u.id WHERE u.id=? AND i.id=1 AND u.status=?');
        $q->execute([$_SESSION['user_id'],'Active']); $u=$q->fetch();
        if (!$u || !self::sessionIsCurrent($_SESSION,$u)) { self::logout(); return null; }
        $_SESSION['last_seen']=time();
        $u['is_owner']=(int)$u['id']===(int)$u['owner_id'];
        return $u;
    }
    public static function can(\PDO $db,array $actor,string $module,string $action): bool {
        if ($actor['status']!=='Active') return false;
        if ($actor['is_owner']===true) return true;
        $q=$db->prepare('SELECT 1 FROM aelvo_user_roles ur JOIN aelvo_role_permissions p ON p.role_id=ur.role_id WHERE ur.user_id=? AND p.module=? AND p.action=? LIMIT 1');
        $q->execute([$actor['id'],$module,$action]); return (bool)$q->fetchColumn();
    }
    public static function logout(): void {
        $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32));
    }
    public static function throttle(\PDO $db,string $key,int $limit=10): bool {
        $bucket=hash('sha256',$key); $now=time();
        $q=$db->prepare('INSERT INTO aelvo_login_attempts(bucket,attempts,expires_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE attempts=IF(expires_at<=?,1,attempts+1), expires_at=IF(expires_at<=?,VALUES(expires_at),expires_at)');
        $q->execute([$bucket,$now+900,$now,$now]);
        $q=$db->prepare('SELECT attempts FROM aelvo_login_attempts WHERE bucket=?'); $q->execute([$bucket]);
        return (int)$q->fetchColumn()<=$limit;
    }
}
