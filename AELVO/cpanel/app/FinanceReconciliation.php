<?php
declare(strict_types=1);
namespace Aelvo;

use PDO;

/** Read-only financial integrity diagnostics; does not post or alter account balances. */
final class FinanceReconciliation extends Service {
    public static function variance(int $recorded,int $posted): int {
        return $recorded-$posted;
    }
    public static function due(int $invoiced,int $paid): array {
        return ['outstanding_minor'=>max(0,$invoiced-$paid),'overpaid_minor'=>max(0,$paid-$invoiced)];
    }
    private function rows(string $sql): array {
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    private function total(string $sql): int {
        return (int)$this->db->query($sql)->fetchColumn();
    }
    public function inspect(): array {
        $this->allow('reports','view');
        $this->allow('finance','view');
        // Balances must equal the signed, append-only account ledger from a zero opening balance.
        // A balance correction is its own ledger event and is never silently repaired here.
        $accounts=$this->rows('SELECT a.id,a.name,a.kind,a.balance_minor,COALESCE(l.posted_minor,0) AS posted_minor,
            COALESCE(last_row.balance_after_minor,0) AS latest_snapshot_minor
            FROM aelvo_accounts a LEFT JOIN (
                SELECT account_id,SUM(delta_minor) AS posted_minor FROM aelvo_ledger GROUP BY account_id
            ) l ON l.account_id=a.id
            LEFT JOIN (
                SELECT entry.account_id,entry.balance_after_minor
                FROM aelvo_ledger entry JOIN (
                    SELECT account_id,MAX(id) AS last_id FROM aelvo_ledger GROUP BY account_id
                ) last_entry ON last_entry.last_id=entry.id
            ) last_row ON last_row.account_id=a.id
            WHERE a.balance_minor<>COALESCE(l.posted_minor,0)
               OR a.balance_minor<>COALESCE(last_row.balance_after_minor,0)
               OR COALESCE(l.posted_minor,0)<>COALESCE(last_row.balance_after_minor,0)
            ORDER BY a.id LIMIT 101');
        $truncatedAccounts=count($accounts)>100;
        $accounts=array_slice($accounts,0,100);
        foreach($accounts as &$account)$account['variance_minor']=self::variance((int)$account['balance_minor'],(int)$account['posted_minor']);
        unset($account);
        $transfers=$this->rows("SELECT e.id,e.created_at,COUNT(l.id) AS entries,COUNT(DISTINCT l.account_id) AS account_count,COALESCE(SUM(l.delta_minor),0) AS net_minor
            FROM aelvo_finance_events e LEFT JOIN aelvo_ledger l ON l.event_id=e.id
            WHERE e.kind='transfer' GROUP BY e.id,e.created_at
            HAVING COUNT(l.id)<>2 OR COUNT(DISTINCT l.account_id)<>2 OR COALESCE(SUM(l.delta_minor),0)<>0
            ORDER BY e.created_at DESC,e.id DESC LIMIT 101");
        $truncatedTransfers=count($transfers)>100;$transfers=array_slice($transfers,0,100);
        // A purchase draft is not a received supplier liability. Keep these separate.
        // Every recorded cash movement must match exactly one ledger entry of the
        // correct sign and type. External gateway charges/COD collections are
        // intentionally excluded because they are NOT internal cash postings.
        $postedEvents=$this->rows("SELECT e.id,e.kind,e.created_at,
            COALESCE(x.kind,'Missing financial detail') AS detail_kind,
            COALESCE(x.expected_minor,0) AS expected_minor,
            COALESCE(l.posted_minor,0) AS posted_minor,COALESCE(l.entries,0) AS entries
            FROM aelvo_finance_events e LEFT JOIN (
                SELECT finance_event_id AS event_id,'supplier-payment' AS kind,-amount_minor AS expected_minor FROM aelvo_supplier_payments
                UNION ALL SELECT event_id,'order-receipt',amount_minor FROM aelvo_order_receipts
                UNION ALL SELECT event_id,'order-refund',-amount_minor FROM aelvo_refunds
                UNION ALL SELECT finance_event_id,'courier-remittance',remitted_minor FROM aelvo_courier_remittances
                UNION ALL SELECT event_id,'gateway-settlement',received_minor FROM aelvo_gateway_settlements
                UNION ALL SELECT event_id,'gateway-settlement',credit_minor FROM aelvo_gateway_payout_batches
            ) x ON x.event_id=e.id
            LEFT JOIN (SELECT event_id,COUNT(*) AS entries,SUM(delta_minor) AS posted_minor
                FROM aelvo_ledger GROUP BY event_id) l ON l.event_id=e.id
            WHERE e.kind IN ('supplier-payment','order-receipt','order-refund','courier-remittance','gateway-settlement')
              AND (x.event_id IS NULL OR x.kind<>e.kind OR COALESCE(l.entries,0)<>1
                   OR COALESCE(l.posted_minor,0)<>COALESCE(x.expected_minor,0))
            ORDER BY e.created_at DESC,e.id DESC LIMIT 101");
        $truncatedPostedEvents=count($postedEvents)>100;$postedEvents=array_slice($postedEvents,0,100);
        $supplierDue=$this->total("SELECT COALESCE(SUM(GREATEST(0,p.total_minor-COALESCE(sp.paid_minor,0))),0)
            FROM aelvo_purchases p LEFT JOIN (
                SELECT purchase_id,SUM(amount_minor) AS paid_minor FROM aelvo_supplier_payments GROUP BY purchase_id
            ) sp ON sp.purchase_id=p.id WHERE p.status='Received'");
        $supplierItems=$this->rows("SELECT p.id,p.reference,p.supplier,p.status,p.total_minor,
            COALESCE(sp.paid_minor,0) AS paid_minor
            FROM aelvo_purchases p LEFT JOIN (
                SELECT purchase_id,SUM(amount_minor) AS paid_minor FROM aelvo_supplier_payments GROUP BY purchase_id
            ) sp ON sp.purchase_id=p.id
            WHERE p.status='Received' AND COALESCE(sp.paid_minor,0)<>p.total_minor
            ORDER BY p.id DESC LIMIT 101");
        $truncatedSuppliers=count($supplierItems)>100;$supplierItems=array_slice($supplierItems,0,100);
        foreach($supplierItems as &$item)$item+=self::due((int)$item['total_minor'],(int)$item['paid_minor']);
        unset($item);
        $overpaidSuppliers=$this->total("SELECT COUNT(*) FROM aelvo_purchases p JOIN (
            SELECT purchase_id,SUM(amount_minor) AS paid_minor FROM aelvo_supplier_payments GROUP BY purchase_id
        ) sp ON sp.purchase_id=p.id WHERE sp.paid_minor>p.total_minor");
        // Explicit coverage warning: historic one-line purchases may have no landed allocation.
        $uncostedLines=$this->total("SELECT COUNT(*) FROM aelvo_purchase_items i
            JOIN aelvo_purchases p ON p.id=i.purchase_id
            LEFT JOIN aelvo_purchase_landed_items li ON li.purchase_item_id=i.id
            WHERE p.status='Received' AND li.purchase_item_id IS NULL");
        $orderIssues=$this->rows("SELECT o.id,o.reference,o.status,o.total_minor,
            COALESCE(m.manual_minor,0)+COALESCE(g.gateway_minor,0)+COALESCE(c.courier_minor,0) AS collected_minor,
            COALESCE(r.refunded_minor,0) AS refunded_minor
            FROM aelvo_orders o
            LEFT JOIN (SELECT order_id,SUM(amount_minor) manual_minor FROM aelvo_order_receipts GROUP BY order_id) m ON m.order_id=o.id
            LEFT JOIN (SELECT order_id,SUM(amount_minor) gateway_minor FROM aelvo_gateway_receipts GROUP BY order_id) g ON g.order_id=o.id
            LEFT JOIN (SELECT s.order_id,SUM(cc.collected_minor) courier_minor FROM aelvo_courier_collections cc
                JOIN aelvo_courier_shipments s ON s.id=cc.shipment_id GROUP BY s.order_id) c ON c.order_id=o.id
            LEFT JOIN (SELECT order_id,SUM(amount_minor) refunded_minor FROM aelvo_refunds GROUP BY order_id) r ON r.order_id=o.id
            WHERE COALESCE(m.manual_minor,0)+COALESCE(g.gateway_minor,0)+COALESCE(c.courier_minor,0)>o.total_minor
               OR COALESCE(r.refunded_minor,0)>
                   COALESCE(m.manual_minor,0)+COALESCE(g.gateway_minor,0)+COALESCE(c.courier_minor,0)
            ORDER BY o.id DESC LIMIT 101");
        $truncatedOrders=count($orderIssues)>100;$orderIssues=array_slice($orderIssues,0,100);
        $courierIssues=$this->rows("SELECT s.id,s.invoice,s.cod_minor,COALESCE(c.collected_minor,0) AS collected_minor,
            COALESCE(r.remitted_minor,0) AS remitted_minor,COALESCE(r.fee_minor,0) AS fee_minor
            FROM aelvo_courier_shipments s
            LEFT JOIN aelvo_courier_collections c ON c.shipment_id=s.id
            LEFT JOIN (SELECT shipment_id,SUM(remitted_minor) remitted_minor,SUM(fee_minor) fee_minor
                FROM aelvo_courier_remittances GROUP BY shipment_id) r ON r.shipment_id=s.id
            WHERE COALESCE(c.collected_minor,0)>s.cod_minor
               OR COALESCE(r.remitted_minor,0)+COALESCE(r.fee_minor,0)>COALESCE(c.collected_minor,0)
            ORDER BY s.id DESC LIMIT 101");
        $truncatedCourier=count($courierIssues)>100;$courierIssues=array_slice($courierIssues,0,100);
        $gatewayIssues=$this->rows("SELECT a.id,a.tran_id,a.state,a.expected_minor,
            COALESCE(r.amount_minor,0) AS validated_minor,
            COALESCE(s.received_minor,0) AS settled_minor,COALESCE(s.fee_minor,0) AS fee_minor
            FROM aelvo_gateway_attempts a
            LEFT JOIN aelvo_gateway_receipts r ON r.attempt_id=a.id
            LEFT JOIN (SELECT attempt_id,SUM(received_minor) received_minor,SUM(fee_minor) fee_minor FROM (
                SELECT attempt_id,received_minor,fee_minor FROM aelvo_gateway_settlements
                UNION ALL SELECT attempt_id,credited_minor,fee_minor FROM aelvo_gateway_payout_allocations
            ) all_settlements GROUP BY attempt_id) s ON s.attempt_id=a.id
            WHERE (a.state='Verified' AND r.attempt_id IS NULL)
               OR (r.attempt_id IS NOT NULL AND (a.state<>'Verified' OR r.amount_minor<>a.expected_minor))
               OR COALESCE(s.received_minor,0)+COALESCE(s.fee_minor,0)>COALESCE(r.amount_minor,0)
            ORDER BY a.created_at DESC,a.id DESC LIMIT 101");
        $truncatedGateway=count($gatewayIssues)>100;$gatewayIssues=array_slice($gatewayIssues,0,100);
        $unknownCourier=$this->total("SELECT COUNT(*) FROM aelvo_courier_shipments WHERE booking_state IN ('Prepared','Unknown')");
        $unknownGateway=$this->total("SELECT COUNT(*) FROM aelvo_gateway_attempts WHERE state IN ('Initiating','Uncertain','Review','RiskHold')");
        return [
            'account_variances'=>$accounts,'unbalanced_transfers'=>$transfers,'cash_posting_issues'=>$postedEvents,'supplier_due_minor'=>$supplierDue,
            'supplier_dues'=>$supplierItems,'supplier_overpaid_count'=>$overpaidSuppliers,'uncosted_purchase_lines'=>$uncostedLines,
            'order_issues'=>$orderIssues,'courier_issues'=>$courierIssues,'gateway_issues'=>$gatewayIssues,
            'courier_pending_count'=>$unknownCourier,'gateway_pending_count'=>$unknownGateway,
            'truncated'=>[
                'accounts'=>$truncatedAccounts,'transfers'=>$truncatedTransfers,'cash_events'=>$truncatedPostedEvents,'suppliers'=>$truncatedSuppliers,
                'orders'=>$truncatedOrders,'courier'=>$truncatedCourier,'gateway'=>$truncatedGateway
            ],
        ];
    }
}
