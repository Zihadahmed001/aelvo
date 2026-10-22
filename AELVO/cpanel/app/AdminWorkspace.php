<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use PDO;

/** Read-only, server-paginated admin registers. No writes or permission shortcuts. */
final class AdminWorkspace extends Service {
    public const PAGE_SIZE = 25;
    private const PRODUCT_STATUSES = ['', 'Active', 'Draft'];
    private const STOCK_FILTERS = ['', 'out', 'low', 'quarantined', 'inactive'];

    public static function filters(array $params, string $register): array {
        if (!in_array($register, ['products', 'inventory'], true)) throw new InvalidArgumentException('Invalid register.');
        $q = $params['q'] ?? '';
        $status = $params['status'] ?? '';
        $p = $params['p'] ?? '1';
        if (!is_string($q) || strlen($q) > 100 || preg_match('/[\x00-\x1f\x7f]/', $q)) {
            throw new InvalidArgumentException('Search must be at most 100 bytes without control characters.');
        }
        $valid = $register === 'products' ? self::PRODUCT_STATUSES : self::STOCK_FILTERS;
        if (!is_string($status) || !in_array($status, $valid, true)) throw new InvalidArgumentException('Invalid register filter.');
        if (!is_string($p) && !is_int($p)) throw new InvalidArgumentException('Invalid page.');
        if (!preg_match('/^[1-9][0-9]{0,4}$/D', (string)$p) || (int)$p > 10000) throw new InvalidArgumentException('Invalid page.');
        return ['q' => trim($q), 'status' => $status, 'p' => (int)$p];
    }

    public static function escapeLike(string $s): string {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
    }

    private function paged(string $countSql, string $listingSql, array $args, int $requestedPage): array {
        $q = $this->db->prepare($countSql);
        foreach ($args as $i => $value) $q->bindValue($i + 1, $value, PDO::PARAM_STR);
        $q->execute();
        $total = (int)$q->fetchColumn();
        $pages = max(1, (int)ceil($total / self::PAGE_SIZE));
        $page = min($pages, $requestedPage);
        $q = $this->db->prepare($listingSql.' LIMIT ? OFFSET ?');
        foreach ($args as $i => $value) $q->bindValue($i + 1, $value, PDO::PARAM_STR);
        $q->bindValue(count($args) + 1, self::PAGE_SIZE, PDO::PARAM_INT);
        $q->bindValue(count($args) + 2, ($page - 1) * self::PAGE_SIZE, PDO::PARAM_INT);
        $q->execute();
        return ['rows' => $q->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function products(array $params): array {
        $this->allow('products', 'view');
        $f = self::filters($params, 'products');
        $where = ["p.status<>'Archived'"];
        $args = [];
        if ($f['status'] !== '') { $where[] = 'p.status=?'; $args[] = $f['status']; }
        if ($f['q'] !== '') {
            $search = '%'.self::escapeLike($f['q']).'%';
            $where[] = "(p.name LIKE ? ESCAPE '!' OR p.name_bn LIKE ? ESCAPE '!' OR p.slug LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM aelvo_variants v2 WHERE v2.product_id=p.id AND v2.sku LIKE ? ESCAPE '!') OR EXISTS (SELECT 1 FROM aelvo_product_details pd WHERE pd.product_id=p.id AND (pd.parent_sku LIKE ? ESCAPE '!' OR pd.barcode LIKE ? ESCAPE '!')))";
            array_push($args, $search, $search, $search, $search, $search, $search);
        }
        $whereSql = ' WHERE '.implode(' AND ', $where);
        $result = $this->paged('SELECT COUNT(*) FROM aelvo_products p'.$whereSql,
            'SELECT p.id,p.name,p.name_bn,p.slug,p.category,p.price_minor,p.sale_price_minor,p.is_featured,p.is_new_arrival,p.status,p.version FROM aelvo_products p'.$whereSql.' ORDER BY p.id DESC', $args, $f['p']);
        // Read stock for the current visible page only, avoiding a whole-catalogue SUM on every search.
        $ids = array_map(static fn($r) => (int)$r['id'], $result['rows']);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = 'SELECT v.product_id,COUNT(*) AS variant_count,COALESCE(SUM(CASE WHEN v.active=1 THEN GREATEST(0,v.stock-v.reserved-COALESCE(q.quantity,0)) ELSE 0 END),0) AS available_units '
                .'FROM aelvo_variants v LEFT JOIN aelvo_variant_quarantine q ON q.variant_id=v.id WHERE v.product_id IN ('.$placeholders.') GROUP BY v.product_id';
            $stmt = $this->db->prepare($sql);
            foreach ($ids as $i=>$id) $stmt->bindValue($i+1, $id, PDO::PARAM_INT);
            $stmt->execute();
            $stock = [];
            foreach ($stmt->fetchAll() as $s) $stock[(int)$s['product_id']] = $s;
            foreach ($result['rows'] as &$row) {
                $s = $stock[(int)$row['id']] ?? null;
                $row['variant_count'] = (int)($s['variant_count'] ?? 0);
                $row['available_units'] = (int)($s['available_units'] ?? 0);
            }
            unset($row);
        }
        return $result + ['filters' => $f];
    }

    public function inventory(array $params): array {
        $this->allow('inventory', 'view');
        $f = self::filters($params, 'inventory');
        $available = 'GREATEST(0,v.stock-v.reserved-COALESCE(q.quantity,0))';
        $base = ' FROM aelvo_variants v JOIN aelvo_products p ON p.id=v.product_id LEFT JOIN aelvo_variant_quarantine q ON q.variant_id=v.id LEFT JOIN aelvo_product_details d ON d.product_id=p.id';
        $where = []; $args = [];
        if ($f['status'] === 'out') $where[] = 'v.active=1 AND '.$available.'=0';
        if ($f['status'] === 'low') $where[] = 'v.active=1 AND '.$available.' BETWEEN 1 AND COALESCE(d.low_stock_alert,5)';
        if ($f['status'] === 'quarantined') $where[] = 'COALESCE(q.quantity,0)>0';
        if ($f['status'] === 'inactive') $where[] = 'v.active=0';
        if ($f['q'] !== '') {
            $like = '%'.self::escapeLike($f['q']).'%';
            $where[] = "(p.name LIKE ? ESCAPE '!' OR p.name_bn LIKE ? ESCAPE '!' OR v.sku LIKE ? ESCAPE '!' OR v.colour LIKE ? ESCAPE '!')";
            array_push($args, $like, $like, $like, $like);
        }
        $whereSql = $where ? ' WHERE '.implode(' AND ', $where) : '';
        $result = $this->paged('SELECT COUNT(*)'.$base.$whereSql,
            'SELECT v.id,v.product_id,v.sku,v.colour,v.size,v.stock,v.reserved,v.active,p.name,p.status AS product_status,COALESCE(d.low_stock_alert,5) AS low_stock_alert,COALESCE(q.quantity,0) AS quarantined,'.$available.' AS available'.$base.$whereSql.' ORDER BY v.id DESC',
            $args, $f['p']);
        return $result + ['filters' => $f];
    }

    /** Only collect each module's counts when the actor can view that module. */
    public function overview(): array {
        $metrics = [];
        $modules = [
            'products' => [
                ['Active products', "SELECT COUNT(*) FROM aelvo_products WHERE status='Active'", 'products', 'status=Active'],
                ['Draft products', "SELECT COUNT(*) FROM aelvo_products WHERE status='Draft'", 'products', 'status=Draft'],
            ],
            'orders' => [
                ['Pending orders', "SELECT COUNT(*) FROM aelvo_orders WHERE status='Pending'", 'orders', 'status=Pending'],
                ['Confirmed orders', "SELECT COUNT(*) FROM aelvo_orders WHERE status='Confirmed'", 'orders', 'status=Confirmed'],
            ],
            'inventory' => [
                ['Low-stock variants (product thresholds)', "SELECT COUNT(*) FROM aelvo_variants v LEFT JOIN aelvo_variant_quarantine q ON q.variant_id=v.id LEFT JOIN aelvo_product_details d ON d.product_id=v.product_id WHERE v.active=1 AND GREATEST(0,v.stock-v.reserved-COALESCE(q.quantity,0)) BETWEEN 1 AND COALESCE(d.low_stock_alert,5)", 'inventory', 'status=low'],
                ['Out-of-stock variants', "SELECT COUNT(*) FROM aelvo_variants v LEFT JOIN aelvo_variant_quarantine q ON q.variant_id=v.id WHERE v.active=1 AND GREATEST(0,v.stock-v.reserved-COALESCE(q.quantity,0))=0", 'inventory', 'status=out'],
            ],
            'returns' => [
                ['Customer returns awaiting review', "SELECT COUNT(*) FROM aelvo_customer_return_requests WHERE status='Pending'", 'returns', 'r_status=Pending'],
                ['Inspected sellable returns awaiting stock posting', "SELECT COUNT(*) FROM aelvo_returns r JOIN aelvo_return_dispositions d ON d.return_id=r.id WHERE r.restocked=0 AND d.sellable_qty>0", 'returns', 's_status=unposted'],
            ],
            'support' => [
                ['Open tickets', "SELECT COUNT(*) FROM aelvo_support_tickets WHERE status='Open'", 'support', 'status=Open'],
            ],
            'payments' => [
                ['Proofs awaiting review', "SELECT COUNT(*) FROM aelvo_payment_proofs WHERE status='Pending'", 'payments', ''],
            ],
            'courier' => [
                ['Courier attempts to review', "SELECT COUNT(*) FROM aelvo_courier_shipments WHERE booking_state NOT IN ('Booked','Cancelled')", 'courier', ''],
                ['Courier exceptions awaiting review', "SELECT COUNT(*) FROM aelvo_courier_exception_cases WHERE state='Pending'", 'courier', ''],
                ['Approved courier holds awaiting resolution', "SELECT COUNT(*) FROM aelvo_courier_exception_cases WHERE state='Approved'", 'courier', ''],
                ['Delivery recovery clearances to review', "SELECT COUNT(*) FROM aelvo_courier_exception_clearances WHERE state='Pending'", 'courier', ''],
            ],
        ];
        foreach ($modules as $module => $items) {
            if (!Security::can($this->db, $this->actor, $module, 'view')) continue;
            foreach ($items as [$label, $sql, $page, $query]) {
                $metrics[] = ['label'=>$label, 'count'=>(int)$this->db->query($sql)->fetchColumn(), 'page'=>$page, 'query'=>$query];
            }
        }
        if (Security::can($this->db,$this->actor,'returns','view') && Security::can($this->db,$this->actor,'finance','view')) {
            $metrics[]=['label'=>'Approved refunds needing verified payout',
                'count'=>(int)$this->db->query("SELECT COUNT(*) FROM aelvo_refund_requests WHERE status='Approved'")->fetchColumn(),
                'page'=>'returns','query'=>'f_status=Approved'];
        }
        if (Security::can($this->db,$this->actor,'payments','view') && Security::can($this->db,$this->actor,'finance','view')) {
            $metrics[]=['label'=>'Gateway investigations awaiting independent review',
                'count'=>(int)$this->db->query("SELECT COUNT(*) FROM aelvo_gateway_exception_cases WHERE state='Pending'")->fetchColumn(),
                'page'=>'payments','query'=>''];
        }
        return $metrics;
    }
}
