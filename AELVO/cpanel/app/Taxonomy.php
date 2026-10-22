<?php
declare(strict_types=1);
namespace Aelvo;
use PDO;
use InvalidArgumentException;
use RuntimeException;
/** Public, search-indexable fashion categories and brands, edited under SEO RBAC. */
final class Taxonomy extends Service {
    public const ROOTS=['Shoes','Socks','Belts','Wallets','Bags'];
    public static function table(string $kind): string {
        return match($kind){'category'=>'aelvo_categories','brand'=>'aelvo_brands',default=>throw new InvalidArgumentException('Unknown taxonomy type.')};
    }
    public static function page(PDO $db,string $kind,int $id): ?array {
        $q=$db->prepare('SELECT * FROM '.self::table($kind).' WHERE id=? AND status=?');
        $q->execute([$id,'Active']);return $q->fetch()?:null;
    }
    public static function listings(PDO $db,string $kind): array {
        $order=$kind==='category'?'root_code,parent_id,id':'name,id';
        return $db->query('SELECT * FROM '.self::table($kind).' ORDER BY '.$order.' LIMIT 300')->fetchAll();
    }
    /** Update product association inside the catalogue save transaction. */
    public static function linkProduct(PDO $db,int $productId,string $root,array $input): void {
        $id=Input::integer($input['category_id']??0,0);
        if($id===0){$q=$db->prepare('SELECT id FROM aelvo_categories WHERE root_code=? AND parent_id IS NULL AND status=? LIMIT 1');$q->execute([$root,'Active']);$id=(int)$q->fetchColumn();}
        $q=$db->prepare('SELECT id FROM aelvo_categories WHERE id=? AND root_code=? AND status=?');$q->execute([$id,$root,'Active']);
        if(!$q->fetchColumn())throw new InvalidArgumentException('Choose an active category in the selected family.');
        $brand=Input::integer($input['brand_id']??0,0);
        if($brand>0){$q=$db->prepare('SELECT id FROM aelvo_brands WHERE id=? AND status=?');$q->execute([$brand,'Active']);if(!$q->fetchColumn())throw new InvalidArgumentException('Choose an active brand.');}
        $q=$db->prepare('INSERT INTO aelvo_product_taxonomy(product_id,category_id,brand_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE category_id=VALUES(category_id),brand_id=VALUES(brand_id)');
        $q->execute([$productId,$id,$brand?:null]);
    }
    public function save(array $v): int {
        $this->allow('seo','edit');
        $kind=Input::text($v,'kind',20);$table=self::table($kind);
        $id=Input::integer($v['id']??0,0);$version=Input::integer($v['version']??0,0);
        $slug=Input::text($v,'slug',180);PublicRoutes::entityPath($kind,$slug);
        $name=Input::text($v,'name',180);$nameBn=Input::text($v,'name_bn',180,false);
        $description=Input::text($v,'description',20000,false);
        $descriptionBn=Input::text($v,'description_bn',20000,false);
        $seoTitle=Input::text($v,'seo_title',180,false);
        $canonical=Input::text($v,'canonical_path',220,false);
        if($canonical!=='')$canonical=PublicRoutes::path($canonical);
        $ogImage=Input::text($v,'og_image_id',32,false);
        if($ogImage!==''&&!preg_match('/^[a-f0-9]{32}$/D',$ogImage))throw new InvalidArgumentException('Choose a valid public photo ID.');
        $seoDescription=Input::text($v,'seo_description',600,false);
        $ogTitle=Input::text($v,'og_title',180,false);
        $ogDescription=Input::text($v,'og_description',600,false);
        $status=Input::text($v,'status',12);
        if(!in_array($status,['Active','Hidden'],true))throw new InvalidArgumentException('Invalid visibility.');
        $index=Input::text($v,'index_mode',10);
        $follow=Input::text($v,'follow_mode',10);
        if(!in_array($index,['inherit','index','noindex'],true)||!in_array($follow,['inherit','follow','nofollow'],true))throw new InvalidArgumentException('Invalid robots setting.');
        $root='';$parent=null;
        if($kind==='category'){
            $root=Input::text($v,'root_code',80);
            if(!in_array($root,self::ROOTS,true))throw new InvalidArgumentException('Choose a fashion category family.');
            $parentInput=Input::integer($v['parent_id']??0,0);
            if($parentInput>0)$parent=$parentInput;
        }
        return $this->transaction(function()use($v,$kind,$table,$id,$version,$slug,$name,$nameBn,$description,$descriptionBn,$seoTitle,$canonical,$ogImage,$seoDescription,$ogTitle,$ogDescription,$status,$index,$follow,$root,$parent){
            $oldSlug=$slug;
            if($id){$q=$this->db->prepare('SELECT * FROM '.$table.' WHERE id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch();if(!$old)throw new InvalidArgumentException('Record not found.');$oldSlug=$old['slug'];}
            if($kind==='category'){
                if($parent!==null){
                    if($parent===$id)throw new InvalidArgumentException('Category cannot parent itself.');
                    $q=$this->db->prepare('SELECT root_code,parent_id FROM aelvo_categories WHERE id=? FOR UPDATE');$q->execute([$parent]);$row=$q->fetch();
                    if(!$row||$row['root_code']!==$root||$row['parent_id']!==null)throw new InvalidArgumentException('Subcategory must use an existing top-level category parent in the same family.');
                } else {
                    $q=$this->db->prepare('SELECT id FROM aelvo_categories WHERE root_code=? AND parent_id IS NULL AND id<>? LIMIT 1');$q->execute([$root,$id]);
                    if($q->fetchColumn())throw new InvalidArgumentException('Each category family already has a top-level category. Choose its parent.');
                }
                if($id){
                    if($old['parent_id']===null && $parent!==null)throw new InvalidArgumentException('Top-level fashion families cannot become subcategories.');
                    $q=$this->db->prepare('SELECT 1 FROM aelvo_product_taxonomy WHERE category_id=? LIMIT 1');$q->execute([$id]);
                    if($q->fetchColumn() && $root!==$old['root_code'])throw new InvalidArgumentException('Cannot move a category with assigned products to a different family.');
                    $q=$this->db->prepare('SELECT 1 FROM aelvo_categories WHERE parent_id=? LIMIT 1');$q->execute([$id]);
                    if($q->fetchColumn() && ($parent!==null||$root!==$old['root_code']))throw new InvalidArgumentException('Move child categories first.');
                }
            }
            $fields=['slug','name','name_bn','description','description_bn','seo_title','canonical_path','og_image_id','seo_description','og_title','og_description','index_mode','follow_mode','status'];
            $values=[$slug,$name,$nameBn,$description,$descriptionBn,$seoTitle,$canonical,$ogImage,$seoDescription,$ogTitle,$ogDescription,$index,$follow,$status];
            if($kind==='category'){$fields[]='root_code';$fields[]='parent_id';$values[]=$root;$values[]=$parent;}
            if($id){
                $set=implode(',',array_map(static fn($s)=>$s.'=?',$fields));
                $q=$this->db->prepare('UPDATE '.$table.' SET '.$set.',version=version+1 WHERE id=? AND version=?');$q->execute([...$values,$id,$version]);
                if($q->rowCount()!==1)throw new RuntimeException('Record changed. Reload before saving.',409);
            } else {
                $q=$this->db->prepare('INSERT INTO '.$table.'('.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');$q->execute($values);$id=(int)$this->db->lastInsertId();
            }
            PublicRoutes::assignEntity($this->db,$kind,$id,$oldSlug,$slug,(int)$this->actor['id']);
            if($canonical!==''){
                $resolved=PublicRoutes::resolve($this->db,$canonical);
                if(($canonical!==PublicRoutes::entityPath($kind,$slug)||$status==='Active') && (!$resolved||$resolved['type']!==$kind))throw new InvalidArgumentException('Canonical must target a currently visible '.($kind).' page.');
            }
            $this->audit('seo.'.$kind.'-saved:'.$id);return $id;
        });
    }
}
