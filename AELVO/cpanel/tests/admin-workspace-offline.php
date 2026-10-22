<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/Security.php';
require dirname(__DIR__).'/app/AdminWorkspace.php';

use Aelvo\AdminWorkspace;
function assertAdmin(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function invalidAdmin(callable $fn,string $reason):void {
    try{$fn();}catch(InvalidArgumentException $e){return;}
    throw new RuntimeException('Expected validation failure: '.$reason);
}
assertAdmin(AdminWorkspace::PAGE_SIZE===25,'Page size changed');
assertAdmin(AdminWorkspace::filters([], 'products')===['q'=>'','status'=>'','p'=>1],'Empty product filters invalid');
assertAdmin(AdminWorkspace::filters(['q'=>'  Shoes  ','status'=>'Draft','p'=>'9'], 'products')===['q'=>'Shoes','status'=>'Draft','p'=>9],'Product normalization failed');
assertAdmin(AdminWorkspace::filters(['q'=>'socks','status'=>'quarantined','p'=>3], 'inventory')['status']==='quarantined','Inventory filter missing');
assertAdmin(AdminWorkspace::escapeLike('a%!_')==='a!%!!!_','LIKE wildcard escaping broken');
foreach (['Archived','Published','all'] as $s)invalidAdmin(fn()=>AdminWorkspace::filters(['status'=>$s],'products'),'product status '.$s);
foreach (['stockout','negative','-2'] as $s)invalidAdmin(fn()=>AdminWorkspace::filters(['status'=>$s],'inventory'),'inventory status '.$s);
foreach (['0','009','10001','1 UNION SELECT','-3'] as $p)invalidAdmin(fn()=>AdminWorkspace::filters(['p'=>$p],'inventory'),'page '.$p);
invalidAdmin(fn()=>AdminWorkspace::filters(['q'=>str_repeat('x',101)],'products'),'oversized search');
invalidAdmin(fn()=>AdminWorkspace::filters(['q'=>"hello\nworld"],'products'),'controls');
invalidAdmin(fn()=>AdminWorkspace::filters(['q'=>['unsafe']],'products'),'search array');
invalidAdmin(fn()=>AdminWorkspace::filters(['p'=>['1']],'products'),'page array');
invalidAdmin(fn()=>AdminWorkspace::filters(['status'=>['Draft']],'inventory'),'status array');
invalidAdmin(fn()=>AdminWorkspace::filters([],'orders'),'unsupported register');

// The read path is wired to real PDO prepared statements, not an in-memory demo list.
$source=file_get_contents(dirname(__DIR__).'/app/AdminWorkspace.php');
$view=file_get_contents(dirname(__DIR__).'/app/AdminView.php');
$bootstrap=file_get_contents(dirname(__DIR__).'/app/bootstrap.php');
foreach(["$".'this->allow(\'products\', \'view\')',"$".'this->allow(\'inventory\', \'view\')',"'SELECT COUNT(*) FROM aelvo_products p'",'PDO::PARAM_INT','EXISTS (SELECT 1 FROM aelvo_variants','COALESCE(q.quantity,0)'] as $needle)assertAdmin(str_contains($source,$needle),'Missing safe register detail: '.$needle);
foreach(['AdminWorkspace($db,$actor))->products($_GET)','AdminWorkspace($db,$actor))->inventory($_GET)','AdminWorkspace($db,$actor))->overview()','htmlspecialchars'] as $needle)assertAdmin(str_contains($view,$needle) || str_contains($source,$needle),'Missing admin wiring: '.$needle);
assertAdmin(str_contains($bootstrap,"'OrderAdmin'") && str_contains($bootstrap,"'AdminWorkspace'") && str_contains($bootstrap,"'Settings'"),'Service missing from actual bootstrap');
echo "Admin workspace validation, safe placeholders, permissions and view wiring passed (offline; no MySQL/browser).\n";

/** PDO-shaped stub tests SQL parameter wiring, not SQL execution or actual MySQL behavior. */
final class StubAdminPDO extends PDO {
    public array $statements=[];
    public function __construct() {}
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        $stmt=new StubAdminStatement($query);$this->statements[]=$stmt;return $stmt;
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {
        $stmt=new StubAdminStatement($query);$this->statements[]=$stmt;return $stmt;
    }
}
final class StubAdminStatement extends PDOStatement {
    public array $params=[];
    public function __construct(public string $sql) {}
    public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR):bool {
        $this->params[$param]=[$value,$type];return true;
    }
    public function execute(?array $params=null):bool {return true;}
    public function fetchColumn(int $column=0):mixed {
        if(str_contains($this->sql,'aelvo_role_permissions'))return false;
        if(str_contains($this->sql,'COUNT(*) FROM aelvo_products p'))return 26;
        if(str_contains($this->sql,'COUNT(*) FROM aelvo_variants v JOIN'))return 2;
        return 1;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {
        if(str_contains($this->sql,'SELECT p.id,p.name,p.name_bn'))return [
            ['id'=>11,'name'=>'Sample wallet','name_bn'=>'নমুনা','slug'=>'wallet','category'=>'Wallets','price_minor'=>125000,'status'=>'Draft','version'=>1],
        ];
        if(str_contains($this->sql,'GROUP BY v.product_id'))return [['product_id'=>11,'variant_count'=>2,'available_units'=>7]];
        if(str_contains($this->sql,'SELECT v.id,v.product_id'))return [[
            'id'=>91,'product_id'=>11,'sku'=>'SK-91','colour'=>'Black','size'=>'L','stock'=>8,'reserved'=>1,
            'active'=>1,'name'=>'Sample wallet','product_status'=>'Draft','quarantined'=>2,'available'=>5,
        ]];
        return [];
    }
}
$db=new StubAdminPDO();$owner=['id'=>1,'is_owner'=>true,'status'=>'Active'];
$register=new AdminWorkspace($db,$owner);
$productResult=$register->products(['q'=>'wallet','status'=>'Draft','p'=>2]);
assertAdmin($productResult['total']===26&&$productResult['page']===2&&$productResult['pages']===2,'Product totals or last-page bounds incorrect');
assertAdmin($productResult['rows'][0]['available_units']===7&&$productResult['rows'][0]['variant_count']===2,'Product batch stock totals missing');
foreach($db->statements as $s) {
    if(str_contains($s->sql,'ORDER BY p.id DESC')) {
        assertAdmin($s->params[count($s->params)-1]===[25,PDO::PARAM_INT]&&$s->params[count($s->params)]===[25,PDO::PARAM_INT],'Product LIMIT/OFFSET not bound as integers');
        assertAdmin(str_contains($s->sql,'aelvo_product_details'),'Product parent SKU/barcode search missing');
        assertAdmin(str_contains($s->sql,'EXISTS (SELECT 1'),'Product SKU search not implemented');
    }
}
$db->statements=[];
$stockResult=$register->inventory(['q'=>'Black','status'=>'quarantined','p'=>3]);
assertAdmin($stockResult['total']===2&&$stockResult['page']===1&&$stockResult['rows'][0]['available']===5,'Inventory pagination and rows incorrect');
foreach($db->statements as $s) if(str_contains($s->sql,'ORDER BY v.id DESC')) {
    assertAdmin($s->params[5]===[25,PDO::PARAM_INT]&&$s->params[6]===[0,PDO::PARAM_INT],'Inventory LIMIT/OFFSET not bound');
    assertAdmin(str_contains($s->sql,'COALESCE(q.quantity,0)>0'),'Quarantine filter missing');
}
$overview=$register->overview();
assertAdmin(count($overview)>=16,'Owner work queue should cover product, inventory, order, returns, support, payment and courier queues');
assertAdmin(in_array('Gateway investigations awaiting independent review',array_column($overview,'label'),true),'Gateway review must appear only on authorized work queue');
assertAdmin(in_array('Approved refunds needing verified payout',array_column($overview,'label'),true),'Owner refund queue missing');
$unprivileged=new AdminWorkspace(new StubAdminPDO(),['id'=>5,'is_owner'=>false,'status'=>'Active']);
foreach(['products','inventory'] as $module) {
    try{$unprivileged->$module([]);}catch(RuntimeException $e){continue;}
    throw new RuntimeException('RBAC did not restrict '.$module);
}
assertAdmin($unprivileged->overview()===[],'Unauthorized dashboard exposed counts');
echo "PDO-stub pagination, placeholder bindings, permission isolation and work-queue scope passed (offline).\n";
