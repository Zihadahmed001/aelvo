<?php
declare(strict_types=1);
namespace Aelvo;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/** Read-only operational reports. Never labels order totals or advances as profit. */
final class Reports extends Service {
    public static function dates(mixed $from,mixed $to): array {
        if(!is_string($from)||!is_string($to))throw new InvalidArgumentException('Choose valid report dates.');
        $zone=new DateTimeZone('UTC');
        $parse=static function(string $date)use($zone): DateTimeImmutable {
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))throw new InvalidArgumentException('Use a date in YYYY-MM-DD format.');
            $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);
            if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('Choose a valid calendar date.');
            return $parsed;
        };
        $a=$parse($from);$b=$parse($to);
        if($b<$a||$a->diff($b)->days>366)throw new InvalidArgumentException('Select a range of 367 days or less.');
        return [$a->format('Y-m-d 00:00:00'),$b->modify('+1 day')->format('Y-m-d 00:00:00')];
    }
    /** Bounded, query-parameterized operational snapshot. */
    public function snapshot(string $from,string $to): array {
        $this->allow('reports','view');[$start,$end]=self::dates($from,$to);$range=[$start,$end];
        $q=$this->db->prepare('SELECT status,COUNT(*) AS count,COALESCE(SUM(total_minor),0) AS total_minor FROM aelvo_orders WHERE created_at>=? AND created_at<? GROUP BY status ORDER BY status');
        $q->execute($range);$orders=$q->fetchAll(PDO::FETCH_ASSOC);
        $sum=function(string $sql)use($range):int{$q=$this->db->prepare($sql);$q->execute($range);return (int)$q->fetchColumn();};
        $measure=[
            'order_count'=>$sum('SELECT COUNT(*) FROM aelvo_orders WHERE created_at>=? AND created_at<?'),
            'order_placed_minor'=>$sum('SELECT COALESCE(SUM(total_minor),0) FROM aelvo_orders WHERE created_at>=? AND created_at<?'),
            'delivered_minor'=>$sum("SELECT COALESCE(SUM(total_minor),0) FROM aelvo_orders WHERE status='Delivered' AND created_at>=? AND created_at<?"),
            'verified_receipts_minor'=>$sum('SELECT COALESCE(SUM(amount_minor),0) FROM aelvo_order_receipts WHERE created_at>=? AND created_at<?'),
            'paid_refunds_minor'=>$sum('SELECT COALESCE(SUM(amount_minor),0) FROM aelvo_refunds WHERE created_at>=? AND created_at<?'),
            'recorded_expenses_minor'=>$sum("SELECT COALESCE(SUM(-l.delta_minor),0) FROM aelvo_ledger l JOIN aelvo_finance_events e ON e.id=l.event_id WHERE e.kind='expense' AND e.created_at>=? AND e.created_at<?"),
            'purchase_value_minor'=>$sum('SELECT COALESCE(SUM(total_minor),0) FROM aelvo_purchases WHERE created_at>=? AND created_at<?'),
            'supplier_paid_minor'=>$sum('SELECT COALESCE(SUM(amount_minor),0) FROM aelvo_supplier_payments WHERE recorded_at>=? AND recorded_at<?'),
            'returns_count'=>$sum('SELECT COUNT(*) FROM aelvo_returns WHERE created_at>=? AND created_at<?'),
            'courier_collected_minor'=>$sum('SELECT COALESCE(SUM(collected_minor),0) FROM aelvo_courier_collections WHERE recorded_at>=? AND recorded_at<?'),
            'courier_remitted_minor'=>$sum('SELECT COALESCE(SUM(remitted_minor),0) FROM aelvo_courier_remittances WHERE recorded_at>=? AND recorded_at<?'),
            'courier_fees_minor'=>$sum('SELECT COALESCE(SUM(fee_minor),0) FROM aelvo_courier_remittances WHERE recorded_at>=? AND recorded_at<?'),
            'courier_booked_minor'=>$sum("SELECT COALESCE(SUM(cod_minor),0) FROM aelvo_courier_shipments WHERE booking_state='Booked' AND created_at>=? AND created_at<?"),
            'gateway_validated_minor'=>$sum('SELECT COALESCE(SUM(amount_minor),0) FROM aelvo_gateway_receipts WHERE confirmed_at>=? AND confirmed_at<?'),
            'gateway_cash_settled_minor'=>$sum('SELECT COALESCE(SUM(received_minor),0) FROM aelvo_gateway_settlements WHERE created_at>=? AND created_at<?')+$sum('SELECT COALESCE(SUM(credit_minor),0) FROM aelvo_gateway_payout_batches WHERE created_at>=? AND created_at<?'),
            'gateway_fees_minor'=>$sum('SELECT COALESCE(SUM(fee_minor),0) FROM aelvo_gateway_settlements WHERE created_at>=? AND created_at<?')+$sum('SELECT COALESCE(SUM(fee_minor),0) FROM aelvo_gateway_payout_batches WHERE created_at>=? AND created_at<?'),
        ];
        $q=$this->db->prepare('SELECT source_type,COUNT(*) AS entries,COALESCE(SUM(stock_delta),0) AS on_hand_change,COALESCE(SUM(reserved_delta),0) AS reservation_change FROM aelvo_movements WHERE created_at>=? AND created_at<? GROUP BY source_type ORDER BY source_type');
        $q->execute($range);$movements=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$this->db->query("SELECT COUNT(*) AS variant_count, COALESCE(SUM(v.stock),0) AS on_hand, COALESCE(SUM(v.reserved),0) AS reserved, COALESCE(SUM(COALESCE(q.quantity,0)),0) AS quarantined, COALESCE(SUM(GREATEST(0,v.stock-v.reserved-COALESCE(q.quantity,0))),0) AS available FROM aelvo_variants v LEFT JOIN aelvo_variant_quarantine q ON q.variant_id=v.id WHERE v.active=1");
        $stock=$q->fetch(PDO::FETCH_ASSOC);
        $low=$this->db->query("SELECT v.id,p.name,v.colour,v.size,v.sku,v.stock,v.reserved,COALESCE(d.low_stock_alert,5) AS low_stock_alert,COALESCE(q.quantity,0) AS quarantined,GREATEST(0,v.stock-v.reserved-COALESCE(q.quantity,0)) AS available FROM aelvo_variants v JOIN aelvo_products p ON p.id=v.product_id LEFT JOIN aelvo_variant_quarantine q ON q.variant_id=v.id LEFT JOIN aelvo_product_details d ON d.product_id=p.id WHERE v.active=1 AND p.status='Active' AND GREATEST(0,v.stock-v.reserved-COALESCE(q.quantity,0))<=COALESCE(d.low_stock_alert,5) ORDER BY available ASC,v.id ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        $accounts=$this->db->query('SELECT name,kind,balance_minor FROM aelvo_accounts WHERE active=1 ORDER BY id LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
        // Point-in-time externally held COD, not AELVO-owned bank/cash balances.
        $courierHeld=(int)$this->db->query('SELECT COALESCE(SUM(GREATEST(0,c.collected_minor-COALESCE(r.remitted,0)-COALESCE(r.fees,0))),0) FROM aelvo_courier_collections c LEFT JOIN (SELECT shipment_id,SUM(remitted_minor) AS remitted,SUM(fee_minor) AS fees FROM aelvo_courier_remittances GROUP BY shipment_id) r ON r.shipment_id=c.shipment_id')->fetchColumn();
        $courierUnknown=(int)$this->db->query("SELECT COUNT(*) FROM aelvo_courier_shipments WHERE booking_state IN ('Prepared','Unknown')")->fetchColumn();
        $gatewayHeld=(int)$this->db->query('SELECT COALESCE(SUM(GREATEST(0,r.amount_minor-COALESCE(s.received,0)-COALESCE(s.fees,0))),0) FROM aelvo_gateway_receipts r LEFT JOIN (SELECT attempt_id,SUM(received_minor) received,SUM(fee_minor) fees FROM (SELECT attempt_id,received_minor,fee_minor FROM aelvo_gateway_settlements UNION ALL SELECT attempt_id,credited_minor,fee_minor FROM aelvo_gateway_payout_allocations) receipts GROUP BY attempt_id) s ON s.attempt_id=r.attempt_id')->fetchColumn();
        $gatewayPending=(int)$this->db->query("SELECT COUNT(*) FROM aelvo_gateway_attempts WHERE state IN ('Initiating','Ready','Uncertain','Review','RiskHold')")->fetchColumn();
        return ['from'=>$from,'to'=>$to,'orders'=>$orders,'movements'=>$movements,'measures'=>$measure,'stock'=>$stock,'low_stock'=>$low,'accounts'=>$accounts,'courier_held_minor'=>$courierHeld,'courier_unknown_count'=>$courierUnknown,'gateway_unsettled_minor'=>$gatewayHeld,'gateway_needs_review_count'=>$gatewayPending];
    }
    /**
     * Cost coverage, not a profit-and-loss calculation: only dated delivery
     * allocations are counted; old deliveries without postings remain unknown.
     * Returns, paid refunds, discounts, gateway/courier fees and taxes must be
     * reconciled before a reliable net-income figure is possible.
     */
    public function costCoverage(string $from,string $to): array {
        $this->allow('reports','view');
        $this->allow('finance','view');
        [$start,$end]=self::dates($from,$to);
        $q=$this->db->prepare('SELECT COALESCE(SUM(known_cost_minor),0) AS known_cost_minor, COALESCE(SUM(known_qty),0) AS known_qty, COALESCE(SUM(unknown_qty),0) AS unknown_qty, COUNT(*) AS posted_lines FROM aelvo_order_item_cost_postings WHERE created_at>=? AND created_at<?');
        $q->execute([$start,$end]);$delivered=$q->fetch(PDO::FETCH_ASSOC);
        $unposted=(int)$this->db->query("SELECT COUNT(*) FROM aelvo_order_items i JOIN aelvo_orders o ON o.id=i.order_id LEFT JOIN aelvo_order_item_cost_postings c ON c.order_item_id=i.id WHERE o.status='Delivered' AND c.order_item_id IS NULL")->fetchColumn();
        $inventory=$this->db->query('SELECT COALESCE(SUM(CASE WHEN remaining_cost_minor IS NOT NULL THEN remaining_cost_minor ELSE 0 END),0) AS known_value_minor, COALESCE(SUM(CASE WHEN remaining_cost_minor IS NULL THEN remaining_qty ELSE 0 END),0) AS unknown_units FROM aelvo_inventory_cost_layers WHERE remaining_qty>0')->fetch(PDO::FETCH_ASSOC);
        $mismatch=(int)$this->db->query('SELECT COUNT(*) FROM aelvo_variants v LEFT JOIN (SELECT variant_id,SUM(remaining_qty) AS units FROM aelvo_inventory_cost_layers GROUP BY variant_id) l ON l.variant_id=v.id WHERE v.stock<>COALESCE(l.units,0)')->fetchColumn();
        $q=$this->db->prepare('SELECT COALESCE(SUM(known_cost_minor),0) AS known_loss_minor, COALESCE(SUM(known_qty),0) AS known_units, COALESCE(SUM(unknown_qty),0) AS unknown_units FROM aelvo_inventory_cost_disposals WHERE created_at>=? AND created_at<?');
        $q->execute([$start,$end]);$disposals=$q->fetch(PDO::FETCH_ASSOC);
        return [
            'known_cogs_minor'=>(int)$delivered['known_cost_minor'],
            'known_sold_units'=>(int)$delivered['known_qty'],
            'unknown_sold_units'=>(int)$delivered['unknown_qty'],
            'posted_lines'=>(int)$delivered['posted_lines'],
            'historic_delivered_lines_without_cost'=>$unposted,
            'known_remaining_inventory_minor'=>(int)$inventory['known_value_minor'],
            'unknown_inventory_units'=>(int)$inventory['unknown_units'],
            'stock_layer_mismatch_variants'=>$mismatch,
            'known_disposal_cost_minor'=>(int)$disposals['known_loss_minor'],
            'known_disposed_units'=>(int)$disposals['known_units'],
            'unknown_disposed_units'=>(int)$disposals['unknown_units'],
        ];
    }

    public function orderRows(string $from,string $to): array {
        $this->allow('reports','view');[$start,$end]=self::dates($from,$to);
        $q=$this->db->prepare('SELECT reference,status,payment_status,created_at,subtotal_minor,delivery_minor,tax_minor,total_minor FROM aelvo_orders WHERE created_at>=? AND created_at<? ORDER BY id DESC LIMIT 1000');
        $q->execute([$start,$end]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Prevent spreadsheet formula evaluation in exported text cells. */
    public static function csvCell(mixed $value): string {
        $text=(string)$value;
        if(preg_match('/^[\x00-\x20]*[=+@\-]/u',$text))$text="'".$text;
        return $text;
    }
}
