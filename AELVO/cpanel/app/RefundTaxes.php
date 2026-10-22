<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use PDO;

/** Tax allocation is a disclosed piece OF the refund, never an extra payout. */
final class RefundTaxes {
    public static function validate(int $taxIncludedMinor,int $refundMinor,int $orderTaxMinor,int $alreadyAllocatedMinor): void {
        if($orderTaxMinor<0||$alreadyAllocatedMinor<0||$refundMinor<1||$taxIncludedMinor<0
           ||$taxIncludedMinor>$refundMinor||$alreadyAllocatedMinor>$orderTaxMinor
           ||$taxIncludedMinor>$orderTaxMinor-$alreadyAllocatedMinor)
            throw new InvalidArgumentException('Refund tax portion exceeds the refund or unallocated order tax.');
    }
    /** Caller locks the owning order row BEFORE checking the aggregate. */
    public static function committed(PDO $db,int $orderId): int {
        $q=$db->prepare("SELECT COALESCE(SUM(a.tax_minor),0) FROM aelvo_refund_tax_allocations a
            JOIN aelvo_refund_requests r ON r.id=a.refund_request_id
            WHERE r.order_id=? AND r.status IN ('Pending','Approved','Completed')");
        $q->execute([$orderId]);return (int)$q->fetchColumn();
    }
    /** Unknown old tax returns cannot be assumed zero; block later allocation until audited. */
    public static function requireKnownHistory(PDO $db,int $orderId): void {
        $q=$db->prepare("SELECT COUNT(*) FROM aelvo_refund_requests r
            LEFT JOIN aelvo_refund_tax_allocations a ON a.refund_request_id=r.id
            WHERE r.order_id=? AND r.status IN ('Pending','Approved','Completed')
              AND a.refund_request_id IS NULL");
        $q->execute([$orderId]);
        if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('Previous taxable refunds have no allocation. Reconcile historical records before another proposal.');
        $q=$db->prepare('SELECT COUNT(*) FROM aelvo_refunds f LEFT JOIN aelvo_refund_requests r ON r.refund_id=f.id WHERE f.order_id=? AND r.id IS NULL');
        $q->execute([$orderId]);
        if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('Legacy paid refunds require tax reconciliation before another proposal.');
    }
    /** Legacy taxable requests must not be approved or paid without an explicit allocation. */
    public static function allocation(PDO $db,int $requestId,int $orderTaxMinor): int {
        $q=$db->prepare('SELECT tax_minor FROM aelvo_refund_tax_allocations WHERE refund_request_id=?');
        $q->execute([$requestId]);$tax=$q->fetchColumn();
        if($tax===false){
            if($orderTaxMinor>0)throw new InvalidArgumentException('Tax allocation missing on this historical refund. Reconcile and migrate it before approval or payout.');
            return 0;
        }
        return (int)$tax;
    }
}
