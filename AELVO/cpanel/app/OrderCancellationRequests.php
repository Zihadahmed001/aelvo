<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Account-owned cancellation requests. A request is never an order cancellation. */
final class OrderCancellationRequests extends Service {
    private const PAGE_SIZE=25;

    public static function canRequest(string $status): bool {
        return in_array($status,['Pending','Confirmed'],true);
    }

    public static function reason(array $input): string {
        $reason=Input::text($input,'reason',1000);
        if(strlen($reason)<10 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$reason))
            throw new InvalidArgumentException('Please provide a cancellation reason of 10–1,000 bytes.');
        return $reason;
    }

    public static function request(PDO $db,array $input): int {
        $customer=Customers::current($db);
        if(!$customer)throw new InvalidArgumentException('Sign in to the original order account to request cancellation.');
        $orderId=Input::integer($input['order_id']??null);
        $reason=self::reason($input);
        $nonce=Input::text($input,'request_key',64);
        if(!preg_match('/^[a-f0-9]{64}$/D',$nonce))throw new InvalidArgumentException('Reload the cancellation form.');
        $key=hash('sha256',(int)$customer['id'].':'.$nonce);
        $payloadHash=hash('sha256',json_encode([$orderId,$reason],JSON_THROW_ON_ERROR));
        $db->beginTransaction();
        try{
            // Lock the order first: staff status changes also lock this row.
            $q=$db->prepare('SELECT o.id,o.status FROM aelvo_orders o JOIN aelvo_customer_orders c ON c.order_id=o.id WHERE o.id=? AND c.customer_id=? FOR UPDATE');
            $q->execute([$orderId,(int)$customer['id']]);$order=$q->fetch();
            if(!$order)throw new InvalidArgumentException('Order unavailable in your account. Guest orders are separate.');
            $q=$db->prepare('SELECT id,request_key,request_hash FROM aelvo_order_cancellation_requests WHERE order_id=?');
            $q->execute([$orderId]);$existing=$q->fetch();
            if($existing){
                if(hash_equals((string)$existing['request_key'],$key) && hash_equals((string)$existing['request_hash'],$payloadHash)){
                    $db->commit();return (int)$existing['id'];
                }
                throw new InvalidArgumentException('This order already has a cancellation request. Check its status in My Orders or contact support.');
            }
            if(!self::canRequest((string)$order['status']))
                throw new InvalidArgumentException('This order cannot be requested for cancellation. Delivered orders use the return process.');
            $q=$db->prepare("INSERT INTO aelvo_order_cancellation_requests(order_id,customer_id,reason,request_key,request_hash,state,staff_note) VALUES (?,?,?,?,?,'Pending','')");
            $q->execute([$orderId,(int)$customer['id'],$reason,$key,$payloadHash]);$id=(int)$db->lastInsertId();
            $q=$db->prepare("INSERT INTO aelvo_order_cancellation_events(request_id,action,actor_customer_id,note) VALUES (?,'Requested',?,?)");
            $q->execute([$id,(int)$customer['id'],$reason]);
            $db->commit();return $id;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    /** Requests are looked up by actual customer_id; matching phone numbers confer no ownership. */
    public static function forCustomer(PDO $db,int $customerId,array $orderIds): array {
        $ids=array_values(array_unique(array_filter(array_map('intval',$orderIds),fn(int $id)=>$id>0)));
        if(!$ids)return [];
        if(count($ids)>25)throw new InvalidArgumentException('Too many orders in this page.');
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $q=$db->prepare('SELECT r.*,o.status AS order_status FROM aelvo_order_cancellation_requests r JOIN aelvo_customer_orders c ON c.order_id=r.order_id AND c.customer_id=r.customer_id JOIN aelvo_orders o ON o.id=r.order_id WHERE r.customer_id=? AND r.order_id IN ('.$marks.')');
        $q->execute([$customerId,...$ids]);$mapped=[];
        foreach($q as $record)$mapped[(int)$record['order_id']]=$record;
        return $mapped;
    }

    /** Caller must already hold the order row lock before fulfillment/courier booking. */
    public static function assertNoPending(PDO $db,int $orderId): void {
        $q=$db->prepare("SELECT id FROM aelvo_order_cancellation_requests WHERE order_id=? AND state='Pending' LIMIT 1");
        $q->execute([$orderId]);
        if($q->fetchColumn()!==false)
            throw new InvalidArgumentException('Customer requested cancellation. Review, decline or cancel the order before dispatch or courier booking.');
    }

    public static function filters(array $input): array {
        $state=$input['cancel_state']??'Pending';
        $page=$input['cancel_page']??'1';
        if(!is_string($state)||!in_array($state,['Pending','Declined','Cancelled','All'],true))throw new InvalidArgumentException('Invalid cancellation request state.');
        if(!is_string($page)&&!is_int($page))throw new InvalidArgumentException('Invalid cancellation page.');
        if(!preg_match('/^[1-9]\d{0,3}$/D',(string)$page))throw new InvalidArgumentException('Invalid cancellation page.');
        return ['state'=>$state,'page'=>(int)$page];
    }

    public function queue(array $input): array {
        $this->allow('orders','view');$filter=self::filters($input);
        $effective="(CASE WHEN o.status='Cancelled' THEN 'Cancelled' ELSE r.state END)";
        $where=$filter['state']==='All'?'':' WHERE '.$effective.'=?';
        $args=$filter['state']==='All'?[]:[$filter['state']];
        $q=$this->db->prepare('SELECT COUNT(*) FROM aelvo_order_cancellation_requests r JOIN aelvo_orders o ON o.id=r.order_id'.$where);
        $q->execute($args);$count=(int)$q->fetchColumn();$pages=max(1,(int)ceil($count/self::PAGE_SIZE));$page=min($filter['page'],$pages);
        $q=$this->db->prepare('SELECT r.id,r.order_id,r.reason,r.state,r.staff_note,r.version,r.created_at,o.status AS order_status,o.reference,o.customer_name,'.$effective.' AS effective_state FROM aelvo_order_cancellation_requests r JOIN aelvo_orders o ON o.id=r.order_id'.$where.' ORDER BY r.id DESC LIMIT ? OFFSET ?');
        foreach($args as $i=>$arg)$q->bindValue($i+1,$arg,PDO::PARAM_STR);
        $q->bindValue(count($args)+1,self::PAGE_SIZE,PDO::PARAM_INT);
        $q->bindValue(count($args)+2,($page-1)*self::PAGE_SIZE,PDO::PARAM_INT);
        $q->execute();
        return ['rows'=>$q->fetchAll(),'count'=>$count,'pages'=>$pages,'page'=>$page,'state'=>$filter['state']];
    }

    public function forOrder(int $orderId): ?array {
        $this->allow('orders','view');
        $q=$this->db->prepare('SELECT r.*,o.status AS order_status FROM aelvo_order_cancellation_requests r JOIN aelvo_orders o ON o.id=r.order_id WHERE r.order_id=?');
        $q->execute([$orderId]);$row=$q->fetch();
        if(!$row)return null;
        $q=$this->db->prepare('SELECT action,note,created_at FROM aelvo_order_cancellation_events WHERE request_id=? ORDER BY id ASC');
        $q->execute([$row['id']]);$row['events']=$q->fetchAll();
        return $row;
    }

    /** Staff may decline or send a public message, but may not silently post stock/refund changes. */
    public function review(array $input): void {
        $this->allow('orders','edit');
        $id=Input::integer($input['request_id']??null);
        $version=Input::integer($input['version']??null);
        $decision=Input::text($input,'decision',20);
        if(!in_array($decision,['Message','Decline'],true))throw new InvalidArgumentException('Invalid cancellation review action.');
        if($decision==='Decline')$this->allow('orders','cancel');
        $note=Input::text($input,'staff_note',1500);
        if(strlen($note)<10)throw new InvalidArgumentException('Explain the decision to the customer (at least 10 bytes).');
        $this->transaction(function()use($id,$version,$decision,$note){
            $q=$this->db->prepare('SELECT order_id FROM aelvo_order_cancellation_requests WHERE id=?');$q->execute([$id]);$orderId=$q->fetchColumn();
            if($orderId===false)throw new InvalidArgumentException('Request unavailable.');
            $q=$this->db->prepare('SELECT status FROM aelvo_orders WHERE id=? FOR UPDATE');$q->execute([$orderId]);$orderStatus=$q->fetchColumn();
            if($orderStatus==='Cancelled')throw new RuntimeException('Order is already cancelled. Request is resolved by the order status.',409);
            $state=$decision==='Decline'?'Declined':'Pending';
            $q=$this->db->prepare("UPDATE aelvo_order_cancellation_requests SET state=?,staff_note=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(),version=version+1 WHERE id=? AND version=? AND state='Pending'");
            $q->execute([$state,$note,(int)$this->actor['id'],$id,$version]);
            if($q->rowCount()!==1)throw new RuntimeException('Request changed since it was opened. Reload and retry.',409);
            $q=$this->db->prepare('INSERT INTO aelvo_order_cancellation_events(request_id,action,actor_staff_id,note) VALUES (?,?,?,?)');
            $q->execute([$id,$decision,(int)$this->actor['id'],$note]);
            $this->audit('order-cancellation.'.strtolower($decision).':'.$id);
        });
    }
}
