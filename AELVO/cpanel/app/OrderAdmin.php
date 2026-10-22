<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use PDO;

/** Read-only, permission-checked Admin order register and order detail. */
final class OrderAdmin extends Service {
    private const STATUSES=['Pending','Confirmed','Dispatched','Delivered','Cancelled'];
    private const PAGE_SIZE=25;

    /** Validate GET filters before they become SQL parameters or navigation links. */
    public static function filters(array $query): array {
        $status=$query['status']??'';
        $term=$query['q']??'';
        $p=$query['p']??'1';
        if(!is_string($status) || ($status!=='' && !in_array($status,self::STATUSES,true)))
            throw new InvalidArgumentException('Invalid order status filter.');
        if(!is_string($term) || strlen($term)>100 || preg_match('/[\x00-\x1f\x7f]/',$term))
            throw new InvalidArgumentException('Search must be at most 100 bytes without control characters.');
        $term=trim($term);
        if(!is_string($p) && !is_int($p))throw new InvalidArgumentException('Invalid page number.');
        $p=(string)$p;
        if(!preg_match('/^[1-9][0-9]{0,4}$/D',$p) || (int)$p>10000)
            throw new InvalidArgumentException('Invalid page number.');
        return ['status'=>$status,'q'=>$term,'p'=>(int)$p];
    }

    /** Quote SQL LIKE wildcards, so searches are literal even for % and _. */
    public static function escapeLike(string $value): string {
        return str_replace(['!','%','_'],['!!','!%','!_'],$value);
    }

    public function listing(array $filter): array {
        $this->allow('orders','view');
        $filter=self::filters($filter);
        $conditions=[];$args=[];
        if($filter['status']!==''){$conditions[]='status=?';$args[]=$filter['status'];}
        if($filter['q']!==''){
            $conditions[]="(reference LIKE ? ESCAPE '!' OR customer_name LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!')";
            $search='%'.self::escapeLike($filter['q']).'%';
            array_push($args,$search,$search,$search);
        }
        $where=$conditions?' WHERE '.implode(' AND ',$conditions):'';
        $q=$this->db->prepare('SELECT COUNT(*) FROM aelvo_orders'.$where);
        $q->execute($args);$total=(int)$q->fetchColumn();
        $maxPage=max(1,(int)ceil($total/self::PAGE_SIZE));
        // A disappearing last page cannot silently become an unbounded list.
        $page=min($filter['p'],$maxPage);
        $sql='SELECT id,reference,customer_name,phone,total_minor,status,payment_status,created_at FROM aelvo_orders'.$where.' ORDER BY id DESC LIMIT ? OFFSET ?';
        $q=$this->db->prepare($sql);
        foreach($args as $i=>$value)$q->bindValue($i+1,$value,PDO::PARAM_STR);
        $q->bindValue(count($args)+1,self::PAGE_SIZE,PDO::PARAM_INT);
        $q->bindValue(count($args)+2,($page-1)*self::PAGE_SIZE,PDO::PARAM_INT);
        $q->execute();
        return ['rows'=>$q->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>$maxPage,'status'=>$filter['status'],'q'=>$filter['q']];
    }

    public function detail(int $id): ?array {
        $this->allow('orders','view');
        $q=$this->db->prepare('SELECT * FROM aelvo_orders WHERE id=?');$q->execute([$id]);
        $order=$q->fetch();if(!$order)return null;
        // A general order editor may see a safety hold, not private gateway credentials or references.
        $order['gateway_hold_state']=GatewayPayments::unresolvedForOrder($this->db,$id);
        $q=$this->db->prepare("SELECT COUNT(*) FROM aelvo_courier_exception_cases c JOIN aelvo_courier_shipments s ON s.id=c.shipment_id WHERE s.order_id=? AND c.state IN ('Pending','Approved')");
        $q->execute([$id]);$order['courier_exception_hold']=(int)$q->fetchColumn()>0;
        $q=$this->db->prepare('SELECT product_name,variant_label,qty,unit_price_minor FROM aelvo_order_items WHERE order_id=? ORDER BY id');
        $q->execute([$id]);$order['items']=$q->fetchAll();
        $q=$this->db->prepare('SELECT status,created_at FROM aelvo_order_events WHERE order_id=? ORDER BY id');
        $q->execute([$id]);$order['events']=$q->fetchAll();
        $q=$this->db->prepare('SELECT COALESCE(SUM(discount_minor),0) FROM aelvo_coupon_redemptions WHERE order_id=?');
        $q->execute([$id]);$order['coupon_minor']=(int)$q->fetchColumn();
        if(Security::can($this->db,$this->actor,'payments','view')){
            $q=$this->db->prepare('SELECT advance_minor FROM aelvo_order_finance WHERE order_id=?');$q->execute([$id]);
            $order['advance_minor']=(int)($q->fetchColumn()?:0);
            $q=$this->db->prepare('SELECT method,reference,amount_minor,created_at FROM aelvo_order_receipts WHERE order_id=? ORDER BY id');
            $q->execute([$id]);$order['receipts']=$q->fetchAll();
            $q=$this->db->prepare('SELECT a.tran_id,r.amount_minor,r.confirmed_at FROM aelvo_gateway_receipts r JOIN aelvo_gateway_attempts a ON a.id=r.attempt_id WHERE r.order_id=? ORDER BY r.confirmed_at');
            $q->execute([$id]);$order['gateway_receipts']=$q->fetchAll();
        }
        if(Security::can($this->db,$this->actor,'returns','view')){
            $q=$this->db->prepare('SELECT amount_minor,method,reference,created_at FROM aelvo_refunds WHERE order_id=? ORDER BY id');
            $q->execute([$id]);$order['refunds']=$q->fetchAll();
        }
        if(Security::can($this->db,$this->actor,'courier','view')){
            $q=$this->db->prepare('SELECT id,booking_state,remote_status,tracking_code,cod_minor,updated_at FROM aelvo_courier_shipments WHERE order_id=?');
            $q->execute([$id]);$order['courier']=$q->fetch()?:null;
        }
        return $order;
    }
}
