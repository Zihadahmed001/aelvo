<?php
declare(strict_types=1);
namespace Aelvo;

/** Pure public-navigation projection; never exposes Hidden taxonomy records. */
final class StorefrontNavigation {
    public static function build(array $categories, array $brands): array {
        $roots = []; $children = []; $visibleBrands = [];
        foreach ($categories as $category) {
            if (($category['status'] ?? '') !== 'Active' || !self::validSlug((string)($category['slug'] ?? ''))) continue;
            $id = (int)($category['id'] ?? 0);
            if ($id <= 0) continue;
            $item = self::item($category, 'category');
            if (empty($category['parent_id'])) {
                $roots[$id] = ['item' => $item, 'root_code' => (string)($category['root_code'] ?? ''), 'children' => []];
            } else {
                $children[] = ['parent_id' => (int)$category['parent_id'], 'root_code' => (string)($category['root_code'] ?? ''), 'item' => $item];
            }
        }
        foreach ($children as $child) {
            $parent = $child['parent_id'];
            if (isset($roots[$parent]) && $roots[$parent]['root_code'] === $child['root_code']) {
                $roots[$parent]['children'][] = $child['item'];
            }
        }
        foreach ($brands as $brand) {
            if (($brand['status'] ?? '') !== 'Active' || !self::validSlug((string)($brand['slug'] ?? ''))) continue;
            $visibleBrands[] = self::item($brand, 'brand');
        }
        // Keep the approved fashion navigation order, not alphabetical SQL order.
        $priority = ['Shoes' => 0, 'Socks' => 1, 'Belts' => 2, 'Wallets' => 3, 'Bags' => 4];
        $roots = array_values($roots);
        usort($roots, static fn(array $a, array $b): int =>
            ($priority[$a['root_code']] ?? 99) <=> ($priority[$b['root_code']] ?? 99));
        return ['categories' => $roots, 'brands' => array_slice($visibleBrands, 0, 24)];
    }

    private static function validSlug(string $slug): bool {
        return strlen($slug) <= 160 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) === 1;
    }

    private static function item(array $row, string $kind): array {
        return [
            'id' => (int)$row['id'],
            'name' => (string)($row['name'] ?? ''),
            'name_bn' => (string)($row['name_bn'] ?? ''),
            'path' => PublicRoutes::entityPath($kind, (string)$row['slug']),
        ];
    }
}
