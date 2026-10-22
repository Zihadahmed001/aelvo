<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/Security.php';
require dirname(__DIR__).'/app/ReturnOperations.php';
use Aelvo\ReturnOperations;

function okReturn(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
function badReturn(callable $check,string $message):void {
    try{$check();}catch(InvalidArgumentException $e){return;}
    throw new RuntimeException('Expected invalid request: '.$message);
}
okReturn(ReturnOperations::PAGE_SIZE===25,'Queue size changed');
okReturn(ReturnOperations::filters([],'requests')===['status'=>'Pending','q'=>'','page'=>1],'Customer request default incorrect');
okReturn(ReturnOperations::filters([],'refunds')['status']==='Pending','Refund default should only show pending');
okReturn(ReturnOperations::filters([],'receipts')['status']==='unposted','Inspected stock default incorrect');
okReturn(ReturnOperations::filters([],'items')['status']==='outstanding','Delivered item default incorrect');
okReturn(ReturnOperations::filters(['f_status'=>'Approved','f_q'=>' AB-12 ','f_page'=>2],'refunds')===['status'=>'Approved','q'=>'AB-12','page'=>2],'Refund filter normalization failed');
okReturn(ReturnOperations::escapeLike('%%_!')==='!%!%!_!!','Wildcards not escaped');
foreach (['requests','refunds','receipts','items'] as $queue){
    $prefix=['requests'=>'r','refunds'=>'f','receipts'=>'s','items'=>'i'][$queue];
    badReturn(fn()=>ReturnOperations::filters([$prefix.'_status'=>['invalid']],$queue),'invalid status array');
    badReturn(fn()=>ReturnOperations::filters([$prefix.'_status'=>'INVALID'],$queue),'unsupported status');
    foreach(['0','01','10001','1 OR 1=1','-1'] as $p)badReturn(fn()=>ReturnOperations::filters([$prefix.'_page'=>$p],$queue),'invalid page '.$p);
    badReturn(fn()=>ReturnOperations::filters([$prefix.'_q'=>"hello\n"],$queue),'controls');
    badReturn(fn()=>ReturnOperations::filters([$prefix.'_q'=>str_repeat('a',81)],$queue),'oversized term');
    badReturn(fn()=>ReturnOperations::filters([$prefix.'_q'=>['array']],$queue),'search array');
}
badReturn(fn()=>ReturnOperations::filters([],'unknown'),'unsupported queue');
$escaped=ReturnOperations::url('refunds',['status'=>'Approved','q'=>'A&B / 1'],3);
okReturn(str_contains($escaped,'f_q=A%26B%20%2F%201')&&str_contains($escaped,'f_page=3'),'Generated links must URL-encode filter values');

/** Intercepts PDO preparation and typed placeholder binding, without claiming a real MySQL run. */
final class ReturnStubDb extends PDO {
    public array $queries=[];
    public function __construct(){}
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        $stmt=new ReturnStubStatement($query);$this->queries[]=$stmt;return $stmt;
    }
}
final class ReturnStubStatement extends PDOStatement {
    public array $params=[];
    public function __construct(public string $sql){}
    public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR):bool {$this->params[$param]=[$value,$type];return true;}
    public function execute(?array $params=null):bool {return true;}
    public function fetchColumn(int $column=0):mixed {return str_contains($this->sql,'aelvo_role_permissions')?false:73;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {return [];}
}
$db=new ReturnStubDb();$owner=['id'=>1,'is_owner'=>true,'status'=>'Active'];$queues=new ReturnOperations($db,$owner);
foreach([
    ['requests',fn()=>$queues->customerRequests(['r_status'=>'Accepted','r_q'=>'12%','r_page'=>'99'])],
    ['items',fn()=>$queues->deliveredItems(['i_status'=>'outstanding','i_q'=>'X_','i_page'=>'2'])],
    ['receipts',fn()=>$queues->inspectedReceipts(['s_status'=>'unposted','s_q'=>'12','s_page'=>'2'])],
    ['refunds',fn()=>$queues->refunds(['f_status'=>'Approved','f_q'=>'ABC','f_page'=>'1'])],
] as [$label,$callback]){
    $old=count($db->queries);$result=$callback();
    okReturn($result['total']===73&&$result['pages']===3,'Incorrect '.$label.' count/pagination');
    $count=$db->queries[$old];$list=$db->queries[$old+1];
    okReturn(str_contains($count->sql,'COUNT(*)')&&str_contains($list->sql,'LIMIT ? OFFSET ?'),'Unbounded '.$label.' query');
    okReturn(!str_contains($count->sql,'12%')&&!str_contains($count->sql,'X_')&&!str_contains($count->sql,'ABC'),'Raw search interpolated into SQL');
    $last=count($list->params);
    okReturn($list->params[$last-1]===[25,PDO::PARAM_INT],'Untyped page size: '.$label);
    okReturn($list->params[$last][1]===PDO::PARAM_INT,'Untyped offset: '.$label);
    okReturn($result['page']<=3,'Page not clamped to total pages');
    if($label==='refunds')okReturn(str_contains($count->sql,'r.status=?'),'Refund status not applied');
    if($label==='receipts')okReturn(str_contains($count->sql,'d.sellable_qty>0'),'Sellable stock filter missing');
    if($label==='items')okReturn(str_contains($count->sql,"o.status='Delivered'"),'Undelivered items exposed');
}
$staff=['id'=>3,'is_owner'=>false,'status'=>'Active'];
try{(new ReturnOperations($db,$staff))->refunds([]);throw new RuntimeException('Non-permitted staff viewed refund amounts');}
catch(RuntimeException $e){if($e->getCode()!==403)throw $e;}
$root=dirname(__DIR__);
foreach(['app/ReturnRequestsView.php','app/ReturnsView.php','public/index.php'] as $file) {
    $source=file_get_contents($root.'/'.$file);
    okReturn(str_contains($source,'ReturnOperations'),'Missing route/view integration: '.$file);
}
$dashboard=file_get_contents($root.'/app/AdminWorkspace.php');
okReturn(str_contains($dashboard,'Approved refunds needing verified payout') && str_contains($dashboard,'Customer returns awaiting review'),'Return counts not wired into the dashboard');
$view=file_get_contents($root.'/app/ReturnsView.php');
okReturn(str_contains($view,"if(!\$can('finance','view'))"),'Refund data must remain behind finance permission');
echo "Return/refund/receipt/item queue filters, bounded SQL, typed placeholders and finance guard passed (offline; no MySQL/browser).\n";
