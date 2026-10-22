<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Aelvo\AuditLog;
use Aelvo\Security;
Security::start((bool)$config['secure_cookies']);
header('X-Robots-Tag: noindex, nofollow');
$actor=Security::actor($pdo);
if (!$actor || ($actor['is_owner']??false)!==true) { http_response_code(403);exit('Forbidden.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405);exit('Method not allowed.'); }
try {
    $filters=AuditLog::filters($_GET);
    $rows=(new AuditLog($pdo,$actor))->search($filters,true);
} catch (InvalidArgumentException $e) { http_response_code(400); exit('Invalid audit filters.'); }
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="aelvo-audit-'.gmdate('Ymd').'.csv"');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$out=fopen('php://output','wb');
fputcsv($out,['Event ID','Timestamp UTC','Actor ID','Actor name','Event']);
foreach ($rows['rows'] as $row) {
    fputcsv($out,[
        (int)$row['id'], AuditLog::csvCell($row['created_at']),
        $row['actor_id']===null?'system':(int)$row['actor_id'],
        AuditLog::csvCell($row['actor_name']??''), AuditLog::csvCell($row['event'])
    ]);
}
fclose($out);
