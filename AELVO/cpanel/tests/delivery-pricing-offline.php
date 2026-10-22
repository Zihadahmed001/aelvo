<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/DeliveryPricing.php';
require dirname(__DIR__).'/app/Payments.php';

use Aelvo\DeliveryPricing;
use Aelvo\Payments;

function check(bool $ok, string $explanation): void {
    if (!$ok) throw new RuntimeException($explanation);
}
function rejects(callable $task): void {
    try { $task(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Invalid pricing input was accepted');
}

$base = ['dhaka_minor'=>7000, 'outside_minor'=>12000, 'cod_enabled'=>true];
check(DeliveryPricing::fee($base,'dhaka',20000)===7000, 'Old flat Dhaka rate changed');
check(DeliveryPricing::fee($base,'outside',20000)===12000, 'Old flat outside rate changed');
rejects(fn()=>DeliveryPricing::fee($base, 'other', 30000));
rejects(fn()=>DeliveryPricing::fee($base, 'dhaka', -1));

$tiers=DeliveryPricing::parseTiers("500.00, 50.00, 90.00\n1000.00, 0.00, 60.00");
check(count($tiers)===2 && $tiers[0]['min_minor']===50000, 'Tier amount parser incorrect');
check(DeliveryPricing::parseTiers(DeliveryPricing::formatTiers($tiers))===$tiers, 'Tier editor round trip failed');
$rules=$base+['value_tiers'=>$tiers,'free_threshold_minor'=>150000];
check(DeliveryPricing::fee($rules,'outside',49999)===12000, 'Below threshold should use base fee');
check(DeliveryPricing::fee($rules,'outside',50000)===9000, 'First tier boundary wrong');
check(DeliveryPricing::fee($rules,'dhaka',100000)===0, 'Second tier boundary wrong');
check(DeliveryPricing::fee($rules,'outside',149999)===6000, 'Second tier range wrong');
check(DeliveryPricing::fee($rules,'outside',150000)===0, 'Free shipping priority wrong');
// Qualification uses discounted merchandise, not an inflated pre-discount subtotal.
check(DeliveryPricing::fee($rules,'outside',150000-2000)===6000, 'Discounted merchandise threshold must apply');
foreach (["", "0.00, 20.00, 30.00", "500.00, 70.00", "500.00, 70.00, 80.00\n500.00, 1.00, 1.00", "500.00, -1.00, 0.00", "500.00, 10000.01, 0.00"] as $bad) {
    if($bad===''){check(DeliveryPricing::parseTiers('')===[], 'Blank tiers must disable tiers');continue;}
    rejects(fn()=>DeliveryPricing::parseTiers($bad));
}
rejects(fn()=>DeliveryPricing::fee($base+['value_tiers'=>[['min_minor'=>50000,'dhaka_minor'=>-1,'outside_minor'=>0]]],'dhaka',60000));

check(Payments::advance(['advance_type'=>'fixed','advance_value'=>30000],200000)===30000, 'Original fixed advance changed');
check(Payments::advance(['advance_type'=>'percent','advance_value'=>13],999)===130, 'Percentage must round upwards');
$advanced=['advance_type'=>'fixed','advance_value'=>10000,'high_value_advance_min_minor'=>100000,'high_value_advance_type'=>'percent','high_value_advance_value'=>25];
check(Payments::advance($advanced,99999)===10000, 'Lower total should use default advance');
check(Payments::advance($advanced,100000)===25000, 'High-value advance threshold incorrect');
check(Payments::advance($advanced,500000)===125000, 'High-value advance formula incorrect');
check(Payments::advance($advanced+['high_value_advance_type'=>'none'],500000)===125000, 'Fixture collision check');
$advanced['high_value_advance_type']='none';
check(Payments::advance($advanced,100000)===0, 'Explicit no-advance alternate rule must be respected');
rejects(fn()=>Payments::advance(['advance_type'=>'percent','advance_value'=>120],100000));

$checkout=file_get_contents(dirname(__DIR__).'/app/Checkout.php');
check(substr_count($checkout,'DeliveryPricing::fee(')===2, 'Checkout must reprice shipping at quote and transactional placement');
check(str_contains($checkout,'Coupons::quote(')&&str_contains($checkout,'$subtotal-$discount'), 'Delivery discounts must be computed from coupon-adjusted merchandise');
check(str_contains($checkout,'$actualFee!==(int)$data[\'delivery_minor\']'), 'Stale shipping price must be rejected');
check(str_contains($checkout,'Payments::advance($settings,$actualTotal)'), 'Required advance must be recalculated at order placement');

echo "Flat-price compatibility, zone/value tiers, free threshold, discount sequencing, high-value advances and checkout wiring checked offline. No MySQL/browser/provider test.\n";
