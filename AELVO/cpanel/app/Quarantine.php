<?php
declare(strict_types=1);
namespace Aelvo;
use InvalidArgumentException;
use RuntimeException;

/** Stock quarantine never changes saleable stock without a reason and audit. */
final class Quarantine extends Service {
    public function record(array $v): int {
        $this->allow('inventory','edit');
        $variant=Input::integer($v['variant_id']??null);
        $action=Input::text($v,'mode',16);
        if(!in_array($action,['hold','release','writeoff'],true))throw new InvalidArgumentException('Invalid inventory quarantine action.');
        $qty=Input::integer($v['qty']??null,1,1000000);
        $reason=Input::text($v,'explanation',2000);
        $request=Input::text($v,'request_key',64);
        if(!preg_match('/^[a-f0-9]{64}$/D',$request))throw new InvalidArgumentException('Reload the inventory quarantine form.');
        $key=hash('sha256','quarantine:'.$this->actor['id'].':'.$request);
        $hash=hash('sha256',json_encode([$variant,$action,$qty,$reason],JSON_THROW_ON_ERROR));
        return $this->transaction(function() use($variant,$action,$qty,$reason,$key,$hash): int {
            // Every inventory mutation locks the variant FIRST; stock reservations
            // and quarantine cannot race each other into overselling.
            $q=$this->db->prepare('SELECT stock,reserved FROM aelvo_variants WHERE id=? FOR UPDATE');
            $q->execute([$variant]);$row=$q->fetch();
            if(!$row)throw new InvalidArgumentException('Variant not found.');
            $q=$this->db->prepare('SELECT id,request_hash FROM aelvo_quarantine_events WHERE request_key=?');
            $q->execute([$key]);$prior=$q->fetch();
            if($prior){
                if(!hash_equals($prior['request_hash'],$hash))throw new RuntimeException('This quarantine request was changed. Reload the form.',409);
                return (int)$prior['id'];
            }
            $q=$this->db->prepare('INSERT INTO aelvo_variant_quarantine(variant_id,quantity) VALUES (?,0) ON DUPLICATE KEY UPDATE quantity=quantity');
            $q->execute([$variant]);
            $q=$this->db->prepare('SELECT quantity FROM aelvo_variant_quarantine WHERE variant_id=? FOR UPDATE');
            $q->execute([$variant]);$held=(int)$q->fetchColumn();
            $onHand=(int)$row['stock'];$reserved=(int)$row['reserved'];
            if($action==='hold' && $onHand-$reserved-$held<$qty)
                throw new InvalidArgumentException('Insufficient unreserved sellable units to quarantine.');
            if($action!=='hold' && $held<$qty)
                throw new InvalidArgumentException('Cannot release or write off more units than currently quarantined.');
            $next=$held+($action==='hold'?$qty:-$qty);
            if($next<0 || $next>$onHand-$reserved+($action==='writeoff'?$qty:0))
                throw new InvalidArgumentException('Inventory quarantine would break stock reconciliation.');
            $q=$this->db->prepare('UPDATE aelvo_variant_quarantine SET quantity=? WHERE variant_id=?');
            $q->execute([$next,$variant]);
            if($action==='writeoff'){
                $costing=new InventoryCosting($this->db);
                $costing->ensureCoverage($variant,$onHand);
                $costing->dispose($variant,$qty,'quarantine-writeoff',$key);
                $q=$this->db->prepare('UPDATE aelvo_variants SET stock=stock-? WHERE id=?');
                $q->execute([$qty,$variant]);
            }
            $q=$this->db->prepare('INSERT INTO aelvo_quarantine_events(variant_id,request_key,request_hash,action,qty,explanation,actor_id) VALUES (?,?,?,?,?,?,?)');
            $q->execute([$variant,$key,$hash,$action,$qty,$reason,$this->actor['id']]);
            $id=(int)$this->db->lastInsertId();
            $stockDelta=$action==='writeoff'?-$qty:0;
            $q=$this->db->prepare('INSERT INTO aelvo_movements(variant_id,stock_delta,reserved_delta,source_type,source_id,explanation,actor_id) VALUES (?,?,0,?,?,?,?)');
            $q->execute([$variant,$stockDelta,'quarantine-'.$action,(string)$id,$reason,$this->actor['id']]);
            $this->audit('inventory.quarantine-'.$action.':'.$id);
            return $id;
        });
    }
}
