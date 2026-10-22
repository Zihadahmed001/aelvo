<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use PDO;

/** Bounded, permission-checked directory; supplier dues use linked purchase history only. */
final class SupplierRegister extends Service {
    public const PAGE_SIZE=25;

    public static function filters(array $get):array {
        $q=$get['supplier_q']??'';
        $status=$get['supplier_status']??'';
        $due=$get['supplier_due']??'';
        $page=$get['supplier_page']??'1';
        if(!is_string($q)||strlen($q)>100||preg_match('/[\x00-\x1f\x7f]/',$q))
            throw new InvalidArgumentException('Invalid supplier search.');
        if(!is_string($status)||!in_array($status,['','active','inactive'],true))
            throw new InvalidArgumentException('Invalid supplier status.');
        if(!is_string($due)||!in_array($due,['','owing','clear'],true))
            throw new InvalidArgumentException('Invalid supplier balance filter.');
        if((!is_int($page)&&!is_string($page))||!preg_match('/^[1-9][0-9]{0,4}$/D',(string)$page)||(int)$page>10000)
            throw new InvalidArgumentException('Invalid supplier page.');
        return ['q'=>trim($q),'status'=>$status,'due'=>$due,'page'=>(int)$page];
    }

    public static function like(string $input):string {
        return str_replace(['!','%','_'],['!!','!%','!_'],$input);
    }

    public function list(array $get):array {
        $this->allow('purchases','view');
        $f=self::filters($get);
        $where=[];$params=[];
        if($f['q']!==''){
            $like='%'.self::like($f['q']).'%';
            $where[]="(s.name LIKE ? ESCAPE '!' OR s.company LIKE ? ESCAPE '!' OR s.phone LIKE ? ESCAPE '!' OR s.email LIKE ? ESCAPE '!')";
            array_push($params,$like,$like,$like,$like);
        }
        if($f['status']!=='') $where[]='s.active='.($f['status']==='active'?'1':'0');
        // Payments are pre-aggregated per purchase: each purchase is counted exactly once.
        $totals="SELECT ps.supplier_id,COUNT(*) AS purchase_count,"
            ."COALESCE(SUM(p.total_minor),0) AS booked_minor,"
            ."COALESCE(SUM(COALESCE(paid.paid_minor,0)),0) AS paid_minor "
            ."FROM aelvo_purchase_suppliers ps "
            ."JOIN aelvo_purchases p ON p.id=ps.purchase_id "
            ."LEFT JOIN (SELECT purchase_id,SUM(amount_minor) paid_minor FROM aelvo_supplier_payments GROUP BY purchase_id) paid "
            ."ON paid.purchase_id=p.id GROUP BY ps.supplier_id";
        $from=' FROM aelvo_suppliers s LEFT JOIN ('.$totals.') t ON t.supplier_id=s.id';
        if($f['due']==='owing')$where[]='COALESCE(t.booked_minor,0)>COALESCE(t.paid_minor,0)';
        if($f['due']==='clear')$where[]='COALESCE(t.booked_minor,0)<=COALESCE(t.paid_minor,0)';
        if($where)$from.=' WHERE '.implode(' AND ',$where);
        $count=$this->db->prepare('SELECT COUNT(*)'.$from);
        foreach($params as $i=>$value)$count->bindValue($i+1,$value,PDO::PARAM_STR);
        $count->execute();$total=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($total/self::PAGE_SIZE));$page=min($f['page'],$pages);
        $stmt=$this->db->prepare('SELECT s.id,s.name,s.company,s.phone,s.email,s.active,s.version,'
            .'COALESCE(t.purchase_count,0) AS purchase_count,'
            .'COALESCE(t.booked_minor,0) AS booked_minor,'
            .'COALESCE(t.paid_minor,0) AS paid_minor'.$from.' ORDER BY s.id DESC LIMIT ? OFFSET ?');
        foreach($params as $i=>$value)$stmt->bindValue($i+1,$value,PDO::PARAM_STR);
        $stmt->bindValue(count($params)+1,self::PAGE_SIZE,PDO::PARAM_INT);
        $stmt->bindValue(count($params)+2,($page-1)*self::PAGE_SIZE,PDO::PARAM_INT);
        $stmt->execute();
        return ['rows'=>$stmt->fetchAll(),'total'=>$total,'pages'=>$pages,'page'=>$page,'filters'=>$f];
    }

    public function activeSupplier(int $id):array {
        $this->allow('purchases','view');
        $q=$this->db->prepare('SELECT id,name,company FROM aelvo_suppliers WHERE id=? AND active=1');
        $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new InvalidArgumentException('Supplier is not active or does not exist.');
        return $row;
    }
}
