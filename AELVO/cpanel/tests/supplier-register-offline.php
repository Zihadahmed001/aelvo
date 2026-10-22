<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/Security.php';
require dirname(__DIR__).'/app/SupplierRegister.php';
use Aelvo\SupplierRegister;
function must(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
function reject(callable $fn):void {try {$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Invalid filter accepted.');}
must(SupplierRegister::filters([])===['q'=>'','status'=>'','due'=>'','page'=>1],'Default filters');
$f=SupplierRegister::filters(['supplier_q'=>'  Fashion  ','supplier_status'=>'active','supplier_due'=>'owing','supplier_page'=>'3']);
must($f['q']==='Fashion'&&$f['page']===3,'Search normalization');
must(SupplierRegister::like('%_!')==='!%!_!!','LIKE escaping');
foreach([['supplier_q'=>[]],['supplier_q'=>str_repeat('a',101)],['supplier_q'=>"evil\n"],['supplier_status'=>'pending'],['supplier_due'=>'paid'],['supplier_page'=>'0'],['supplier_page'=>'01'],['supplier_page'=>[]]] as $bad) reject(fn()=>SupplierRegister::filters($bad));
class MockSupplierPDO extends PDO {
    public array $statements=[];
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        $stmt=new MockSupplierStmt($query);$this->statements[]=$stmt;return $stmt;
    }
}
class MockSupplierStmt extends PDOStatement {
    public array $bindings=[];
    public function __construct(public string $sql){}
    public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR):bool{$this->bindings[$param]=[$value,$type];return true;}
    public function execute(?array $params=null):bool{return true;}
    public function fetchColumn(int $column=0):mixed {return str_contains($this->sql,'aelvo_role_permissions')?false:53;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {return [['id'=>12,'name'=>'X','active'=>1,'purchase_count'=>3,'booked_minor'=>9990,'paid_minor'=>4000]];}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return ['id'=>12,'name'=>'X','company'=>''];}
}
$db=new MockSupplierPDO();$owner=['id'=>1,'status'=>'Active','is_owner'=>true];
$result=(new SupplierRegister($db,$owner))->list(['supplier_q'=>'%_!','supplier_status'=>'active','supplier_due'=>'owing','supplier_page'=>'3']);
must($result['total']===53 && $result['page']===3 && $result['pages']===3,'Page metadata');
$sql=end($db->statements);
must(str_contains($sql->sql,'GROUP BY ps.supplier_id') && str_contains($sql->sql,'GROUP BY purchase_id'),'Joined payments must aggregate before linked purchases');
must(str_contains($sql->sql,'ORDER BY s.id DESC LIMIT ? OFFSET ?'),'Stable paginated directory');
must(str_contains($sql->sql,'COALESCE(t.booked_minor,0)>COALESCE(t.paid_minor,0)'),'Owing filter');
must($sql->bindings[1][0]==='%!%!_!!%' && $sql->bindings[4][0]==='%!%!_!!%','Escaped search bindings');
must($sql->bindings[5]===[25,PDO::PARAM_INT]&&$sql->bindings[6]===[50,PDO::PARAM_INT],'Integer limit and offset');
must((new SupplierRegister($db,$owner))->activeSupplier(12)['name']==='X','Active supplier selection');
$staff=['id'=>5,'status'=>'Active','is_owner'=>false];
try {(new SupplierRegister(new MockSupplierPDO(),$staff))->list([]);throw new RuntimeException('Unauthorized directory access.');}
catch(RuntimeException $e){must($e->getCode()===403,'Permission must be enforced');}
$root=dirname(__DIR__);
$view=file_get_contents($root.'/app/AdminView.php');
$purchase=file_get_contents($root.'/app/Purchases.php');
$index=file_get_contents($root.'/public/index.php');
$boot=file_get_contents($root.'/app/bootstrap.php');
foreach (['SupplierRegister($db,$actor)','supplier_page','purchase_supplier_id','purchase_supplier','Use for purchase','purchase_reference'] as $needle)
    must(str_contains($view.$index,$needle),'Supplier UI/route missing: '.$needle);
must(str_contains($purchase,'hash_equals((string)$purchase[\'reference\'],$purchaseReference)'),'Supplier payment invoice confirmation missing');
must(str_contains($purchase,'SELECT id,reference,total_minor FROM aelvo_purchases WHERE id=? FOR UPDATE'),'Payment must lock invoice');
must(str_contains($boot,"'SupplierRegister'"),'Service autoload missing');
echo "Supplier register filters, aggregated linked dues, paginated SQL, RBAC, purchase and payment wiring passed (offline; no MySQL/browser).\n";
