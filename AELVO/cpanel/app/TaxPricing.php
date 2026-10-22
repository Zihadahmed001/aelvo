<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;

/** Merchant-configured EXCLUSIVE sales-tax computation, not tax advice.
 * Absence of configuration means zero tax for legacy installations.
 * Amounts are BDT minor units and basis points, never floating point.
 */
final class TaxPricing {
    public static function settings(array $settings): array {
        $mode=$settings['mode']??'off';
        $rate=$settings['rate_basis_points']??0;
        $shipping=$settings['shipping_taxable']??false;
        if(!in_array($mode,['off','exclusive'],true) || !is_int($rate) || $rate<0 || $rate>10000 || !is_bool($shipping))
            throw new InvalidArgumentException('Tax settings require review.');
        if($mode==='exclusive' && $rate===0)throw new InvalidArgumentException('Set a positive tax rate before enabling tax.');
        return ['mode'=>$mode,'rate_basis_points'=>$mode==='off'?0:$rate,'shipping_taxable'=>$mode==='exclusive'?$shipping:false];
    }
    public static function calculate(array $settings,int $merchandiseNetMinor,int $deliveryMinor): array {
        if($merchandiseNetMinor<0||$merchandiseNetMinor>1000000000000||$deliveryMinor<0||$deliveryMinor>1000000000000)
            throw new InvalidArgumentException('Invalid taxable order value.');
        $s=self::settings($settings);
        $base=$s['mode']==='off'?0:$merchandiseNetMinor+($s['shipping_taxable']?$deliveryMinor:0);
        // Half-up rounding ONCE on the total taxable base, at 1/100 BDT.
        $tax=intdiv($base*$s['rate_basis_points']+5000,10000);
        return $s+['taxable_merchandise_minor'=>$s['mode']==='off'?0:$merchandiseNetMinor,
            'taxable_shipping_minor'=>$s['shipping_taxable']?$deliveryMinor:0,
            'tax_minor'=>$tax];
    }
    public static function parseRate(string $rate): int {
        $rate=trim($rate);
        if(!preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?$/D',$rate))
            throw new InvalidArgumentException('Enter a tax percentage with at most two decimal places.');
        [$whole,$fraction]=array_pad(explode('.',$rate,2),2,'');
        $points=(int)$whole*100+(int)str_pad($fraction,2,'0');
        if($points>10000)throw new InvalidArgumentException('Tax rate must not exceed 100%.');
        return $points;
    }
}
