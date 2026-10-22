<?php
declare(strict_types=1);
namespace Aelvo;
use InvalidArgumentException;
use PDO;
use RuntimeException;
final class Catalogue extends Service {
    public function save(array $v): int {
        $id=isset($v['id']) && $v['id']!==''?Input::integer($v['id']):null;
        $this->allow('products',$id?'edit':'create');
        $name=Input::text($v,'name'); $bn=Input::text($v,'name_bn',540,false);
        $slug=Input::text($v,'slug',180);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug) || in_array($slug,['admin','api','login','checkout','cart','shop'])) throw new InvalidArgumentException('Choose a lowercase product slug.');
        $category=Input::text($v,'category',80);
        if (!in_array($category,['Shoes','Bags','Wallets','Socks','Belts'],true)) throw new InvalidArgumentException('Choose a fashion category.');
        $status=Input::text($v,'status',20);
        if (!in_array($status,['Draft','Active'],true)) throw new InvalidArgumentException('Invalid product status');
        $details=ProductDetails::parse($v);
        $basePrice=Input::money($v['price']??null);
        $salePrice=VariantPricing::sale($v['sale_price']??'', $basePrice);
        $flags=[];
        foreach(['is_featured','is_new_arrival'] as $flag){
            $raw=$v[$flag]??'0';
            if(!in_array($raw,['0','1'],true))throw new InvalidArgumentException('Invalid merchandising flag.');
            $flags[]=($raw==='1'?1:0);
        }
        $fields=[$name,$bn,$slug,$category,Input::text($v,'description',20000,false),Input::text($v,'description_bn',30000,false),Input::text($v,'details',20000,false),$basePrice,$salePrice,...$flags,$status];
        return $this->transaction(function() use($id,$v,$fields,$details) {
            if ($id) {
                $old=$this->db->prepare('SELECT slug FROM aelvo_products WHERE id=? FOR UPDATE');$old->execute([$id]);$oldSlug=$old->fetchColumn();
                if ($oldSlug===false)throw new InvalidArgumentException('Product no longer exists.');
                $version=Input::integer($v['version']??null);
                $q=$this->db->prepare("UPDATE aelvo_products SET name=?,name_bn=?,slug=?,category=?,description=?,description_bn=?,details=?,price_minor=?,sale_price_minor=?,is_featured=?,is_new_arrival=?,status=?,version=version+1 WHERE id=? AND version=? AND status<>'Archived'");
                $q->execute([...$fields,$id,$version]);
                if ($q->rowCount()!==1) throw new RuntimeException('Product changed. Reload before saving.',409);
                PublicRoutes::assignProduct($this->db,$id,(string)$oldSlug,$fields[2],(int)$this->actor['id']);
                if ($oldSlug!==$fields[2]) $this->audit('seo.product-slug-changed:'.$id);
            } else {
                $q=$this->db->prepare('INSERT INTO aelvo_products(name,name_bn,slug,category,description,description_bn,details,price_minor,sale_price_minor,is_featured,is_new_arrival,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'); $q->execute($fields); $id=(int)$this->db->lastInsertId();
                PublicRoutes::assignProduct($this->db,$id,$fields[2],$fields[2],(int)$this->actor['id']);
            }
            ProductDetails::persist($this->db,$id,$details);
            Taxonomy::linkProduct($this->db,$id,$fields[3],$v);
            $this->audit('product.saved:'.$id); return $id;
        });
    }
    public function archive(int $id): void {
        $this->allow('products','delete');
        $this->transaction(function() use($id) {
            $q=$this->db->prepare("UPDATE aelvo_products SET status='Archived',version=version+1 WHERE id=?"); $q->execute([$id]); $this->audit('product.archived:'.$id);
        });
    }
    public function variant(array $v): int {
        $this->allow('products','edit'); $product=Input::integer($v['product_id']??null);
        $colour=Input::text($v,'colour',80);$hex=Input::text($v,'colour_hex',7);$size=Input::text($v,'size',30);$sku=Input::text($v,'sku',80);
        if (!preg_match('/^#[0-9a-f]{6}$/iD',$hex)) throw new InvalidArgumentException('Select a valid colour.');
        $priceOverride=VariantPricing::override($v['price_override']??'');
        [$material,$style]=ProductDetails::variantAttrs($v);
        return $this->transaction(function() use($product,$colour,$hex,$size,$sku,$priceOverride,$material,$style) {
            $q=$this->db->prepare("SELECT id FROM aelvo_products WHERE id=? AND status<>'Archived' FOR UPDATE"); $q->execute([$product]); if (!$q->fetch()) throw new InvalidArgumentException('Product not found');
            $q=$this->db->prepare('INSERT INTO aelvo_variants(product_id,sku,colour,colour_hex,size,price_override_minor,material,style) VALUES (?,?,?,?,?,?,?,?)'); $q->execute([$product,$sku,$colour,$hex,$size,$priceOverride,$material,$style]);
            $id=(int)$this->db->lastInsertId();
            $q=$this->db->prepare('INSERT INTO aelvo_variant_quarantine(variant_id,quantity) VALUES (?,0)');$q->execute([$id]);
            $this->audit('variant.created:'.$id); return $id;
        });
    }
    /** Price-only edit; historical orders and inventory movements remain unchanged. */
    public function variantPrice(array $v): int {
        $this->allow('products','edit');
        $id=Input::integer($v['variant_id']??null);
        $productId=Input::integer($v['product_id']??null);
        $version=Input::integer($v['pricing_version']??null,1);
        $price=VariantPricing::override($v['price_override']??'');
        $this->transaction(function() use($id,$productId,$version,$price): void {
            $q=$this->db->prepare('SELECT v.id,v.pricing_version FROM aelvo_variants v JOIN aelvo_products p ON p.id=v.product_id WHERE v.id=? AND v.product_id=? AND p.status<>? FOR UPDATE');
            $q->execute([$id,$productId,'Archived']);$row=$q->fetch();
            if(!$row)throw new InvalidArgumentException('Variant/product does not exist.');
            if((int)$row['pricing_version']!==$version)throw new RuntimeException('Variant price changed. Reload before saving.',409);
            $q=$this->db->prepare('UPDATE aelvo_variants SET price_override_minor=?,pricing_version=pricing_version+1 WHERE id=? AND product_id=? AND pricing_version=?');
            $q->execute([$price,$id,$productId,$version]);
            if($q->rowCount()!==1)throw new RuntimeException('Variant price changed. Reload before saving.',409);
            $this->audit('variant.price-changed:'.$id);
        });
        return $productId;
    }
    /** Merchandise-only attributes; stock, price and historical order lines stay immutable. */
    public function variantAttributes(array $v): int {
        $this->allow('products','edit');
        $id=Input::integer($v['variant_id']??null);
        $productId=Input::integer($v['product_id']??null);
        $version=Input::integer($v['attributes_version']??null);
        [$material,$style]=ProductDetails::variantAttrs($v);
        $this->transaction(function() use($id,$productId,$version,$material,$style): void {
            $q=$this->db->prepare("SELECT v.attributes_version FROM aelvo_variants v JOIN aelvo_products p ON p.id=v.product_id WHERE v.id=? AND v.product_id=? AND p.status<>'Archived' FOR UPDATE");
            $q->execute([$id,$productId]);$record=$q->fetch();
            if(!$record)throw new InvalidArgumentException('Variant/product does not exist.');
            if((int)$record['attributes_version']!==$version)throw new RuntimeException('Variant attributes changed. Reload before saving.',409);
            $q=$this->db->prepare('UPDATE aelvo_variants SET material=?,style=?,attributes_version=attributes_version+1 WHERE id=? AND product_id=? AND attributes_version=?');
            $q->execute([$material,$style,$id,$productId,$version]);
            if($q->rowCount()!==1)throw new RuntimeException('Variant attributes changed. Reload before saving.',409);
            $this->audit('variant.attributes-changed:'.$id);
        });
        return $productId;
    }
    public function adjust(array $v): void {
        $this->allow('inventory','edit');$id=Input::integer($v['variant_id']??null);
        $delta=Input::integer($v['delta']??null,-1000000,1000000);if ($delta===0) throw new InvalidArgumentException('Adjustment cannot be zero.');
        $reason=Input::text($v,'explanation',2000);
        $this->transaction(function() use($id,$delta,$reason) {
            $q=$this->db->prepare('SELECT stock,reserved FROM aelvo_variants WHERE id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch();
            $q=$this->db->prepare('SELECT quantity FROM aelvo_variant_quarantine WHERE variant_id=? FOR UPDATE');$q->execute([$id]);$quarantined=(int)($q->fetchColumn()?:0);
            if (!$row || (int)$row['stock']+$delta<(int)$row['reserved']+$quarantined || (int)$row['stock']+$delta>100000000) throw new InvalidArgumentException('Adjustment would invalidate stock or existing reservations.');
            $costing=new InventoryCosting($this->db);
            $costing->ensureCoverage($id,(int)$row['stock']);
            $movementKey=bin2hex(random_bytes(12));
            if($delta>0)$costing->unpricedIncrease($id,$delta,$movementKey);
            else $costing->dispose($id,-$delta,'manual-reduction',$movementKey);
            $q=$this->db->prepare('UPDATE aelvo_variants SET stock=stock+? WHERE id=?');$q->execute([$delta,$id]);
            $q=$this->db->prepare('INSERT INTO aelvo_movements(variant_id,stock_delta,reserved_delta,source_type,source_id,explanation,actor_id) VALUES (?,?,0,?,?,?,?)');
            $q->execute([$id,$delta,'manual-adjustment',$movementKey,$reason,$this->actor['id']]);$this->audit('inventory.adjusted:'.$id);
        });
    }
    /** The outer SQL query always uses aelvo_products as the table name. */
    public static function priceExpression(string $mode='min'): string {
        if(!in_array($mode,['min','max'],true))throw new InvalidArgumentException('Unknown pricing mode.');
        $fn=strtoupper($mode);
        // Match the public price range: prefer sellable active variants, then
        // active out-of-stock variants, then product base (no variants yet).
        $price='COALESCE(vp.price_override_minor,aelvo_products.sale_price_minor,aelvo_products.price_minor)';
        $sellable='(SELECT '.$fn.'('.$price.') FROM aelvo_variants vp LEFT JOIN aelvo_variant_quarantine pq ON pq.variant_id=vp.id WHERE vp.product_id=aelvo_products.id AND vp.active=1 AND vp.stock>vp.reserved+COALESCE(pq.quantity,0))';
        $active='(SELECT '.$fn.'('.$price.') FROM aelvo_variants vp WHERE vp.product_id=aelvo_products.id AND vp.active=1)';
        return 'COALESCE('.$sellable.','.$active.',aelvo_products.sale_price_minor,aelvo_products.price_minor)';
    }
    public static function queryParts(array $filters): array {
        $where=["status='Active'"];$args=[];
        $search=Input::text($filters,'q',180,false);$category=Input::text($filters,'category',80,false);
        if($search!==''){
            $where[]="(name LIKE ? ESCAPE '!' OR name_bn LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM aelvo_product_details pd WHERE pd.product_id=aelvo_products.id AND (pd.parent_sku LIKE ? ESCAPE '!' OR pd.barcode LIKE ? ESCAPE '!' OR pd.tags_csv LIKE ? ESCAPE '!')) OR EXISTS (SELECT 1 FROM aelvo_variants sv WHERE sv.product_id=aelvo_products.id AND sv.sku LIKE ? ESCAPE '!'))";
            $like='%'.strtr($search,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            array_push($args,$like,$like,$like,$like,$like,$like);
        }
        if($category!==''){$where[]='category=?';$args[]=$category;}
        if(($filters['category_id']??'')!==''){
            $where[]='EXISTS (SELECT 1 FROM aelvo_product_taxonomy pt JOIN aelvo_categories c ON c.id=pt.category_id LEFT JOIN aelvo_categories parent ON parent.id=c.parent_id WHERE pt.product_id=aelvo_products.id AND pt.category_id=? AND c.status=? AND (c.parent_id IS NULL OR parent.status=?))';
            $args[]=Input::integer($filters['category_id']);$args[]='Active';$args[]='Active';
        }
        if(($filters['brand_id']??'')!==''){
            $where[]='EXISTS (SELECT 1 FROM aelvo_product_taxonomy pt JOIN aelvo_brands b ON b.id=pt.brand_id WHERE pt.product_id=aelvo_products.id AND pt.brand_id=? AND b.status=?)';
            $args[]=Input::integer($filters['brand_id']);$args[]='Active';
        }
        if(($filters['min']??'')!==''){$where[]=self::priceExpression('max').'>=?';$args[]=Input::money($filters['min']);}
        if(($filters['max']??'')!==''){$where[]=self::priceExpression('min').'<=?';$args[]=Input::money($filters['max']);}
        if(($filters['stock']??'')==='1')$where[]='EXISTS (SELECT 1 FROM aelvo_variants v LEFT JOIN aelvo_variant_quarantine q ON q.variant_id=v.id WHERE v.product_id=aelvo_products.id AND v.active=1 AND v.stock>v.reserved+COALESCE(q.quantity,0))';
        return [implode(' AND ',$where),$args];
    }
    /** Related live products with sellable variants; never return a stale, out-of-stock suggestion. */
    public static function relatedProducts(PDO $db,int $productId,string $category,int $limit=4): array {
        if ($productId<1 || !in_array($category, Taxonomy::ROOTS, true) || $limit<1 || $limit>12) return [];
        $q=$db->prepare("SELECT p.id FROM aelvo_products p WHERE p.status='Active' AND p.id<>? AND p.category=? AND EXISTS (
            SELECT 1 FROM aelvo_variants v LEFT JOIN aelvo_variant_quarantine qu ON qu.variant_id=v.id
            WHERE v.product_id=p.id AND v.active=1 AND v.stock-v.reserved-COALESCE(qu.quantity,0)>0
        ) ORDER BY p.id DESC LIMIT ".$limit);
        $q->execute([$productId,$category]);
        $ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        return $ids?self::publicProducts($db,[], $ids):[];
    }
    public static function count(PDO $db,array $filters=[]): int {
        [$where,$args]=self::queryParts($filters);$q=$db->prepare('SELECT COUNT(*) FROM aelvo_products WHERE '.$where);$q->execute($args);return (int)$q->fetchColumn();
    }
    public static function publicProducts(\PDO $db,array $filters=[],?array $ids=null): array {
        [$where,$args]=self::queryParts($filters);
        if($ids!==null){if(!$ids)return [];$ids=array_values(array_unique(array_map(fn($id)=>Input::integer($id),$ids)));$where.=' AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$args=[...$args,...$ids];}
        $sort=['newest'=>'id DESC','price-low'=>self::priceExpression('min').' ASC,id DESC','price-high'=>self::priceExpression('max').' DESC,id DESC','name'=>'name ASC,id DESC','merchandising'=>'is_featured DESC,is_new_arrival DESC,id DESC'][$filters['sort']??'newest']??'id DESC';
        $page=Input::integer($filters['p']??1,1,1000000);$limit=$ids===null?' LIMIT 24 OFFSET '.(($page-1)*24):'';
        $q=$db->prepare('SELECT id,name,name_bn,slug,category,description,description_bn,details,price_minor,sale_price_minor,is_featured,is_new_arrival, (SELECT c.slug FROM aelvo_product_taxonomy pt JOIN aelvo_categories c ON c.id=pt.category_id WHERE pt.product_id=aelvo_products.id AND c.status=\'Active\') AS category_slug, (SELECT b.name FROM aelvo_product_taxonomy pt JOIN aelvo_brands b ON b.id=pt.brand_id WHERE pt.product_id=aelvo_products.id AND b.status=\'Active\') AS brand_name, (SELECT b.slug FROM aelvo_product_taxonomy pt JOIN aelvo_brands b ON b.id=pt.brand_id WHERE pt.product_id=aelvo_products.id AND b.status=\'Active\') AS brand_slug FROM aelvo_products WHERE '.$where.' ORDER BY '.$sort.$limit);$q->execute($args);$rows=$q->fetchAll();
        if (!$rows) return [];
        $ids=array_column($rows,'id');$marks=implode(',',array_fill(0,count($ids),'?'));
        $details=ProductDetails::forProducts($db,$ids);
        $q=$db->prepare("SELECT v.id,v.product_id,v.sku,v.colour,v.colour_hex,v.size,v.material,v.style,v.price_override_minor,COALESCE(v.price_override_minor,p.price_minor) AS regular_price_minor,COALESCE(v.price_override_minor,p.sale_price_minor,p.price_minor) AS price_minor,GREATEST(v.stock-v.reserved-COALESCE(q.quantity,0),0) AS available FROM aelvo_variants v JOIN aelvo_products p ON p.id=v.product_id LEFT JOIN aelvo_variant_quarantine q ON q.variant_id=v.id WHERE v.active=1 AND v.product_id IN ($marks) ORDER BY v.id");$q->execute($ids);$variants=[];
        foreach($q as $row) $variants[$row['product_id']][]=$row;
        $q=$db->prepare("SELECT pm.product_id,pm.media_id,pm.colour,COALESCE(a.alt_en,'') AS alt_en,COALESCE(a.alt_bn,'') AS alt_bn FROM aelvo_product_media pm JOIN aelvo_media m ON m.id=pm.media_id LEFT JOIN aelvo_product_image_alt a ON a.product_id=pm.product_id AND a.media_id=pm.media_id WHERE m.is_public=1 AND pm.product_id IN ($marks) ORDER BY pm.position,pm.media_id");$q->execute($ids);$images=[];
        foreach($q as $row) $images[$row['product_id']][]=$row;
        foreach($rows as &$row) { $row['variants']=$variants[$row['id']]??[];$row['images']=$images[$row['id']]??[];$row['variant_price_range']=VariantPricing::range((int)$row['price_minor'],$row['variants'],$row['sale_price_minor']===null?null:(int)$row['sale_price_minor']);$row['product_details']=$details[(int)$row['id']]??[]; }unset($row);return $rows;
    }
}
