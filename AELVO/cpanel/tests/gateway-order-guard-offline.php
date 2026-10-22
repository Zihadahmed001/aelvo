<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/GatewayPayments.php';

use Aelvo\GatewayPayments;

function assertGuard(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}

foreach(['Initiating','Ready','Uncertain','Review','RiskHold','SandboxValidated','NewUnexpectedProviderState'] as $state){
    assertGuard(GatewayPayments::blocksOrderAction($state),'Unresolved gateway state was not blocked: '.$state);
}
foreach(['Verified','FailedVerified','CancelledVerified'] as $state){
    assertGuard(!GatewayPayments::blocksOrderAction($state),'Reconciled gateway state was blocked: '.$state);
}

// Purely in-memory PDO statement test; no MySQL/provider connection is attempted.
final class GuardStatement extends PDOStatement {
    private array $items;
    private string $sql;
    private int $orderId=0;
    public function __construct(array $items,string $sql){$this->items=$items;$this->sql=$sql;}
    public function execute(?array $params=null): bool {
        assertGuard(isset($params[0])&&is_int($params[0])&&$params[0]>0,'Order id must be bound');
        assertGuard(str_contains($this->sql,'state NOT IN'),'Must exclude reconciled states in SQL');
        assertGuard(str_contains($this->sql,'order_id=?'),'Must be scoped to the order');
        $this->orderId=$params[0];return true;
    }
    public function fetchColumn(int $column=0): mixed {
        foreach($this->items[$this->orderId]??[] as $state){
            if(GatewayPayments::blocksOrderAction($state))return $state;
        }
        return false;
    }
}
final class GuardDatabase extends PDO {
    public string $lastSql='';
    public function __construct(private array $items){}
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        $this->lastSql=$query;return new GuardStatement($this->items,$query);
    }
}
$db=new GuardDatabase([18=>['Verified','Ready'],19=>['FailedVerified','CancelledVerified'],20=>['Review']]);
assertGuard(GatewayPayments::unresolvedForOrder($db,18)==='Ready','Active attempt after verified attempt must hold order');
assertGuard(!str_contains($db->lastSql,'FOR UPDATE'),'Read-only order display must not lock payment rows');
assertGuard(GatewayPayments::unresolvedForOrder($db,19)===null,'Only independently closed attempts must not hold order');
GatewayPayments::assertNoUnresolved($db,19);
assertGuard(str_contains($db->lastSql,'FOR UPDATE'),'Write-path guard must lock matching attempt rows');
foreach([18,20] as $orderId){
    try{GatewayPayments::assertNoUnresolved($db,$orderId);}
    catch(InvalidArgumentException $e){continue;}
    throw new RuntimeException('Checkout/order action was not held for order '.$orderId);
}

// Wiring assertions supplement, NOT replace, later database race/concurrency tests.
$root=dirname(__DIR__).'/app/';
foreach(['Orders.php','Courier.php','Payments.php'] as $filename){
    assertGuard(str_contains(file_get_contents($root.$filename),'GatewayPayments::assertNoUnresolved('),
        $filename.' is missing the shared gateway safety guard');
}
assertGuard(str_contains(file_get_contents($root.'OrderAdmin.php'),'GatewayPayments::unresolvedForOrder('),
    'Admin order detail must surface pending gateway state');
assertGuard(str_contains(file_get_contents($root.'GatewayPayments.php'),'Returns::committed('),
    'New gateway charges and callbacks must respect refund commitments');
assertGuard(str_contains(file_get_contents($root.'GatewayPayments.php'),"status='Pending' LIMIT 1 FOR UPDATE"),
    'New gateway initiation must stop on a pending manual payment proof');
assertGuard(str_contains(file_get_contents($root.'Payments.php'),'GatewayPayments::assertNoUnresolved($db,$order)'),
    'New manual proof claims must stop while gateway attempt is unresolved');
assertGuard(str_contains(file_get_contents(dirname(__DIR__).'/public/checkout-view.php'),'GatewayPayments::unresolvedForOrder('),
    'Checkout must hide a second charge button during gateway review');

echo "Gateway states, scoped/locking payment-action guard and order/courier/manual-receipt wiring checked offline. No MySQL or provider activity.\n";
