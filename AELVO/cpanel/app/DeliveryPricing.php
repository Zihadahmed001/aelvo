<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;

/**
 * Deterministic, side-effect-free merchant-configured shipping calculation.
 * Only the two currently supported delivery zones are allowed. This does not
 * assume weight, district, taxation or a store return/shipping policy.
 */
final class DeliveryPricing {
    public const ZONES = ['dhaka', 'outside'];

    public static function fee(array $settings, string $zone, int $merchandiseNetMinor): int {
        if (!in_array($zone, self::ZONES, true) || $merchandiseNetMinor < 0) {
            throw new InvalidArgumentException('Choose a valid delivery area and order amount.');
        }
        $base = self::amount($settings[$zone.'_minor'] ?? null, 1000000);
        $free = self::amount($settings['free_threshold_minor'] ?? 0, 100000000);
        if ($free > 0 && $merchandiseNetMinor >= $free) return 0;

        // Old installations have no tiers; they retain their flat rates.
        $tiers = $settings['value_tiers'] ?? [];
        if (!is_array($tiers) || count($tiers) > 12) {
            throw new InvalidArgumentException('Delivery configuration needs review.');
        }
        $last = 0;
        $rate = $base;
        foreach ($tiers as $tier) {
            if (!is_array($tier)) throw new InvalidArgumentException('Delivery configuration needs review.');
            $min = self::amount($tier['min_minor'] ?? null, 100000000);
            $dhaka = self::amount($tier['dhaka_minor'] ?? null, 1000000);
            $outside = self::amount($tier['outside_minor'] ?? null, 1000000);
            if ($min <= $last) throw new InvalidArgumentException('Delivery tiers must be strictly increasing.');
            if ($merchandiseNetMinor >= $min) $rate = $zone === 'dhaka' ? $dhaka : $outside;
            $last = $min;
        }
        return $rate;
    }

    /** Parse operator-entered ascending rows: minimum BDT, Dhaka BDT, outside BDT. */
    public static function parseTiers(string $text): array {
        $text = trim($text);
        if ($text === '') return [];
        if (strlen($text) > 1200) throw new InvalidArgumentException('Too many delivery tiers.');
        $lines = preg_split('/\r\n|\n|\r/', $text);
        if (count($lines) > 12) throw new InvalidArgumentException('Keep at most 12 delivery tiers.');
        $tiers = []; $last = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') throw new InvalidArgumentException('Remove blank lines in delivery tiers.');
            $parts = array_map('trim', explode(',', $line));
            if (count($parts) !== 3) throw new InvalidArgumentException('Each delivery tier needs minimum, Dhaka fee and outside fee.');
            $minimum = Input::money($parts[0]);
            $dhaka = Input::money($parts[1]);
            $outside = Input::money($parts[2]);
            if ($minimum <= $last || $minimum > 100000000 || $dhaka > 1000000 || $outside > 1000000) {
                throw new InvalidArgumentException('Delivery tiers must have increasing minimums and valid fees.');
            }
            $tiers[] = ['min_minor'=>$minimum,'dhaka_minor'=>$dhaka,'outside_minor'=>$outside];
            $last = $minimum;
        }
        return $tiers;
    }

    public static function formatTiers(array $tiers): string {
        $lines = [];
        foreach ($tiers as $tier) {
            if (!is_array($tier)) continue;
            $lines[] = implode(', ', [
                number_format((int)($tier['min_minor'] ?? 0) / 100, 2, '.', ''),
                number_format((int)($tier['dhaka_minor'] ?? 0) / 100, 2, '.', ''),
                number_format((int)($tier['outside_minor'] ?? 0) / 100, 2, '.', ''),
            ]);
        }
        return implode("\n", $lines);
    }

    private static function amount(mixed $value, int $limit): int {
        if (!is_int($value) || $value < 0 || $value > $limit) {
            throw new InvalidArgumentException('Delivery settings are invalid.');
        }
        return $value;
    }
}
