<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;

/** Price changes are catalogue data, NEVER historical order-line rewrites. */
final class VariantPricing {
    public static function override(mixed $input): ?int {
        if($input===null || $input==='')return null; // inherit product base
        $amount=Input::money($input);
        if($amount<1)throw new InvalidArgumentException('A variant price override must be greater than zero. Leave blank to inherit the product price.');
        return $amount;
    }
    public static function sale(mixed $input,int $regular): ?int {
        if($input===null || $input==='')return null;
        $sale=Input::money($input);
        if($regular<1 || $sale<1 || $sale>=$regular)throw new InvalidArgumentException('Sale price must be greater than zero and lower than regular price.');
        return $sale;
    }
    /** An explicit variant price takes precedence; base sales affect inheriting variants only. */
    public static function effective(int $base, mixed $override, ?int $sale=null): int {
        if($base<0 || ($sale!==null && ($sale<1 || $sale>=$base)))throw new InvalidArgumentException('Invalid base/sale price.');
        if($override===null)return $sale??$base;
        if(!is_int($override) && (!is_string($override)||!ctype_digit($override)))throw new InvalidArgumentException('Invalid variant price.');
        $price=(int)$override;
        if($price<1)throw new InvalidArgumentException('Invalid variant price.');
        return $price;
    }
    /** Public display and structured data must reflect currently selectable variants. */
    public static function range(int $base,array $variants,?int $sale=null): array {
        $selected=array_values(array_filter($variants,static fn(array $v):bool=>(int)($v['available']??0)>0));
        if(!$selected)$selected=$variants;
        if(!$selected)return ['min'=>$sale??$base,'max'=>$sale??$base];
        $prices=array_map(static fn(array $v):int=>self::effective($base,$v['price_minor']??null),$selected);
        return ['min'=>min($prices),'max'=>max($prices)];
    }
}
