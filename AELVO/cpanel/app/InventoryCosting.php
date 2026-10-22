<?php
declare(strict_types=1);
namespace Aelvo;

use PDO;
use RuntimeException;
use InvalidArgumentException;

/**
 * FIFO valuation journal for *future* physical receipts and deliveries.
 * Must be called inside the caller's transaction, AFTER locking the variant row.
 * Unknown historical stock gets an explicitly unpriced layer, not a guessed cost.
 * This is an accounting aid, not a complete profit/loss or tax system.
 */
final class InventoryCosting {
    public function __construct(private PDO $db) {}

    /** Exact integer allocation with full-lot conservation (no floats). */
    public static function allocateMinor(int $remainingMinor,int $remainingQty,int $takenQty): int {
        if($remainingMinor<0||$remainingQty<1||$takenQty<1||$takenQty>$remainingQty)
            throw new InvalidArgumentException('Invalid cost allocation.');
        // Avoid overflow from multiplying very large monetary figures by quantity.
        return intdiv($remainingMinor,$remainingQty)*$takenQty
            + intdiv(($remainingMinor%$remainingQty)*$takenQty,$remainingQty);
    }

    /**
     * Align with current physical on-hand stock without rewriting or repricing
     * existing cost layers. A deficit indicates prior uncosted stock or return;
     * an excess indicates an unvalued writeoff/adjustment requiring review.
     */
    public function ensureCoverage(int $variantId,int $onHand): void {
        if($onHand<0)throw new RuntimeException('Negative physical stock.');
        $q=$this->db->prepare('SELECT id,remaining_qty FROM aelvo_inventory_cost_layers WHERE variant_id=? ORDER BY id FOR UPDATE');
        $q->execute([$variantId]);$covered=0;
        foreach($q as $layer)$covered+=(int)$layer['remaining_qty'];
        if($covered>$onHand)throw new RuntimeException('Inventory valuation mismatch: cost layers exceed on-hand stock. Reconcile stock movement before delivery/receipt.');
        if($covered===$onHand)return;
        $gap=$onHand-$covered;
        $q=$this->db->prepare("INSERT INTO aelvo_inventory_cost_layers(variant_id,origin_type,origin_id,received_qty,received_cost_minor,remaining_qty,remaining_cost_minor) VALUES (?,'unpriced-opening',?,?,NULL,?,NULL)");
        $q->execute([$variantId,bin2hex(random_bytes(16)),$gap,$gap]);
    }

    /** Create exactly one FIFO layer per physically received purchase item. */
    public function receivePurchaseItem(int $variantId,int $itemId,int $qty,?int $landedCost): void {
        if($qty<1||($landedCost!==null&&$landedCost<0))throw new InvalidArgumentException('Invalid receipt valuation.');
        $q=$this->db->prepare("INSERT INTO aelvo_inventory_cost_layers(variant_id,origin_type,origin_id,received_qty,received_cost_minor,remaining_qty,remaining_cost_minor) VALUES (?,'purchase-item',?,?,?,?,?)");
        $q->execute([$variantId,(string)$itemId,$qty,$landedCost,$qty,$landedCost]);
    }

    /** Consume FIFO units; an unknown-cost lot remains visibly unknown. */
    public function deliverItem(int $itemId,int $variantId,int $qty): void {
        if($qty<1)throw new InvalidArgumentException('Invalid delivered quantity.');
        $q=$this->db->prepare('SELECT order_item_id FROM aelvo_order_item_cost_postings WHERE order_item_id=?');
        $q->execute([$itemId]);
        if($q->fetchColumn()!==false)throw new RuntimeException('Delivery cost already posted: review duplicate stock transition.');
        $q=$this->db->prepare('SELECT id,remaining_qty,remaining_cost_minor FROM aelvo_inventory_cost_layers WHERE variant_id=? AND remaining_qty>0 ORDER BY id FOR UPDATE');
        $q->execute([$variantId]);$layers=$q->fetchAll(PDO::FETCH_ASSOC);$left=$qty;$known=0;$unknown=0;$cost=0;
        $apply=$this->db->prepare('UPDATE aelvo_inventory_cost_layers SET remaining_qty=?,remaining_cost_minor=? WHERE id=?');
        $ins=$this->db->prepare('INSERT INTO aelvo_order_item_cost_allocations(order_item_id,layer_id,qty,cost_minor) VALUES (?,?,?,?)');
        foreach($layers as $layer){
            if($left===0)break;
            $available=(int)$layer['remaining_qty'];$take=min($left,$available);
            $unitCost=$layer['remaining_cost_minor']===null?null:
                self::allocateMinor((int)$layer['remaining_cost_minor'],$available,$take);
            $afterQty=$available-$take;
            $afterCost=$unitCost===null?null:(int)$layer['remaining_cost_minor']-$unitCost;
            $apply->execute([$afterQty,$afterCost,$layer['id']]);
            $ins->execute([$itemId,$layer['id'],$take,$unitCost]);
            if($unitCost===null)$unknown+=$take;
            else {$known+=$take;$cost+=$unitCost;}
            $left-=$take;
        }
        if($left!==0)throw new RuntimeException('Insufficient FIFO cost units; inventory requires reconciliation.');
        $q=$this->db->prepare('INSERT INTO aelvo_order_item_cost_postings(order_item_id,delivered_qty,known_qty,unknown_qty,known_cost_minor) VALUES (?,?,?,?,?)');
        $q->execute([$itemId,$qty,$known,$unknown,$cost]);
    }

    /** Manual positive adjustments have no purchase documentation and stay unpriced. */
    public function unpricedIncrease(int $variantId,int $qty,string $sourceId): void {
        if($qty<1||!preg_match('/^[a-f0-9]{24,64}$/D',$sourceId))throw new InvalidArgumentException('Invalid adjustment reference.');
        $q=$this->db->prepare("INSERT INTO aelvo_inventory_cost_layers(variant_id,origin_type,origin_id,received_qty,received_cost_minor,remaining_qty,remaining_cost_minor) VALUES (?,'manual-increase',?,?,NULL,?,NULL)");
        $q->execute([$variantId,$sourceId,$qty,$qty]);
    }

    /** Audited disposal, not a sale: capture FIFO loss independently of COGS. */
    public function dispose(int $variantId,int $qty,string $sourceType,string $sourceId): void {
        if($qty<1||!in_array($sourceType,['manual-reduction','quarantine-writeoff'],true)||
           !preg_match('/^[a-f0-9]{24,64}$/D',$sourceId))throw new InvalidArgumentException('Invalid stock write-off reference.');
        $q=$this->db->prepare('SELECT id FROM aelvo_inventory_cost_disposals WHERE source_type=? AND source_id=?');
        $q->execute([$sourceType,$sourceId]);
        if($q->fetchColumn()!==false)throw new RuntimeException('Stock disposal already valued; refuse duplicate mutation.');
        $q=$this->db->prepare('SELECT id,remaining_qty,remaining_cost_minor FROM aelvo_inventory_cost_layers WHERE variant_id=? AND remaining_qty>0 ORDER BY id FOR UPDATE');
        $q->execute([$variantId]);$layers=$q->fetchAll(PDO::FETCH_ASSOC);
        $remaining=$qty;$known=0;$unknown=0;$totalCost=0;$parts=[];
        $apply=$this->db->prepare('UPDATE aelvo_inventory_cost_layers SET remaining_qty=?,remaining_cost_minor=? WHERE id=?');
        foreach($layers as $layer){
            if($remaining===0)break;
            $available=(int)$layer['remaining_qty'];$take=min($remaining,$available);
            $cost=$layer['remaining_cost_minor']===null?null:self::allocateMinor((int)$layer['remaining_cost_minor'],$available,$take);
            $apply->execute([$available-$take,$cost===null?null:(int)$layer['remaining_cost_minor']-$cost,$layer['id']]);
            $parts[]=[(int)$layer['id'],$take,$cost];
            if($cost===null)$unknown+=$take;else{$known+=$take;$totalCost+=$cost;}
            $remaining-=$take;
        }
        if($remaining>0)throw new RuntimeException('Insufficient inventory cost layers for disposal.');
        $q=$this->db->prepare('INSERT INTO aelvo_inventory_cost_disposals(variant_id,source_type,source_id,qty,known_qty,unknown_qty,known_cost_minor) VALUES (?,?,?,?,?,?,?)');
        $q->execute([$variantId,$sourceType,$sourceId,$qty,$known,$unknown,$totalCost]);
        $disposalId=(int)$this->db->lastInsertId();
        $q=$this->db->prepare('INSERT INTO aelvo_inventory_cost_disposal_lines(disposal_id,layer_id,qty,cost_minor) VALUES (?,?,?,?)');
        foreach($parts as [$layerId,$taken,$cost])$q->execute([$disposalId,$layerId,$taken,$cost]);
    }

    /**
     * Return the recorded cost (not retail price) to a sellable FIFO layer.
     * A sale with any unknown-cost units remains unknown for conservative
     * returns valuation. Damaged units are NOT silently put back into stock.
     */
    public function restockReturn(int $returnId,int $orderItemId,int $variantId,int $sellableQty): void {
        if($sellableQty<1)throw new InvalidArgumentException('Return has no sellable units.');
        $q=$this->db->prepare('SELECT delivered_qty,known_qty,unknown_qty,known_cost_minor FROM aelvo_order_item_cost_postings WHERE order_item_id=?');
        $q->execute([$orderItemId]);$sale=$q->fetch(PDO::FETCH_ASSOC);
        $cost=null;
        if($sale && (int)$sale['unknown_qty']===0 && (int)$sale['known_qty']===(int)$sale['delivered_qty']){
            $q=$this->db->prepare('SELECT COALESCE(SUM(d.sellable_qty),0) FROM aelvo_returns r JOIN aelvo_return_dispositions d ON d.return_id=r.id WHERE r.order_item_id=? AND d.posted_at IS NOT NULL');
            $q->execute([$orderItemId]);$prior=(int)$q->fetchColumn();
            $delivered=(int)$sale['delivered_qty'];
            if($prior+$sellableQty>$delivered)throw new RuntimeException('Restock quantity exceeds delivered sale.');
            $value=(int)$sale['known_cost_minor'];
            $totalAfter=self::allocateMinor($value,$delivered,$prior+$sellableQty);
            $totalBefore=$prior===0?0:self::allocateMinor($value,$delivered,$prior);
            $cost=$totalAfter-$totalBefore;
        }
        $q=$this->db->prepare("INSERT INTO aelvo_inventory_cost_layers(variant_id,origin_type,origin_id,received_qty,received_cost_minor,remaining_qty,remaining_cost_minor) VALUES (?,'sellable-return',?,?,?,?,?)");
        $q->execute([$variantId,(string)$returnId,$sellableQty,$cost,$sellableQty,$cost]);
    }
}
