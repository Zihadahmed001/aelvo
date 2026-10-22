<?php
declare(strict_types=1);
namespace Aelvo;
use PDO;
use InvalidArgumentException;
use RuntimeException;
final class Reviews extends Service {
    public static function submit(PDO $db,int $customer,array $v,array $files=[]): void {
        $item=Input::integer($v['order_item_id']??null);$rating=Input::integer($v['rating']??null,1,5);$body=Input::text($v,'body',6000);
        $savedFiles=[];$db->beginTransaction();
        try{
            $q=$db->prepare("SELECT v.product_id FROM aelvo_order_items i JOIN aelvo_orders o ON o.id=i.order_id JOIN aelvo_customer_orders c ON c.order_id=o.id JOIN aelvo_variants v ON v.id=i.variant_id WHERE i.id=? AND c.customer_id=? AND o.status='Delivered' FOR UPDATE");$q->execute([$item,$customer]);$product=$q->fetchColumn();
            if(!$product)throw new InvalidArgumentException('Reviews require your own delivered order.');
            $q=$db->prepare("INSERT INTO aelvo_reviews(customer_id,order_item_id,product_id,rating,body,reply) VALUES (?,?,?,?,?,'')");$q->execute([$customer,$item,$product,$rating,$body]);$review=(int)$db->lastInsertId();
            if($files){
                if(!is_array($files['error']??null)||count($files['error'])>3)throw new InvalidArgumentException('Choose up to three review photos.');
                foreach($files['error'] as $index=>$error){
                    if($error===UPLOAD_ERR_NO_FILE)continue;
                    $tmp=$files['tmp_name'][$index]??null;
                    if($error!==UPLOAD_ERR_OK||!is_string($tmp)||!is_uploaded_file($tmp))throw new InvalidArgumentException('Review photo upload failed.');
                    $bytes=filesize($tmp);$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);$types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];$dimensions=@getimagesize($tmp);
                    if(!$bytes||$bytes>5*1024*1024||!isset($types[$mime])||!$dimensions||$dimensions[0]*$dimensions[1]>25000000)throw new InvalidArgumentException('Each photo must be JPEG, PNG or WebP, under 5 MB and 25 megapixels.');
                    $id=bin2hex(random_bytes(16));$disk=$id.'.'.$types[$mime];$dir=dirname(__DIR__).'/storage/review-photos';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Photo storage unavailable.');
                    $target=$dir.'/'.$disk;if(!move_uploaded_file($tmp,$target))throw new RuntimeException('Photo upload failed.');$savedFiles[]=$target;chmod($target,0600);
                    $q=$db->prepare('INSERT INTO aelvo_review_photos(id,review_id,disk_name,mime,bytes) VALUES (?,?,?,?,?)');$q->execute([$id,$review,$disk,$mime,$bytes]);
                }
            }
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();foreach($savedFiles as $target)if(is_file($target))unlink($target);throw $e;}
    }
    public function moderate(array $v): void {
        $this->allow('reviews','edit');$id=Input::integer($v['id']??null);$version=Input::integer($v['version']??null);$status=Input::text($v,'status',20);$reply=Input::text($v,'reply',6000,false);
        if(!in_array($status,['Approved','Hidden','Pending'],true))throw new InvalidArgumentException('Invalid review status.');
        $this->transaction(function()use($id,$version,$status,$reply){
            $q=$this->db->prepare('UPDATE aelvo_reviews SET status=?,reply=?,version=version+1 WHERE id=? AND version=?');$q->execute([$status,$reply,$id,$version]);
            if($q->rowCount()!==1)throw new RuntimeException('Review changed. Reload first.',409);
            $this->audit('review.moderated:'.$id.':'.$status);
        });
    }
    public const MODERATION_PAGE_SIZE = 20;
    public const PUBLIC_PAGE_SIZE = 10;

    /** Reject invalid queue filters rather than silently widening access or query scope. */
    public static function moderationFilters(array $params): array {
        $q = $params['q'] ?? '';
        $status = $params['status'] ?? 'Pending';
        $rating = $params['rating'] ?? '';
        $page = $params['p'] ?? '1';
        if (!is_string($q) || strlen($q)>100 || preg_match('/[\x00-\x1f\x7f]/', $q)) {
            throw new InvalidArgumentException('Review search must be at most 100 bytes without control characters.');
        }
        if (!is_string($status) || !in_array($status,['Pending','Approved','Hidden','All'],true)) {
            throw new InvalidArgumentException('Invalid review status filter.');
        }
        if (!is_string($rating) || !in_array($rating,['','1','2','3','4','5'],true)) {
            throw new InvalidArgumentException('Invalid review rating filter.');
        }
        if ((!is_string($page) && !is_int($page)) || !preg_match('/^[1-9][0-9]{0,4}$/D',(string)$page) || (int)$page>10000) {
            throw new InvalidArgumentException('Invalid review page.');
        }
        return ['q'=>trim($q),'status'=>$status,'rating'=>$rating,'p'=>(int)$page];
    }

    public static function likeLiteral(string $s): string {
        return str_replace(['!','%','_'],['!!','!%','!_'],$s);
    }

    /** Permission-filtered, searchable review register; photo IDs loaded only for the visible page. */
    public function moderationQueue(array $params): array {
        $this->allow('reviews','view');
        $f=self::moderationFilters($params);
        $where=[];$args=[];
        if ($f['status']!=='All') {$where[]='r.status=?';$args[]=$f['status'];}
        if ($f['rating']!=='') {$where[]='r.rating=?';$args[]=(int)$f['rating'];}
        if ($f['q']!=='') {
            $match='%'.self::likeLiteral($f['q']).'%';
            $where[]="(p.name LIKE ? ESCAPE '!' OR p.name_bn LIKE ? ESCAPE '!' OR p.slug LIKE ? ESCAPE '!' OR c.name LIKE ? ESCAPE '!')";
            array_push($args,$match,$match,$match,$match);
        }
        $from=' FROM aelvo_reviews r JOIN aelvo_customers c ON c.id=r.customer_id JOIN aelvo_products p ON p.id=r.product_id';
        $condition=$where?' WHERE '.implode(' AND ',$where):'';
        $count=$this->db->prepare('SELECT COUNT(*)'.$from.$condition);
        foreach($args as $i=>$v)$count->bindValue($i+1,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
        $count->execute();$total=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($total/self::MODERATION_PAGE_SIZE));$page=min($pages,$f['p']);
        $sql='SELECT r.id,r.customer_id,r.product_id,r.rating,r.body,r.reply,r.status,r.version,r.created_at,c.name AS customer_name,p.name AS product_name,p.slug AS product_slug'.$from.$condition.' ORDER BY r.id DESC LIMIT ? OFFSET ?';
        $q=$this->db->prepare($sql);
        foreach($args as $i=>$v)$q->bindValue($i+1,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
        $q->bindValue(count($args)+1,self::MODERATION_PAGE_SIZE,PDO::PARAM_INT);
        $q->bindValue(count($args)+2,($page-1)*self::MODERATION_PAGE_SIZE,PDO::PARAM_INT);
        $q->execute();$rows=$q->fetchAll();
        $ids=array_map(static fn($r)=>(int)$r['id'],$rows);
        $photos=self::photoIds($this->db,$ids);
        foreach($rows as &$row)$row['photos']=$photos[(int)$row['id']]??[];
        unset($row);
        return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page'=>$page,'filters'=>$f];
    }

    /** Aggregates only approved reviews; no synthetic rating, paid review or unapproved photo. */
    public static function publishedSummary(PDO $db,int $product): array {
        $q=$db->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(rating),0) AS rating_sum FROM aelvo_reviews WHERE product_id=? AND status='Approved'");
        $q->execute([$product]);$row=$q->fetch();
        $count=(int)($row['total']??0);
        return ['count'=>$count,'average'=>$count>0?round((int)$row['rating_sum']/$count,1):null];
    }

    private static function photoIds(PDO $db,array $ids): array {
        if(!$ids)return [];
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $q=$db->prepare('SELECT id,review_id FROM aelvo_review_photos WHERE review_id IN ('.$marks.') ORDER BY id');
        foreach($ids as $i=>$id)$q->bindValue($i+1,(int)$id,PDO::PARAM_INT);
        $q->execute();$photos=[];
        foreach($q as $photo)$photos[(int)$photo['review_id']][]=$photo['id'];
        return $photos;
    }

    public static function published(PDO $db,int $product,int $page=1): array {
        $page=max(1,min(10000,$page));
        $q=$db->prepare("SELECT r.id,r.rating,r.body,r.reply,r.created_at,c.name FROM aelvo_reviews r JOIN aelvo_customers c ON c.id=r.customer_id WHERE r.product_id=? AND r.status='Approved' ORDER BY r.id DESC LIMIT ? OFFSET ?");
        $q->bindValue(1,$product,PDO::PARAM_INT);
        $q->bindValue(2,self::PUBLIC_PAGE_SIZE,PDO::PARAM_INT);
        $q->bindValue(3,($page-1)*self::PUBLIC_PAGE_SIZE,PDO::PARAM_INT);
        $q->execute();$rows=$q->fetchAll();
        $photos=self::photoIds($db,array_map(static fn($r)=>(int)$r['id'],$rows));
        foreach($rows as &$row)$row['photos']=$photos[(int)$row['id']]??[];
        unset($row);return $rows;
    }
}
