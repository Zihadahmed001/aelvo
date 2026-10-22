<?php
declare(strict_types=1);
namespace Aelvo;

use PDO;
use RuntimeException;

/** Write immutable order-time tax facts inside the surrounding order transaction. */
final class OrderTaxes {
    public static function record(PDO $db,int $orderId,array $tax): void {
        if($orderId<1 || !isset($tax['mode'],$tax['rate_basis_points'],$tax['shipping_taxable'],$tax['taxable_merchandise_minor'],$tax['taxable_shipping_minor'],$tax['tax_minor']))
            throw new RuntimeException('Tax snapshot is incomplete.');
        $normalized=TaxPricing::settings($tax);
        if(($normalized['mode']==='off' && ((int)$tax['taxable_merchandise_minor']!==0 || (int)$tax['taxable_shipping_minor']!==0)) ||
           (!$normalized['shipping_taxable'] && (int)$tax['taxable_shipping_minor']!==0))
            throw new RuntimeException('Taxable base disagrees with the policy.');
        $base=(int)$tax['taxable_merchandise_minor']+(int)$tax['taxable_shipping_minor'];
        if($base<0 || $base>1000000000000 || (int)$tax['tax_minor']!==intdiv($base*$normalized['rate_basis_points']+5000,10000))
            throw new RuntimeException('Tax snapshot does not reconcile.');
        $q=$db->prepare('INSERT INTO aelvo_order_tax_snapshots(order_id,mode,rate_basis_points,shipping_taxable,taxable_merchandise_minor,taxable_shipping_minor,tax_minor) VALUES (?,?,?,?,?,?,?)');
        $q->execute([$orderId,$tax['mode'],$tax['rate_basis_points'],$tax['shipping_taxable']?1:0,
            $tax['taxable_merchandise_minor'],$tax['taxable_shipping_minor'],$tax['tax_minor']]);
    }
}
