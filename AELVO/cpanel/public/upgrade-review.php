<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
Aelvo\Security::start((bool)($config['secure_cookies']??true));
header('X-Robots-Tag: noindex, nofollow, noarchive');
$actor=Aelvo\Security::actor($pdo);
if(!$actor || ($actor['is_owner']??false)!==true){http_response_code(403);exit('Access denied.');}
$report=null;$error='';
try{$report=Aelvo\UpgradeAudit::report($pdo,dirname(__DIR__),$config,$_SERVER);}catch(Throwable $e){$error=$e->getMessage();error_log('AELVO read-only upgrade audit: '.get_class($e));}
// Optional read-only JSON export. No customer, credential, server path or backup data.
if(($_GET['format']??'')==='json'){
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="aelvo-upgrade-preflight.json"');
    echo json_encode(['status'=>$error===''?'review':'blocked','error'=>$error?:null,'report'=>$report,'note'=>'Read-only file and migration preflight; NOT a backup, staged restore, passed integration test or upgrade permission.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function ue(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AELVO | Upgrade preflight</title><link rel="stylesheet" href="backend.css"></head>
<body><main class="admin-access-main upgrade-review-main"><a class="brand" href="index.php?page=settings">AELVO FASHION</a>
<h1>Upgrade preflight &amp; recovery readiness</h1>
<p><strong>Read-only Owner diagnostic.</strong> This screen does not create, verify, restore or download a database backup. It does not run migrations, patch code, approve production deployment or unlock Owner AI. Do not use it as proof of release acceptance.</p>
<?php if($error!==''):?>
<p class="error" role="alert">Upgrade review blocked: <?=ue($error)?></p>
<p>Do not delete migration rows, remove config or restart the installer. Preserve the database and private files; investigate against an independently tested backup. Interrupted DDL requires manual inspection or restore, never automatic replay.</p>
<?php elseif($report!==null):
$environment=$report['environment'];$plan=$report['plan'];$ready=$environment['ready'] && $plan['mode']==='tracked'; ?>
<h2>Release source integrity</h2>
<p>All <?=count($report['schemas'])?> bundled schema files were parsed. <?= (int)$report['package']['files'] ?> release files match the accompanying SHA-256 manifest. This only detects local mismatches: the manifest is <em>not</em> a publisher signature.</p>
<p>Package digest: <code><?=ue($report['package']['sha256'])?></code></p>
<h2>Database migration review</h2>
<p>History: <strong><?=ue($plan['mode'])?></strong> · Applied: <?=count($plan['applied'])?> · Pending: <?=count($plan['pending'])?></p>
<p>Exact proposal fingerprint: <code><?=ue($report['fingerprint'])?></code></p>
<table><thead><tr><th>Version</th><th>SQL file</th><th>State</th><th>SHA-256 (prefix)</th></tr></thead><tbody>
<?php foreach($report['schemas'] as $entry):?><tr><td><?= (int)$entry['version'] ?></td><td><?=ue($entry['name'])?>.sql</td><td><?=ue($entry['status'])?></td><td><code><?=ue(substr($entry['sha256'],0,16))?></code></td></tr><?php endforeach;?></tbody></table>
<h2>Runtime &amp; web-root checks</h2>
<ul><?php foreach($environment['checks'] as $label=>$ok):?><li><?= $ok?'Pass':'BLOCK' ?> — <?=ue($label)?></li><?php endforeach;?></ul>
<?php foreach($environment['warnings'] as $warning):?><p role="status"><?=ue($warning)?></p><?php endforeach; ?>
<?php if(!$environment['ready']):?><p class="error" role="alert">One or more required environment checks failed. Correct hosting configuration before attempting staging or upgrades.</p><?php endif; ?>
<?php if(!$plan['pending']):?><p>No pending bundled migrations. This does not confirm that runtime modules or live integrations are working.</p>
<?php elseif(!$ready):?><p class="error">Upgrade blocked: the source/database/environment has not met the basic preflight requirements.</p>
<?php else:?><p>Pending migrations are identifiable but deliberately <strong>not executable here</strong>. A separate tested backup and isolated staging upgrade must precede any live upgrade.</p><?php endif; ?>
<a href="upgrade-review.php?format=json" download>Download read-only preflight report (JSON)</a>
<?php endif; ?>
<h2>Required recovery &amp; release gates</h2>
<ol><li>Export the exact database and private uploads/configuration to protected storage, without exposing secrets in the web root.</li><li>Actually restore the backup into an isolated <code>_test</code> database; verify records, permissions and file recovery before marking it restorable.</li><li>Run the same, checksum-matched migrations on staging and complete acceptance tests, including concurrency and payment/courier failure paths.</li><li>Schedule a maintenance window and use a separate, audited release/rollback runner; abort if the package digest or database plan changed.</li><li>If a migration fails, stop all retries, preserve logs/state and restore verified backup through a controlled recovery process.</li></ol>
<p><strong>Current limitation:</strong> the no-SSH one-click upgrade, backup/restore test runner and safe rollback controls are not implemented or approved for production. This page intentionally cannot bypass those gates.</p>
<p><a href="schema-status.php">Schema status</a> · <a href="index.php?page=settings">Back to Admin</a></p>
</main></body></html>
