<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use PDO;

/** Bounded, read-only work queues: customer requests, inspected receipts and refund approvals. */
final class ReturnOperations extends Service {
    public const PAGE_SIZE = 25;
    public const REQUEST_STATUSES = ['Pending','Accepted','Partial','Received','Rejected','All'];
    public const REFUND_STATUSES = ['Pending','Approved','Rejected','Completed','All'];
    public const RECEIPT_STATES = ['unposted','posted','all'];
    public const ITEM_STATES = ['outstanding','all'];

    /** Distinct parameter prefixes keep each queue's state independent on the shared page. */
    public static function filters(array $query,string $queue): array {
        $settings = match($queue) {
            'requests' => ['r',self::REQUEST_STATUSES,'Pending'],
            'refunds' => ['f',self::REFUND_STATUSES,'Pending'],
            'receipts' => ['s',self::RECEIPT_STATES,'unposted'],
            'items' => ['i',self::ITEM_STATES,'outstanding'],
            default => throw new InvalidArgumentException('Unknown returns queue.'),
        };
        [$prefix,$statuses,$default]=$settings;
        $status=$query[$prefix.'_status']??$default;
        $search=$query[$prefix.'_q']??'';
        $page=$query[$prefix.'_page']??'1';
        if(!is_string($status)||!in_array($status,$statuses,true))throw new InvalidArgumentException('Invalid return queue status.');
        if(!is_string($search)||strlen($search)>80||preg_match('/[\x00-\x1f\x7f]/',$search))throw new InvalidArgumentException('Return search must be 80 bytes or fewer without control characters.');
        if(!is_string($page)&&!is_int($page))throw new InvalidArgumentException('Invalid return queue page.');
        if(!preg_match('/^[1-9][0-9]{0,4}$/D',(string)$page)||(int)$page>10000)throw new InvalidArgumentException('Invalid return queue page.');
        return ['status'=>$status,'q'=>trim($search),'page'=>(int)$page];
    }

    public static function escapeLike(string $term): string {
        return str_replace(['!','%','_'],['!!','!%','!_'],$term);
    }

    /** No arbitrary client SQL, ordering, limit, or offset. Never return unbounded records. */
    private function page(string $countSql,string $rowsSql,array $params,int $requested): array {
        $count=$this->db->prepare($countSql);
        foreach($params as $i=>$value)$count->bindValue($i+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
        $count->execute();$total=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($total/self::PAGE_SIZE));$current=min($pages,$requested);
        $list=$this->db->prepare($rowsSql.' LIMIT ? OFFSET ?');
        foreach($params as $i=>$value)$list->bindValue($i+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
        $list->bindValue(count($params)+1,self::PAGE_SIZE,PDO::PARAM_INT);
        $list->bindValue(count($params)+2,($current-1)*self::PAGE_SIZE,PDO::PARAM_INT);
        $list->execute();
        return ['rows'=>$list->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'page'=>$current,'pages'=>$pages];
    }

    private static function searchWhere(string $term,string $idColumn,string $referenceColumn,array &$where,array &$params): void {
        if($term==='')return;
        $like='%'.self::escapeLike($term).'%';
        if(ctype_digit($term)&&strlen($term)<=18&&(int)$term>0){
            $where[]='('.$idColumn.'=? OR '.$referenceColumn." LIKE ? ESCAPE '!')";
            $params[]=(int)$term;$params[]=$like;
        } else {
            $where[]='('.$referenceColumn." LIKE ? ESCAPE '!')";
            $params[]=$like;
        }
    }

    public function customerRequests(array $query): array {
        $this->allow('returns','view');$f=self::filters($query,'requests');
        $base=' FROM aelvo_customer_return_requests r JOIN aelvo_order_items i ON i.id=r.order_item_id JOIN aelvo_orders o ON o.id=i.order_id';
        $where=[];$params=[];
        if($f['status']!=='All'){$where[]='r.status=?';$params[]=$f['status'];}
        self::searchWhere($f['q'],'r.id','o.reference',$where,$params);
        $clause=$where?' WHERE '.implode(' AND ',$where):'';
        $result=$this->page('SELECT COUNT(*)'.$base.$clause,
            'SELECT r.*,o.reference,i.product_name,i.variant_label,(SELECT id FROM aelvo_return_request_photos p WHERE p.request_id=r.id ORDER BY p.id LIMIT 1) AS photo_id, '
            .'(SELECT COALESCE(SUM(g.qty),0) FROM aelvo_return_request_receipts l JOIN aelvo_returns g ON g.id=l.return_id WHERE l.request_id=r.id) AS received_qty'
            .$base.$clause.' ORDER BY r.id DESC',$params,$f['page']);
        return $result+['filters'=>$f];
    }

    public function deliveredItems(array $query): array {
        $this->allow('returns','view');$f=self::filters($query,'items');
        $received='(SELECT COALESCE(SUM(g.qty),0) FROM aelvo_returns g WHERE g.order_item_id=i.id)';
        $base=' FROM aelvo_order_items i JOIN aelvo_orders o ON o.id=i.order_id';
        $where=["o.status='Delivered'"];$params=[];
        if($f['status']==='outstanding')$where[]='i.qty>'.$received;
        self::searchWhere($f['q'],'i.id','o.reference',$where,$params);
        $clause=' WHERE '.implode(' AND ',$where);
        $result=$this->page('SELECT COUNT(*)'.$base.$clause,
            'SELECT i.id,i.order_id,i.variant_id,i.product_name,i.variant_label,i.qty,o.reference,'.$received.' AS received'
            .$base.$clause.' ORDER BY i.id DESC',$params,$f['page']);
        return $result+['filters'=>$f];
    }

    public function inspectedReceipts(array $query): array {
        $this->allow('returns','view');$f=self::filters($query,'receipts');
        $base=' FROM aelvo_returns r JOIN aelvo_order_items i ON i.id=r.order_item_id JOIN aelvo_orders o ON o.id=i.order_id LEFT JOIN aelvo_return_dispositions d ON d.return_id=r.id';
        $where=[];$params=[];
        if($f['status']==='unposted')$where[]='r.restocked=0 AND d.sellable_qty>0';
        elseif($f['status']==='posted')$where[]='r.restocked=1';
        self::searchWhere($f['q'],'r.id','o.reference',$where,$params);
        $clause=$where?' WHERE '.implode(' AND ',$where):'';
        $result=$this->page('SELECT COUNT(*)'.$base.$clause,
            'SELECT r.*,i.order_id,o.reference,d.sellable_qty,d.damaged_qty,d.inspection,d.posted_at'
            .$base.$clause.' ORDER BY r.id DESC',$params,$f['page']);
        return $result+['filters'=>$f];
    }

    public function refunds(array $query): array {
        $this->allow('returns','view');$this->allow('finance','view');
        $f=self::filters($query,'refunds');
        $base=' FROM aelvo_refund_requests r LEFT JOIN aelvo_refund_tax_allocations t ON t.refund_request_id=r.id JOIN aelvo_orders o ON o.id=r.order_id JOIN aelvo_accounts a ON a.id=r.account_id';
        $where=[];$params=[];
        if($f['status']!=='All'){$where[]='r.status=?';$params[]=$f['status'];}
        self::searchWhere($f['q'],'r.id','o.reference',$where,$params);
        $clause=$where?' WHERE '.implode(' AND ',$where):'';
        $result=$this->page('SELECT COUNT(*)'.$base.$clause,
            'SELECT r.*,o.reference,o.tax_minor AS original_tax_minor,t.tax_minor AS tax_refund_minor,a.name AS account_name'.$base.$clause.' ORDER BY r.id DESC',$params,$f['page']);
        return $result+['filters'=>$f];
    }

    /** HTML-escaped by the caller; all query segments are URL-encoded. */
    public static function url(string $queue,array $filters,int $page): string {
        $prefix=match($queue){'requests'=>'r','refunds'=>'f','receipts'=>'s','items'=>'i',default=>throw new InvalidArgumentException('Invalid return queue.')};
        return '?'.http_build_query(['page'=>'returns',$prefix.'_status'=>$filters['status'],$prefix.'_q'=>$filters['q'],$prefix.'_page'=>$page],'','&',PHP_QUERY_RFC3986);
    }
}
