<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use PDO;

/** Searchable purchase register; supplier payout and receipt status remain independent. */
final class PurchaseRegister extends Service {
    public const PAGE_SIZE=25;
    public static function filters(array $get):array {
        $q=$get['purchase_q']??'';
        $receipt=$get['receipt_status']??'';
        $payment=$get['payment_status']??'';
        $page=$get['purchase_page']??'1';
        $supplier=$get['purchase_supplier']??'';
        if(!is_string($q)||strlen($q)>100||preg_match('/[\x00-\x1f\x7f]/',$q))throw new InvalidArgumentException('Invalid purchase search.');
        if(!is_string($receipt)||!in_array($receipt,['','Draft','Received'],true))throw new InvalidArgumentException('Invalid receiving filter.');
        if(!is_string($payment)||!in_array($payment,['','unpaid','partial','paid'],true))throw new InvalidArgumentException('Invalid payment filter.');
        if((!is_string($page)&&!is_int($page))||!preg_match('/^[1-9][0-9]{0,4}$/D',(string)$page)||(int)$page>10000)
            throw new InvalidArgumentException('Invalid purchase page.');
        if(!is_string($supplier)&&!is_int($supplier))throw new InvalidArgumentException('Invalid purchase supplier.');
        if((string)$supplier!=='' && (!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$supplier)))throw new InvalidArgumentException('Invalid purchase supplier.');
        return ['q'=>trim($q),'receipt'=>$receipt,'payment'=>$payment,'page'=>(int)$page,'supplier_id'=>(string)$supplier===''?null:(int)$supplier];
    }
    public static function like(string $s):string { return str_replace(['!','%','_'],['!!','!%','!_'],$s); }
    public function list(array $get):array {
        $this->allow('purchases','view');
        $f=self::filters($get);
        $where=[];$args=[];
        if($f['q']!==''){
            $where[]="(p.reference LIKE ? ESCAPE '!' OR p.supplier LIKE ? ESCAPE '!')";
            $like='%'.self::like($f['q']).'%';$args[]=$like;$args[]=$like;
        }
        if($f['receipt']!==''){$where[]='p.status=?';$args[]=$f['receipt'];}
        if($f['supplier_id']!==null){
            $where[]='EXISTS (SELECT 1 FROM aelvo_purchase_suppliers link WHERE link.purchase_id=p.id AND link.supplier_id=?)';
            $args[]=$f['supplier_id'];
        }
        $inner='SELECT p.id,p.reference,p.supplier,p.receipt_id,p.total_minor,p.status,p.created_at,'
            .'COALESCE((SELECT SUM(sp.amount_minor) FROM aelvo_supplier_payments sp WHERE sp.purchase_id=p.id),0) AS paid_minor'
            .' FROM aelvo_purchases p'.($where?' WHERE '.implode(' AND ',$where):'');
        $filters=[];
        if($f['payment']==='unpaid')$filters[]='r.paid_minor=0';
        elseif($f['payment']==='partial')$filters[]='r.paid_minor>0 AND r.paid_minor<r.total_minor';
        elseif($f['payment']==='paid')$filters[]='r.paid_minor>=r.total_minor';
        $outer=' FROM ('.$inner.') r'.($filters?' WHERE '.implode(' AND ',$filters):'');
        $count=$this->db->prepare('SELECT COUNT(*)'.$outer);
        foreach($args as $i=>$val)$count->bindValue($i+1,$val,is_int($val)?PDO::PARAM_INT:PDO::PARAM_STR);
        $count->execute();$total=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($total/self::PAGE_SIZE));$page=min($pages,$f['page']);
        $stmt=$this->db->prepare('SELECT r.*'.$outer.' ORDER BY r.id DESC LIMIT ? OFFSET ?');
        foreach($args as $i=>$val)$stmt->bindValue($i+1,$val,is_int($val)?PDO::PARAM_INT:PDO::PARAM_STR);
        $stmt->bindValue(count($args)+1,self::PAGE_SIZE,PDO::PARAM_INT);
        $stmt->bindValue(count($args)+2,($page-1)*self::PAGE_SIZE,PDO::PARAM_INT);
        $stmt->execute();
        return ['rows'=>$stmt->fetchAll(),'total'=>$total,'pages'=>$pages,'page'=>$page,'filters'=>$f];
    }
}
