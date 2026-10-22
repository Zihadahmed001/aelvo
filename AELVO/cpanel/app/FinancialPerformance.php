<?php
declare(strict_types=1);
namespace Aelvo;

use PDO;
use RuntimeException;

/**
 * Dated, read-only financial coverage bridge. NOT a statutory P&L: the system
 * does not yet capture every tax, fulfilment invoice or accrual. No supplier
 * payment/deposit/transfer is treated as sales or COGS.
 */
final class FinancialPerformance extends Service {
    /** Integer-minor-unit arithmetic; negative is allowed for a loss. */
    public static function recordedBridge(array $v): int {
        $fields=['delivered_minor','paid_refunds_minor','known_cogs_minor','known_return_reversal_minor',
            'known_disposals_minor','operating_expenses_minor','gateway_fees_minor','courier_fees_minor','tax_charged_minor','tax_refunded_minor'];
        foreach($fields as $field) {
            if(!isset($v[$field]) || !is_int($v[$field]) || $v[$field]<0)
                throw new \InvalidArgumentException('Incomplete financial bridge component: '.$field);
        }
        return $v['delivered_minor']-$v['tax_charged_minor']-$v['paid_refunds_minor']+$v['tax_refunded_minor']-$v['known_cogs_minor']
            +$v['known_return_reversal_minor']-$v['known_disposals_minor']
            -$v['operating_expenses_minor']-$v['gateway_fees_minor']-$v['courier_fees_minor'];
    }
    public static function coverageFlags(array $v): array {
        $checks=[
            'unknown_cogs_units'=>'Some delivered units have unknown purchase cost.',
            'delivered_lines_missing_postings'=>'Delivered order items have no cost posting.',
            'unknown_restock_units'=>'Some sellable returns were restocked without verifiable cost.',
            'return_layers_missing'=>'Some posted sellable returns have no cost-layer reversal.',
            'unknown_disposal_units'=>'Some damaged/write-off units have unknown cost.',
            'orders_missing_delivery_events'=>'Some delivered orders lack a dated delivery event.',
            'invalid_discount_orders'=>'Some delivered orders have an inconsistent subtotal, delivery fee, tax and final total.',
            'taxed_orders_missing_snapshots'=>'Taxed orders have no durable order-time tax snapshot.',
            'taxed_refunds_missing_allocations'=>'Paid refunds on taxed orders have no explicit tax allocation.',
        ];
        $flags=[];
        foreach($checks as $key=>$reason){
            if(!array_key_exists($key,$v)||!is_int($v[$key])||$v[$key]<0)
                throw new \InvalidArgumentException('Incomplete financial coverage count: '.$key);
            if($v[$key]>0)$flags[]=$reason;
        }
        // Even if all measured costs exist, this codebase does not yet capture
        // all actual delivery charges, tax liabilities or accrued expenses.
        $flags[]='Tax charged and explicitly refunded are separated in the recorded bridge; statutory remittance and refund treatment remain unverified.';
        $flags[]='Fulfilment costs, returns and accrued expenses have not passed full accounting reconciliation.';
        $flags[]='Gateway/courier fees are counted when recorded and may need manual classification against other expenses.';
        return $flags;
    }
    private function sum(string $sql,array $range): int {
        $q=$this->db->prepare($sql);$q->execute($range);
        return (int)$q->fetchColumn();
    }
    /** All money in BDT minor units. Dates refer to individual event times in UTC. */
    public function period(string $from,string $to): array {
        $this->allow('reports','view');$this->allow('finance','view');
        [$start,$end]=Reports::dates($from,$to);$range=[$start,$end];
        $v=[];
        // Sale recognized on actual delivery event, not the order creation date
        // or gateway settlement date. One first delivery event per order.
        $v['delivered_minor']=$this->sum("SELECT COALESCE(SUM(o.total_minor),0) FROM aelvo_orders o
            JOIN (SELECT order_id,MIN(created_at) AS delivered_at FROM aelvo_order_events
                  WHERE status='Delivered' GROUP BY order_id) d ON d.order_id=o.id
            WHERE d.delivered_at>=? AND d.delivered_at<?",$range);
        $deliveryCohort=" FROM aelvo_orders o JOIN (
            SELECT order_id,MIN(created_at) AS delivered_at FROM aelvo_order_events
            WHERE status='Delivered' GROUP BY order_id
        ) d ON d.order_id=o.id WHERE d.delivered_at>=? AND d.delivered_at<?";
        $v['delivered_merchandise_minor']=$this->sum('SELECT COALESCE(SUM(o.subtotal_minor),0)'.$deliveryCohort,$range);
        $v['delivery_charged_minor']=$this->sum('SELECT COALESCE(SUM(o.delivery_minor),0)'.$deliveryCohort,$range);
        $v['tax_charged_minor']=$this->sum('SELECT COALESCE(SUM(o.tax_minor),0)'.$deliveryCohort,$range);
        $v['discounts_applied_minor']=$this->sum('SELECT COALESCE(SUM(GREATEST(0,o.subtotal_minor+o.delivery_minor+o.tax_minor-o.total_minor)),0)'.$deliveryCohort,$range);
        $v['invalid_discount_orders']=$this->sum('SELECT COUNT(*)'.$deliveryCohort.' AND o.total_minor>o.subtotal_minor+o.delivery_minor+o.tax_minor',$range);
        $v['taxed_orders_missing_snapshots']=$this->sum('SELECT COUNT(*) FROM aelvo_orders o LEFT JOIN aelvo_order_tax_snapshots t ON t.order_id=o.id WHERE o.tax_minor>0 AND t.order_id IS NULL AND o.created_at>=? AND o.created_at<?',$range);
        $v['paid_refunds_minor']=$this->sum('SELECT COALESCE(SUM(amount_minor),0) FROM aelvo_refunds WHERE created_at>=? AND created_at<?',$range);
        $v['tax_refunded_minor']=$this->sum('SELECT COALESCE(SUM(a.tax_minor),0) FROM aelvo_refunds f JOIN aelvo_refund_requests r ON r.refund_id=f.id JOIN aelvo_refund_tax_allocations a ON a.refund_request_id=r.id WHERE f.created_at>=? AND f.created_at<?',$range);
        $v['taxed_refunds_missing_allocations']=$this->sum('SELECT COUNT(*) FROM aelvo_refunds f JOIN aelvo_orders o ON o.id=f.order_id LEFT JOIN aelvo_refund_requests r ON r.refund_id=f.id LEFT JOIN aelvo_refund_tax_allocations a ON a.refund_request_id=r.id WHERE o.tax_minor>0 AND a.refund_request_id IS NULL AND f.created_at>=? AND f.created_at<?',$range);
        $v['known_cogs_minor']=$this->sum('SELECT COALESCE(SUM(known_cost_minor),0) FROM aelvo_order_item_cost_postings WHERE created_at>=? AND created_at<?',$range);
        // Returning sellable stock reactivates recorded historical cost, not its
        // current retail value. NULL remains an unknown-cost return.
        $v['known_return_reversal_minor']=$this->sum("SELECT COALESCE(SUM(received_cost_minor),0) FROM aelvo_inventory_cost_layers
            WHERE origin_type='sellable-return' AND created_at>=? AND created_at<?",$range);
        $v['known_disposals_minor']=$this->sum('SELECT COALESCE(SUM(known_cost_minor),0) FROM aelvo_inventory_cost_disposals WHERE created_at>=? AND created_at<?',$range);
        $v['operating_expenses_minor']=$this->sum("SELECT COALESCE(SUM(-l.delta_minor),0) FROM aelvo_ledger l
            JOIN aelvo_finance_events e ON e.id=l.event_id
            WHERE e.kind='expense' AND e.created_at>=? AND e.created_at<?",$range);
        $v['gateway_fees_minor']=$this->sum('SELECT COALESCE(SUM(fee_minor),0) FROM aelvo_gateway_settlements WHERE created_at>=? AND created_at<?',$range)
            +$this->sum('SELECT COALESCE(SUM(fee_minor),0) FROM aelvo_gateway_payout_batches WHERE created_at>=? AND created_at<?',$range);
        $v['courier_fees_minor']=$this->sum('SELECT COALESCE(SUM(fee_minor),0) FROM aelvo_courier_remittances WHERE recorded_at>=? AND recorded_at<?',$range);
        $v['unknown_cogs_units']=$this->sum('SELECT COALESCE(SUM(unknown_qty),0) FROM aelvo_order_item_cost_postings WHERE created_at>=? AND created_at<?',$range);
        $v['unknown_restock_units']=$this->sum("SELECT COALESCE(SUM(received_qty),0) FROM aelvo_inventory_cost_layers
            WHERE origin_type='sellable-return' AND received_cost_minor IS NULL AND created_at>=? AND created_at<?",$range);
        $v['unknown_disposal_units']=$this->sum('SELECT COALESCE(SUM(unknown_qty),0) FROM aelvo_inventory_cost_disposals WHERE created_at>=? AND created_at<?',$range);
        $v['return_layers_missing']=$this->sum("SELECT COUNT(*) FROM aelvo_return_dispositions d
            JOIN aelvo_returns r ON r.id=d.return_id
            LEFT JOIN aelvo_inventory_cost_layers l ON l.origin_type='sellable-return' AND l.origin_id=CAST(d.return_id AS CHAR)
            WHERE d.posted_at>=? AND d.posted_at<? AND d.sellable_qty>0 AND l.id IS NULL",$range);
        $v['delivered_lines_missing_postings']=$this->sum("SELECT COUNT(*) FROM (
                SELECT order_id,MIN(created_at) AS delivered_at FROM aelvo_order_events
                WHERE status='Delivered' GROUP BY order_id
            ) d JOIN aelvo_order_items i ON i.order_id=d.order_id
            LEFT JOIN aelvo_order_item_cost_postings c ON c.order_item_id=i.id
            WHERE d.delivered_at>=? AND d.delivered_at<? AND c.order_item_id IS NULL",$range);
        $v['orders_missing_delivery_events']=(int)$this->db->query("SELECT COUNT(*) FROM aelvo_orders o
            LEFT JOIN (SELECT DISTINCT order_id FROM aelvo_order_events WHERE status='Delivered') e ON e.order_id=o.id
            WHERE o.status='Delivered' AND e.order_id IS NULL")->fetchColumn();
        $v['recorded_bridge_minor']=self::recordedBridge($v);
        $v['warnings']=self::coverageFlags($v);
        return $v;
    }

    /** Small bounded drill-down, filtered by FIRST delivery date, not placement date. */
    public function deliveredOrderReview(string $from,string $to): array {
        $this->allow('reports','view');$this->allow('finance','view');
        [$start,$end]=Reports::dates($from,$to);
        $sql="SELECT o.reference,d.delivered_at,o.total_minor,o.subtotal_minor,o.delivery_minor,o.tax_minor,
                COALESCE(c.known_cost_minor,0) AS known_cost_minor,
                COALESCE(c.unknown_qty,0) AS unknown_qty,
                COALESCE(c.unposted_lines,0) AS unposted_lines,
                COALESCE(r.paid_minor,0) AS lifetime_paid_refunds_minor,
                COALESCE(rt.paid_tax_minor,0) AS lifetime_refunded_tax_minor,
                COALESCE(t.reversed_minor,0) AS lifetime_return_reversal_minor,
                COALESCE(t.unknown_units,0) AS lifetime_unpriced_return_units
            FROM aelvo_orders o
            JOIN (SELECT order_id,MIN(created_at) AS delivered_at
                  FROM aelvo_order_events WHERE status='Delivered' GROUP BY order_id) d ON d.order_id=o.id
            LEFT JOIN (
                SELECT i.order_id,
                    COALESCE(SUM(p.known_cost_minor),0) known_cost_minor,
                    COALESCE(SUM(p.unknown_qty),0) unknown_qty,
                    SUM(CASE WHEN p.order_item_id IS NULL THEN 1 ELSE 0 END) unposted_lines
                FROM aelvo_order_items i
                LEFT JOIN aelvo_order_item_cost_postings p ON p.order_item_id=i.id
                GROUP BY i.order_id
            ) c ON c.order_id=o.id
            LEFT JOIN (SELECT order_id,SUM(amount_minor) AS paid_minor FROM aelvo_refunds GROUP BY order_id) r ON r.order_id=o.id
            LEFT JOIN (SELECT rq.order_id,SUM(a.tax_minor) AS paid_tax_minor FROM aelvo_refund_requests rq
                JOIN aelvo_refund_tax_allocations a ON a.refund_request_id=rq.id WHERE rq.status='Completed' GROUP BY rq.order_id) rt ON rt.order_id=o.id
            LEFT JOIN (
                SELECT i.order_id,
                    COALESCE(SUM(l.received_cost_minor),0) reversed_minor,
                    COALESCE(SUM(CASE WHEN l.id IS NOT NULL AND l.received_cost_minor IS NULL THEN l.received_qty ELSE 0 END),0) unknown_units
                FROM aelvo_returns rt
                JOIN aelvo_order_items i ON i.id=rt.order_item_id
                JOIN aelvo_return_dispositions rd ON rd.return_id=rt.id AND rd.posted_at IS NOT NULL
                LEFT JOIN aelvo_inventory_cost_layers l ON l.origin_type='sellable-return' AND l.origin_id=CAST(rt.id AS CHAR)
                GROUP BY i.order_id
            ) t ON t.order_id=o.id
            WHERE d.delivered_at>=? AND d.delivered_at<?
            ORDER BY d.delivered_at DESC,o.id DESC LIMIT 100";
        $q=$this->db->prepare($sql);$q->execute([$start,$end]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
