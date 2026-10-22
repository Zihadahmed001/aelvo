<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
use Aelvo\Security;
if (!str_ends_with($config['db']['name'],'_test')) throw new RuntimeException("Use a dedicated database ending in _test.\n");
function check(bool $value,string $message): void {
    if (!$value) throw new RuntimeException($message);
    echo "PASS $message\n";
}
if ((int)$pdo->query('SELECT COUNT(*) FROM aelvo_users')->fetchColumn()!==0) throw new RuntimeException("Use an empty test database.\n");
$pdo->beginTransaction();
try {
    $q=$pdo->prepare('INSERT INTO aelvo_users(name,email,password_hash) VALUES (?,?,?)');
    $hash=password_hash('Long test password 123!',PASSWORD_DEFAULT);
    $q->execute(['Owner','owner@example.invalid',$hash]); $owner=(int)$pdo->lastInsertId();
    $q->execute(['Staff','staff@example.invalid',$hash]); $staff=(int)$pdo->lastInsertId();
    $q=$pdo->prepare('UPDATE aelvo_installation SET owner_id=? WHERE id=1'); $q->execute([$owner]);
    $pdo->exec("INSERT INTO aelvo_roles(name) VALUES ('Super Admin')"); $role=(int)$pdo->lastInsertId();
    $q=$pdo->prepare('INSERT INTO aelvo_user_roles VALUES (?,?)'); $q->execute([$staff,$role]);
    $o=['id'=>$owner,'status'=>'Active','is_owner'=>true];
    $s=['id'=>$staff,'status'=>'Active','is_owner'=>false];
    check(Security::can($pdo,$o,'dashboard','view'),'Original Owner access');
    check(!Security::can($pdo,$s,'dashboard','view'),'Role name alone grants nothing');
    $q=$pdo->prepare('INSERT INTO aelvo_role_permissions VALUES (?,?,?)'); $q->execute([$role,'dashboard','view']);
    check(Security::can($pdo,$s,'dashboard','view'),'Explicit role grant works');
    check(!Security::can($pdo,$s,'dashboard','delete'),'Ungrantable action denied');
    $pdo->exec('DELETE FROM aelvo_role_permissions');
    check(!Security::can($pdo,$s,'dashboard','view'),'Revocation takes effect immediately');
    $o['status']='Disabled'; check(!Security::can($pdo,$o,'dashboard','view'),'Disabled actor denied');
    check(password_verify('Long test password 123!',$hash),'Password hash verifies');
    check(!password_verify('wrong',$hash),'Wrong password rejected');
    for ($i=0;$i<3;$i++) check(Security::throttle($pdo,'test-login',3),'Attempt within limit');
    check(!Security::throttle($pdo,'test-login',3),'Attempt beyond limit denied');
    $pdo->exec('UPDATE aelvo_login_attempts SET expires_at=0');
    check(Security::throttle($pdo,'test-login',3),'Expired throttle window resets');
} finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
echo "Database checks completed; test records rolled back.\n";
