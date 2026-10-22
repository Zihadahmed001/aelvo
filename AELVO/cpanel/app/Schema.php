<?php
declare(strict_types=1);
namespace Aelvo;

/** Ordered bundled SQL release. Never rewrite an already-applied SQL file. */
final class Schema {
    public const FILES=['core','commerce','finance','owner-ai','checkout','customers','content','coupons','tracking','payments','returns','refund-review','customer-tools','customer-engagement','seo-errors','seo-routes','seo-entities','supplier-operations','inventory-quarantine','taxonomy-seo','cms-pages','cms-rich-content','support-inbox','courier','gateway','inventory-costing','support-public-updates','staff-sessions','customer-chat','owner-email-verification','courier-resolution','courier-exceptions','gateway-exceptions','gateway-payout-batches','order-cancellations','order-tax','refund-tax-allocation','variant-pricing','product-details','product-merchandising','coupon-eligibility'];

    /**
     * Split known bundled SQL files without treating a semicolon in a comment
     * or quoted SQL literal as a command terminator. No SQL from users is read.
     */
    public static function statements(string $sql): array {
        $statements=[];$buffer='';$length=strlen($sql);$quote=null;
        for($i=0;$i<$length;$i++){
            $ch=$sql[$i];
            if($quote!==null){
                $buffer.=$ch;
                if($ch==='\\' && $i+1<$length){$buffer.=$sql[++$i];continue;}
                if($ch===$quote){
                    if($i+1<$length && $sql[$i+1]===$quote){$buffer.=$sql[++$i];continue;}
                    $quote=null;
                }
                continue;
            }
            if($ch==='-' && $i+2<$length && $sql[$i+1]==='-' && ctype_space($sql[$i+2])){
                while($i<$length && $sql[$i]!=="\n")$i++;
                $buffer.="\n";continue;
            }
            if($ch==='#'){
                while($i<$length && $sql[$i]!=="\n")$i++;
                $buffer.="\n";continue;
            }
            if($ch==='/' && $i+1<$length && $sql[$i+1]==='*'){
                $end=strpos($sql,'*/',$i+2);
                if($end===false)throw new \RuntimeException('Unterminated SQL comment.');
                $i=$end+1;$buffer.="\n";continue;
            }
            if($ch==="'"||$ch==='"'||$ch==='`'){$quote=$ch;$buffer.=$ch;continue;}
            if($ch===';'){
                $command=trim($buffer);
                if($command!=='')$statements[]=$command;
                $buffer='';continue;
            }
            $buffer.=$ch;
        }
        if($quote!==null)throw new \RuntimeException('Unterminated SQL quoted value.');
        $tail=trim($buffer);if($tail!=='')$statements[]=$tail;
        return $statements;
    }

    /** The ordered files and their exact content digests are release artifacts. */
    public static function manifest(): array {
        $manifest=[];
        foreach(self::FILES as $index=>$name){
            if(!preg_match('/^[a-z0-9-]+$/D',$name))throw new \RuntimeException('Invalid bundled schema name.');
            $path=dirname(__DIR__).'/database/'.$name.'.sql';
            $sql=@file_get_contents($path);
            if($sql===false || trim($sql)==='')throw new \RuntimeException('Missing/empty bundled schema: '.$name);
            $statements=self::statements($sql);
            if(!$statements)throw new \RuntimeException('Empty SQL statement list: '.$name);
            $manifest[$index+1]=['name'=>$name,'sha256'=>hash('sha256',$sql),'statements'=>$statements];
        }
        return $manifest;
    }

    private static function tables(\PDO $db): array {
        $tables=[];
        foreach($db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_NUM) as $row)$tables[]=(string)$row[0];
        return $tables;
    }

    /**
     * Read-only operator preflight. An old populated database without a ledger
     * cannot be assumed migrated, even when CREATE TABLE IF NOT EXISTS exists.
     */
    public static function plan(\PDO $db): array {
        $files=self::manifest();
        $tables=self::tables($db);
        $ledger=in_array('aelvo_schema_migrations',$tables,true);
        if(!$ledger){
            if($tables)throw new \RuntimeException('Untracked existing database. Stop: back up and audit the legacy schema before an upgrade; no data was changed.');
            return ['mode'=>'fresh','applied'=>[],'pending'=>array_keys($files)];
        }
        $rows=$db->query('SELECT version,filename,sha256,state FROM aelvo_schema_migrations ORDER BY version')->fetchAll(\PDO::FETCH_ASSOC);
        return self::planFromHistory($files,$tables,$rows);
    }

    /** Pure preflight used for offline regression tests as well as PDO execution. */
    public static function planFromHistory(array $files,array $tables,array $rows): array {
        if(!in_array('aelvo_schema_migrations',$tables,true)){
            if($tables)throw new \RuntimeException('Untracked existing database. Stop: back up and audit the legacy schema before an upgrade; no data was changed.');
            return ['mode'=>'fresh','applied'=>[],'pending'=>array_keys($files)];
        }
        if(!$rows && count($tables)>1)throw new \RuntimeException('Migration ledger is empty but application tables exist. An interrupted bootstrap needs manual recovery.');
        $applied=[];$expectedVersion=1;
        foreach($rows as $row){
            $version=(int)$row['version'];
            if($version!==$expectedVersion || !isset($files[$version]) || $row['filename']!==$files[$version]['name'] || !hash_equals($files[$version]['sha256'],(string)$row['sha256'])){
                throw new \RuntimeException('Recorded migration order or checksum differs from bundled source. Review backup/release before proceeding.');
            }
            if($row['state']!=='applied')throw new \RuntimeException('Migration '.$version.' is interrupted or failed. Never replay DDL automatically; review the database before recovery.');
            $applied[]=$version;$expectedVersion++;
        }
        return ['mode'=>'tracked','applied'=>$applied,'pending'=>array_slice(array_keys($files),count($applied))];
    }

    /**
     * Applies only never-started, immutable bundled files. MySQL DDL commits
     * implicitly: a crashed or failed migration is intentionally BLOCKED, not
     * retried or reported as rolled back. Upgrades require a real restore test.
     * This method does not create backups or approve an upgrade by itself.
     */
    public static function migrate(\PDO $db): void {
        // Never execute installer/upgrade DDL from an unverified or modified
        // release tree. This is a consistency gate, not a publisher signature.
        require_once __DIR__.'/UpgradeAudit.php';
        UpgradeAudit::verifyPackage(dirname(__DIR__));
        $files=self::manifest(); // fail on missing/malformed release files before DB writes
        $database=(string)$db->query('SELECT DATABASE()')->fetchColumn();
        if($database==='')throw new \RuntimeException('Select a dedicated database before applying schemas.');
        $lockName='aelvo:schema:'.substr(hash('sha256',$database),0,32);
        $getLock=$db->prepare('SELECT GET_LOCK(?,0)');$getLock->execute([$lockName]);
        if((int)$getLock->fetchColumn()!==1)throw new \RuntimeException('A schema migration is already running or DB locking is unavailable.');
        try {
            $plan=self::plan($db);
            if($plan['mode']==='fresh'){
                $db->exec("CREATE TABLE aelvo_schema_migrations (
                    version SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
                    filename VARCHAR(96) NOT NULL UNIQUE,
                    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                    state ENUM('applying','applied','failed') NOT NULL,
                    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    completed_at DATETIME NULL,
                    INDEX(state)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
            $start=$db->prepare("INSERT INTO aelvo_schema_migrations(version,filename,sha256,state) VALUES (?,?,?,'applying')");
            $applied=$db->prepare("UPDATE aelvo_schema_migrations SET state='applied',completed_at=UTC_TIMESTAMP() WHERE version=? AND state='applying'");
            $failed=$db->prepare("UPDATE aelvo_schema_migrations SET state='failed' WHERE version=? AND state='applying'");
            foreach($plan['pending'] as $version){
                $file=$files[$version];
                $start->execute([$version,$file['name'],$file['sha256']]);
                try {
                    foreach($file['statements'] as $statement)$db->exec($statement);
                    $applied->execute([$version]);
                    if($applied->rowCount()!==1)throw new \RuntimeException('Migration completion journal not written.');
                }catch(\Throwable $e){
                    try {$failed->execute([$version]);}catch(\Throwable $journalError){error_log('AELVO migration failure marker could not be saved.');}
                    throw new \RuntimeException('Migration '.$version.' failed or was interrupted. Do not retry automatically; inspect schema and restore from a verified backup.',0,$e);
                }
            }
            self::verifyPublicRouteOwnership($db);
        }finally{
            try{$release=$db->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}catch(\Throwable $e){error_log('AELVO migration advisory lock release failed.');}
        }
    }

    private static function verifyPublicRouteOwnership(\PDO $db): void {
        foreach(['category'=>'aelvo_categories','brand'=>'aelvo_brands'] as $kind=>$table){
            $q=$db->query("SELECT e.id FROM $table e LEFT JOIN aelvo_public_routes r ON r.path=CONCAT('$kind/',e.slug) AND r.route_kind='current' AND r.entity_type='$kind' AND r.entity_id=e.id AND r.current_owner=CONCAT('$kind:',e.id) WHERE r.path IS NULL LIMIT 1");
            if($q->fetchColumn()!==false)throw new \RuntimeException('Taxonomy URL ownership requires manual conflict review; data was preserved.');
        }
        $q=$db->query("SELECT p.id FROM aelvo_products p LEFT JOIN aelvo_public_routes r ON r.path=CONCAT('product/',p.slug) AND r.route_kind='current' AND r.entity_type='product' AND r.entity_id=p.id AND r.current_owner=CONCAT('product:',p.id) WHERE r.path IS NULL LIMIT 1");
        if($q->fetchColumn()!==false)throw new \RuntimeException('Public URL ownership migration needs manual conflict resolution. Existing data was retained.');
    }
}
