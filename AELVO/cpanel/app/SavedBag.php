<?php
declare(strict_types=1);
namespace Aelvo;
use PDO;
use InvalidArgumentException;
final class SavedBag {
    public static function read(PDO $db,int $customer): array {
        $q=$db->prepare('SELECT variant_id,qty FROM aelvo_customer_bag WHERE customer_id=? ORDER BY variant_id');$q->execute([$customer]);$bag=[];foreach($q as $row)$bag[(int)$row['variant_id']]=(int)$row['qty'];return $bag;
    }
    public static function lock(PDO $db,int $customer): void {
        $q=$db->prepare("SELECT id FROM aelvo_customers WHERE id=? AND status='Active' FOR UPDATE");$q->execute([$customer]);if(!$q->fetch())throw new InvalidArgumentException('Account unavailable.');
    }
    public static function hydrate(PDO $db,?array $customer): void {
        if(!$customer)return;$id=(int)$customer['id'];
        if(($_SESSION['bag_owner']??null)!==$id){
            $guest=isset($_SESSION['bag_owner'])?[]:($_SESSION['bag']??[]);
            $merge=hash('sha256',$_SESSION['checkout_identity']);
            $db->beginTransaction();try{
                self::lock($db,$id);
                $q=$db->prepare('SELECT customer_id FROM aelvo_bag_merges WHERE customer_id=? AND session_hash=?');$q->execute([$id,$merge]);
                if(!$q->fetch()){
                    $bag=self::read($db,$id);
                    foreach($guest as $variant=>$qty){
                        $variant=Input::integer($variant);$qty=Input::integer($qty,1,100);
                        if(!isset($bag[$variant])&&count($bag)>=30)continue;
                        $q=$db->prepare("SELECT v.id FROM aelvo_variants v JOIN aelvo_products p ON p.id=v.product_id WHERE v.id=? AND v.active=1 AND p.status='Active'");$q->execute([$variant]);if(!$q->fetch())continue;
                        // A saved bag is an intention, not a reservation. Checkout rechecks stock/price.
                        $bag[$variant]=min(100,($bag[$variant]??0)+$qty);
                        $q=$db->prepare('INSERT INTO aelvo_customer_bag(customer_id,variant_id,qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty=VALUES(qty),updated_at=UTC_TIMESTAMP()');$q->execute([$id,$variant,$bag[$variant]]);
                    }
                    $q=$db->prepare('INSERT INTO aelvo_bag_merges(customer_id,session_hash) VALUES (?,?)');$q->execute([$id,$merge]);
                }$db->commit();
            }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            $_SESSION['bag_owner']=$id;
        }
        $_SESSION['bag']=self::read($db,$id);
    }
    public static function mutate(PDO $db,int $variant,int $qty,bool $remove=false): void {
        $customer=Customers::current($db);if(!$customer)throw new InvalidArgumentException('Sign in again.');$id=(int)$customer['id'];
        $db->beginTransaction();try{
            self::lock($db,$id);$bag=self::read($db,$id);
            if($remove){$q=$db->prepare('DELETE FROM aelvo_customer_bag WHERE customer_id=? AND variant_id=?');$q->execute([$id,$variant]);}
            else{
                if(!isset($bag[$variant])&&count($bag)>=30)throw new InvalidArgumentException('Keep up to 30 variants in your bag.');
                $new=($bag[$variant]??0)+$qty;
                $q=$db->prepare("SELECT GREATEST(v.stock-v.reserved-COALESCE(q.quantity,0),0) FROM aelvo_variants v JOIN aelvo_products p ON p.id=v.product_id LEFT JOIN aelvo_variant_quarantine q ON q.variant_id=v.id WHERE v.id=? AND v.active=1 AND p.status='Active'");$q->execute([$variant]);$available=$q->fetchColumn();
                if($available===false||$new>100||$new<1||$new>(int)$available)throw new InvalidArgumentException('Not enough stock for this quantity.');
                $q=$db->prepare('INSERT INTO aelvo_customer_bag(customer_id,variant_id,qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty=VALUES(qty),updated_at=UTC_TIMESTAMP()');$q->execute([$id,$variant,$new]);
            }$db->commit();$_SESSION['bag']=self::read($db,$id);
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** A bag quantity is an intention, not a stock reservation. Revalidate at checkout. */
    public static function validateQuantity(int $quantity,int $available): void {
        if($quantity<1 || $quantity>100 || $available<0 || $quantity>$available)
            throw new InvalidArgumentException('Not enough stock for this quantity.');
    }
    /** Replace rather than increment: browser retries must not multiply the bag. */
    public static function replaceQuantity(PDO $db,int $variant,int $quantity): void {
        $customer=Customers::current($db);
        if(!$customer)throw new InvalidArgumentException('Sign in again.');
        if($variant<1)throw new InvalidArgumentException('Invalid variant.');
        $id=(int)$customer['id'];
        $db->beginTransaction();
        try {
            self::lock($db,$id);
            $q=$db->prepare('SELECT qty FROM aelvo_customer_bag WHERE customer_id=? AND variant_id=? FOR UPDATE');
            $q->execute([$id,$variant]);
            if($q->fetchColumn()===false)throw new InvalidArgumentException('This item is no longer in your bag.');
            $q=$db->prepare("SELECT GREATEST(v.stock-v.reserved-COALESCE(q.quantity,0),0) FROM aelvo_variants v JOIN aelvo_products p ON p.id=v.product_id LEFT JOIN aelvo_variant_quarantine q ON q.variant_id=v.id WHERE v.id=? AND v.active=1 AND p.status='Active'");
            $q->execute([$variant]);$available=$q->fetchColumn();
            if($available===false)throw new InvalidArgumentException('This variant is currently unavailable. Remove it from your bag.');
            self::validateQuantity($quantity,(int)$available);
            $q=$db->prepare('UPDATE aelvo_customer_bag SET qty=?,updated_at=UTC_TIMESTAMP() WHERE customer_id=? AND variant_id=?');
            $q->execute([$quantity,$id,$variant]);
            $db->commit();
            $_SESSION['bag']=self::read($db,$id);
        } catch(\Throwable $e) {
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
    }
    // Called inside the order transaction after its customer row has been locked.
    public static function purchased(PDO $db,int $customer,array $items): void {
        foreach($items as $item){
            $q=$db->prepare('UPDATE aelvo_customer_bag SET qty=IF(qty>?,qty-?,0),updated_at=UTC_TIMESTAMP() WHERE customer_id=? AND variant_id=?');$q->execute([$item['qty'],$item['qty'],$customer,$item['variant_id']]);
        }
        $q=$db->prepare('DELETE FROM aelvo_customer_bag WHERE customer_id=? AND qty=0');$q->execute([$customer]);
    }
}
