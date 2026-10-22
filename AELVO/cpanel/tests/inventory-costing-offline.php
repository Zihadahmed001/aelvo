<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/InventoryCosting.php';
require_once dirname(__DIR__).'/app/Schema.php';
use Aelvo\InventoryCosting;
use Aelvo\Schema;
function testCost(bool $ok,string $name): void {if(!$ok)throw new RuntimeException('FAILED: '.$name);echo "PASS $name\n";}
// Cannot substitute guessed prices for genuine purchase landed cost.
testCost(InventoryCosting::allocateMinor(100,3,1)===33,'one unit rounded down');
testCost(InventoryCosting::allocateMinor(67,2,2)===67,'final receipt gets remaining cents');
testCost(InventoryCosting::allocateMinor(0,2,1)===0,'known zero-cost is distinct from NULL');
testCost(InventoryCosting::allocateMinor(1000000000000,1000000,500000)===500000000000,'large-value split without floating point');
$initial=109; $remainingCost=$initial; $remainingQty=7; $sum=0;
foreach([2,1,3,1] as $units){
    $allocated=InventoryCosting::allocateMinor($remainingCost,$remainingQty,$units);
    $remainingCost-=$allocated;$remainingQty-=$units;$sum+=$allocated;
}
testCost($sum===$initial && $remainingQty===0 && $remainingCost===0,'multiple FIFO draws exactly conserve landed cents');
foreach([[100,0,1],[100,2,0],[100,2,3],[-1,2,1]] as $case){
    $threw=false;try {InventoryCosting::allocateMinor(...$case);}catch(InvalidArgumentException){$threw=true;}
    testCost($threw,'invalid quantity and negative costs rejected');
}
testCost(in_array('inventory-costing',Schema::FILES,true),'schema appended to versioned load order');
$sql=file_get_contents(dirname(__DIR__).'/database/inventory-costing.sql');
testCost(count(Schema::statements($sql))===5,'5 cost-layer and disposal schema tables parse safely');
