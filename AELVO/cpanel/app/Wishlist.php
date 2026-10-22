<?php
declare(strict_types=1);
namespace Aelvo;
use PDO;
use InvalidArgumentException;
final class Wishlist {
    public static function ids(PDO $db,int $customer): array {
        $q=$db->prepare('SELECT product_id FROM aelvo_wishlist WHERE customer_id=? ORDER BY created_at DESC,product_id DESC');$q->execute([$customer]);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    }
    public static function save(PDO $db,array $v): void {
        $customer=Customers::current($db);if(!$customer)throw new InvalidArgumentException('Sign in to save your wishlist.');
        $product=Input::integer($v['product_id']??null);$mode=Input::text($v,'mode',10);
        if(!in_array($mode,['save','remove'],true))throw new InvalidArgumentException('Invalid wishlist action.');
        $db->beginTransaction();try{
            $q=$db->prepare("SELECT id FROM aelvo_customers WHERE id=? AND status='Active' FOR UPDATE");$q->execute([$customer['id']]);if(!$q->fetch())throw new InvalidArgumentException('Sign in again.');
            if($mode==='remove'){$q=$db->prepare('DELETE FROM aelvo_wishlist WHERE customer_id=? AND product_id=?');$q->execute([$customer['id'],$product]);}
            else{
                $q=$db->prepare("SELECT id FROM aelvo_products WHERE id=? AND status='Active'");$q->execute([$product]);if(!$q->fetch())throw new InvalidArgumentException('Product unavailable.');
                $q=$db->prepare('SELECT product_id FROM aelvo_wishlist WHERE customer_id=? AND product_id=?');$q->execute([$customer['id'],$product]);
                if(!$q->fetch()){
                    $q=$db->prepare('SELECT COUNT(*) FROM aelvo_wishlist WHERE customer_id=?');$q->execute([$customer['id']]);if((int)$q->fetchColumn()>=100)throw new InvalidArgumentException('Your wishlist holds up to 100 products.');
                    $q=$db->prepare('INSERT INTO aelvo_wishlist(customer_id,product_id) VALUES (?,?)');$q->execute([$customer['id'],$product]);
                }
            }$db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
