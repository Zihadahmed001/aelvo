<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/Security.php';
require dirname(__DIR__).'/app/AuditLog.php';
require dirname(__DIR__).'/app/PurchaseRegister.php';
use Aelvo\AuditLog;
use Aelvo\PurchaseRegister;
function ok(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function rejects(callable $fn,string $message):void{try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Expected rejection: '.$message);}
$owner=['id'=>1,'status'=>'Active','is_owner'=>true];
$staff=['id'=>9,'status'=>'Active','is_owner'=>false];
$af=AuditLog::filters(['from'=>'2026-10-01','to'=>'2026-10-21','event'=>'product.price','actor'=>'system','p'=>'2']);
ok($af['event']==='product.price'&&$af['p']===2&&$af['actor']==='system','Audit filter validation');
foreach ([['from'=>'2026-02-30'],['from'=>'2024-01-01','to'=>'2026-01-01'],['event'=>'hi'."\n".'there'],['actor'=>'1 OR 1=1'],['actor'=>[]],['p'=>'0002'],['p'=>'10001']] as $bad)rejects(fn()=>AuditLog::filters($bad),'audit:'.json_encode($bad));
ok(AuditLog::csvCell('=SUM(A1:A3)')==="'=SUM(A1:A3)",'CSV formula injection');
ok(AuditLog::csvCell(" \n+2+3")==="' \n+2+3",'CSV whitespace formula injection');
ok(AuditLog::csvCell('audit.record')==='audit.record','CSV ordinary value');
ok(PurchaseRegister::filters([])===['q'=>'','receipt'=>'','payment'=>'','page'=>1,'supplier_id'=>null],'Empty purchase filters');
ok(PurchaseRegister::filters(['purchase_q'=>'  PO-1  ','receipt_status'=>'Received','payment_status'=>'partial','purchase_page'=>'3'])['q']==='PO-1','Purchase filter trim');
ok(PurchaseRegister::like('%_!')==='!%!_!!','LIKE wildcard escape');
ok(PurchaseRegister::filters(['purchase_supplier'=>'19'])['supplier_id']===19,'Purchase supplier filter');
foreach ([['receipt_status'=>'Posted'],['payment_status'=>'overdue'],['purchase_q'=>[]],['purchase_q'=>str_repeat('a',101)],['purchase_q'=>"\0"],['purchase_page'=>'0'],['purchase_page'=>'0002'],['purchase_supplier'=>'0'],['purchase_supplier'=>[]],['purchase_supplier'=>'1 OR 1=1']] as $bad)rejects(fn()=>PurchaseRegister::filters($bad),'purchase:'.json_encode($bad));
class OfflinePDO extends PDO {
    public array $queries=[];
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false{
        $stmt=new OfflineStmt($query);$this->queries[]=$stmt;return $stmt;
    }
}
class OfflineStmt extends PDOStatement {
    public array $args=[];
    public function __construct(public string $sql){}
    public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR):bool{$this->args[$param]=[$value,$type];return true;}
    public function execute(?array $params=null):bool{return true;}
    public function fetchColumn(int $column=0):mixed{if(str_contains($this->sql,'aelvo_role_permissions'))return false;return 67;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{
        if(str_contains($this->sql,'aelvo_audit a'))return [['id'=>10,'actor_id'=>null,'event'=>'gateway.validation:Review:2','created_at'=>'2026-10-21 10:00:00','actor_name'=>null,'actor_email'=>null]];
        return [['id'=>15,'reference'=>'PO-15','supplier'=>'Supplier','receipt_id'=>'1','total_minor'=>50000,'paid_minor'=>10000,'status'=>'Draft','created_at'=>'2026-10-21']];
    }
}
$db=new OfflinePDO();
try{new AuditLog($db,$staff);throw new RuntimeException('Non-owner allowed to audit');}catch(RuntimeException $ex){ok($ex->getCode()===403,'Incorrect Owner gate');}
$a=new AuditLog($db,$owner);
$rows=$a->search($af);
ok($rows['total']===67 && $rows['page']===2 && $rows['pages']===3,'Audit pagination');
$last=end($db->queries);
ok(str_contains($last->sql,'ORDER BY a.created_at DESC,a.id DESC'),'Audit stable ordering');
ok(str_contains($last->sql,'a.actor_id IS NULL'),'System filter');
ok($last->args[4]===[25,PDO::PARAM_INT] && $last->args[5]===[25,PDO::PARAM_INT],'Audit limit/offset must be integers');
ok($last->args[3][0]==='%product.price%','Audit LIKE bind');
$db->queries=[];$rows=$a->search($af,true);
$last=end($db->queries);
ok($last->args[4]===[1000,PDO::PARAM_INT] && $last->args[5]===[0,PDO::PARAM_INT],'CSV bound to 1000');
$db->queries=[];
$p=new PurchaseRegister($db,$owner);
$result=$p->list(['purchase_q'=>'PO%!','receipt_status'=>'Draft','payment_status'=>'partial','purchase_page'=>'3']);
ok($result['total']===67 && $result['page']===3 && count($result['rows'])===1,'Purchase count and data');
$sql=end($db->queries);
ok(str_contains($sql->sql,'aelvo_supplier_payments'),'Purchase due based on actual paid records');
ok(str_contains($sql->sql,'r.paid_minor>0 AND r.paid_minor<r.total_minor'),'Partial payment rule');
ok($sql->args[1][0]==='%PO!%!!%','Purchase SQL wildcard safe');
ok($sql->args[4]===[25,PDO::PARAM_INT] && $sql->args[5]===[50,PDO::PARAM_INT],'Purchase pagination binding');
try{(new PurchaseRegister(new OfflinePDO(),$staff))->list([]);throw new RuntimeException('Non-authorized staff accessed purchase register');}catch(RuntimeException $ex){ok($ex->getCode()===403,'Purchase permission');}
$root=dirname(__DIR__);
$index=file_get_contents($root.'/public/index.php');
$view=file_get_contents($root.'/app/AdminView.php');
$export=file_get_contents($root.'/public/audit-export.php');
$boot=file_get_contents($root.'/app/bootstrap.php');
foreach(['AuditLog::filters($_GET)','PurchaseRegister::filters($_GET)','audit-export.php','PurchaseRegister($db,$actor)','Activity &amp; audit'] as $part)
    ok(str_contains($index.$view.$export,$part),'Missing real route/view wiring: '.$part);
ok(str_contains($boot,"'AuditLog'")&&str_contains($boot,"'PurchaseRegister'"),'Missing bootstrap classes');
ok(str_contains($export,'Security::actor($pdo)')&&str_contains($export,"['is_owner']"),'CSV must authorize owner');
echo "Owner audit, bounded CSV, purchase statuses, PDO prepared statement parameters, authorization and routes passed (offline; no MySQL/browser).\n";
