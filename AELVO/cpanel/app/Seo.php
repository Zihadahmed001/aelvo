<?php
declare(strict_types=1);
namespace Aelvo;
use PDO;
use InvalidArgumentException;
final class Seo extends Service {
    public static function config(PDO $db): array {return Settings::get($db,'seo')['value'];}
    public function save(array $v): void {
        $this->allow('seo','edit');
        $base=rtrim(Input::text($v,'base_url',300),'/');$parts=parse_url($base);
        if(!$parts||($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'],$parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||preg_match('/[\s<>"\x00-\x1f]/',$base))throw new InvalidArgumentException('Use the HTTPS public directory URL without query or fragment.');
        if(isset($parts['user'])||isset($parts['pass']))throw new InvalidArgumentException('Credentials cannot appear in the site URL.');
        $data=['base_url'=>$base,'title'=>Input::text($v,'title',180),'description'=>Input::text($v,'description',600,false),'indexing'=>($v['indexing']??'0')==='1'];
        $version=Input::integer($v['version']??0,0);
        $this->transaction(function()use($data,$version){$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if(!$version){$q=$this->db->prepare("INSERT INTO aelvo_settings(setting_key,payload) VALUES ('seo',?)");$q->execute([$json]);}
            else{$q=$this->db->prepare("UPDATE aelvo_settings SET payload=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE setting_key='seo' AND version=?");$q->execute([$json,$version]);if($q->rowCount()!==1)throw new \RuntimeException('SEO settings changed. Reload first.',409);}$this->audit('seo.global-saved');
        });
    }
    /** Effective optional overrides remain separate from catalogue defaults. */
    public static function product(PDO $db,int $id): array {
        $q=$db->prepare('SELECT * FROM aelvo_product_seo WHERE product_id=?');
        $q->execute([$id]);
        return $q->fetch()?:['product_id'=>$id,'title'=>'','description'=>'',
            'canonical_path'=>'','og_title'=>'','og_description'=>'','og_image_id'=>null,
            'index_mode'=>'inherit','follow_mode'=>'inherit','version'=>0];
    }
    public function saveProduct(array $v): void {
        $this->allow('seo','edit');
        $id=Input::integer($v['product_id']??null);
        $version=Input::integer($v['version']??0,0);
        $data=[];
        foreach(['title'=>180,'description'=>600,'og_title'=>180,'og_description'=>600] as $key=>$max)
            $data[$key]=Input::text($v,$key,$max,false);
        $data['canonical_path']=Input::text($v,'canonical_path',220,false);
        if($data['canonical_path']!=='')$data['canonical_path']=PublicRoutes::path($data['canonical_path']);
        foreach(['index_mode'=>['inherit','index','noindex'],'follow_mode'=>['inherit','follow','nofollow']] as $key=>$options) {
            $data[$key]=Input::text($v,$key,10);
            if(!in_array($data[$key],$options,true))throw new InvalidArgumentException('Invalid SEO robots selection.');
        }
        $data['og_image_id']=Input::text($v,'og_image_id',32,false);
        if($data['og_image_id']!=='' && !preg_match('/^[a-f0-9]{32}$/D',$data['og_image_id']))
            throw new InvalidArgumentException('Choose a valid product photo ID.');
        $this->transaction(function() use($id,$version,$data): void {
            $q=$this->db->prepare("SELECT id,slug,status FROM aelvo_products WHERE id=? FOR UPDATE");
            $q->execute([$id]);$product=$q->fetch();
            if(!$product || $product['status']==='Archived')throw new InvalidArgumentException('Product is unavailable.');
            if($data['canonical_path']!=='') {
                $route=PublicRoutes::resolve($this->db,$data['canonical_path']);
                if(!$route || $route['type']!=='product')throw new InvalidArgumentException('Canonical must target an active, current AELVO product URL.');
            }
            if($data['og_image_id']!=='') {
                $q=$this->db->prepare('SELECT 1 FROM aelvo_product_media pm JOIN aelvo_media m ON m.id=pm.media_id WHERE pm.product_id=? AND pm.media_id=? AND m.is_public=1');
                $q->execute([$id,$data['og_image_id']]);
                if(!$q->fetchColumn())throw new InvalidArgumentException('Social image must be an approved public photo of this product.');
            }
            $fields=['title','description','canonical_path','og_title','og_description','og_image_id','index_mode','follow_mode'];
            $values=array_map(fn($key)=>$key==='og_image_id'&&$data[$key]===''?null:$data[$key],$fields);
            if($version===0){
                $q=$this->db->prepare('INSERT INTO aelvo_product_seo(product_id,'.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields)+1,'?')).')');
                $q->execute([$id,...$values]);
            } else {
                $q=$this->db->prepare('UPDATE aelvo_product_seo SET '.implode(',',array_map(fn($key)=>$key.'=?',$fields)).',version=version+1 WHERE product_id=? AND version=?');
                $q->execute([...$values,$id,$version]);
                if($q->rowCount()!==1)throw new \RuntimeException('SEO settings changed. Reload before saving.',409);
            }
            $this->audit('seo.product-saved:'.$id);
        });
    }
    public static function imageAlt(PDO $db,int $productId,string $mediaId,string $language,string $fallback): string {
        $q=$db->prepare('SELECT alt_en,alt_bn FROM aelvo_product_image_alt WHERE product_id=? AND media_id=?');
        $q->execute([$productId,$mediaId]);$row=$q->fetch();
        return $row&&($language==='bn'?($row['alt_bn']?:$row['alt_en']):$row['alt_en'])!==''
            ?($language==='bn'?($row['alt_bn']?:$row['alt_en']):$row['alt_en']):$fallback;
    }
    public function saveImageAlt(array $v): void {
        $this->allow('seo','edit');
        $id=Input::integer($v['product_id']??null);
        $media=Input::text($v,'media_id',32);
        if(!preg_match('/^[a-f0-9]{32}$/D',$media))throw new InvalidArgumentException('Invalid image ID.');
        $en=Input::text($v,'alt_en',255,false);$bn=Input::text($v,'alt_bn',255,false);
        $version=Input::integer($v['version']??0,0);
        $this->transaction(function() use($id,$media,$en,$bn,$version): void {
            $q=$this->db->prepare('SELECT 1 FROM aelvo_product_media WHERE product_id=? AND media_id=?');
            $q->execute([$id,$media]);if(!$q->fetchColumn())throw new InvalidArgumentException('Photo is not assigned to this product.');
            if($version===0){
                $q=$this->db->prepare('INSERT INTO aelvo_product_image_alt(product_id,media_id,alt_en,alt_bn) VALUES (?,?,?,?)');
                $q->execute([$id,$media,$en,$bn]);
            } else {
                $q=$this->db->prepare('UPDATE aelvo_product_image_alt SET alt_en=?,alt_bn=?,version=version+1 WHERE product_id=? AND media_id=? AND version=?');
                $q->execute([$en,$bn,$id,$media,$version]);
                if($q->rowCount()!==1)throw new \RuntimeException('ALT text was changed. Reload first.',409);
            }
            $this->audit('seo.image-alt-saved:'.$id);
        });
    }
    /**
     * A bounded, privacy-minimizing 404 aggregator. Path values are constructed
     * from known route tokens; never log a raw URI, query string, host or IP.
     */
    public static function record404(PDO $db, string $route): void {
        if (!preg_match('/^[a-zA-Z0-9_./=?&-]{1,160}$/D', $route)) return;
        // Cap new distinct paths to prevent unbounded hostile cardinality.
        // Existing paths can still update their counters after reaching the cap.
        $q=$db->prepare('SELECT id FROM aelvo_seo_404 WHERE path=?');$q->execute([$route]);
        if (!$q->fetchColumn()) {
            // Opportunistic pruning, no scheduler/cron needed on shared hosting.
            if (random_int(1,100)===1) $db->exec('DELETE FROM aelvo_seo_404 WHERE last_seen_at < UTC_TIMESTAMP()-INTERVAL 90 DAY AND reviewed_at IS NOT NULL LIMIT 50');
            if ((int)$db->query('SELECT COUNT(*) FROM aelvo_seo_404')->fetchColumn()>=5000) return;
        }
        $q=$db->prepare('INSERT INTO aelvo_seo_404(path) VALUES (?) ON DUPLICATE KEY UPDATE hits=LEAST(hits+1,4294967295),last_seen_at=UTC_TIMESTAMP()');
        $q->execute([$route]);
    }
    public function review404(array $v): void {
        $this->allow('seo','edit');
        $id=Input::integer($v['id']??null);$version=Input::integer($v['version']??null);
        $this->transaction(function()use($id,$version){
            $q=$this->db->prepare('UPDATE aelvo_seo_404 SET reviewed_at=UTC_TIMESTAMP(),reviewed_by=?,version=version+1 WHERE id=? AND version=?');
            $q->execute([$this->actor['id'],$id,$version]);
            if($q->rowCount()!==1)throw new \RuntimeException('404 entry changed or no longer exists. Reload before reviewing.',409);
            $this->audit('seo.404-reviewed:'.$id);
        });
    }
    /** Per-entity overrides are never allowed to turn a globally noindex site on. */
    public static function metadata(PDO $db,string $page,?array $product,string $language,array $filters=[],?array $taxonomy=null,?array $cmsPage=null): array {
        $v=self::config($db);
        $siteTitle=(string)($v['title']??'AELVO Fashion');
        $title=$siteTitle;
        $description=(string)($v['description']??'');
        $path='';
        $index=($v['indexing']??false)===true;
        $follow=true;
        $canonical=true;
        $ogTitle='';$ogDescription='';$ogImage='';$ogType='website';
        $base=rtrim((string)($v['base_url']??''),'/');
        if($page==='product') {
            if(!$product){$index=false;$follow=false;$canonical=false;}
            else {
                $title=($language==='bn'&&$product['name_bn']!==''?$product['name_bn']:$product['name']).' | AELVO';
                $description=$language==='bn'&&$product['description_bn']!==''?$product['description_bn']:$product['description'];
                $path=PublicRoutes::productPath($product['slug']);
                $override=self::product($db,(int)$product['id']);
                if($override['title']!=='')$title=$override['title'];
                if($override['description']!=='')$description=$override['description'];
                if($override['index_mode']==='noindex')$index=false;
                if($override['follow_mode']==='nofollow')$follow=false;
                if($override['follow_mode']==='follow')$follow=true;
                if($override['canonical_path']!=='') {
                    $route=PublicRoutes::resolve($db,$override['canonical_path']);
                    if($route&&$route['type']==='product')$path=$override['canonical_path'];
                    else {$index=false;$canonical=false;}
                }
                $ogTitle=$override['og_title'];$ogDescription=$override['og_description'];$ogType='product';
                $availableImages=array_column($product['images']??[],'media_id');
                $mediaId=$override['og_image_id']!==null && in_array($override['og_image_id'],$availableImages,true)?$override['og_image_id']:($availableImages[0]??'');
                if($base!==''&&$mediaId!==''&&preg_match('/^[a-f0-9]{32}$/D',$mediaId))$ogImage=$base.'/media.php?id='.$mediaId;
            }
        } elseif(in_array($page,['category','brand'],true)){
            if(!$taxonomy || $taxonomy['status']!=='Active'){$index=false;$follow=false;$canonical=false;}
            else {
                $name=$language==='bn'&&$taxonomy['name_bn']!==''?$taxonomy['name_bn']:$taxonomy['name'];
                $title=$taxonomy['seo_title']!==''?$taxonomy['seo_title']:$name.' | AELVO';
                $fallbackDescription=$language==='bn'&&$taxonomy['description_bn']!==''?$taxonomy['description_bn']:$taxonomy['description'];
                $description=$taxonomy['seo_description']!==''?$taxonomy['seo_description']:$fallbackDescription;
                $path=PublicRoutes::entityPath($page,$taxonomy['slug']);
                $pagination=Input::integer($filters['p']??1,1,1000000);
                if($pagination===1 && $taxonomy['canonical_path']!==''){
                    $target=PublicRoutes::resolve($db,$taxonomy['canonical_path']);
                    if($target && $target['type']===$page)$path=$taxonomy['canonical_path'];
                    else {$index=false;$canonical=false;}
                }
                if($base!==''&&$taxonomy['og_image_id']!==null)$ogImage=$base.'/media.php?id='.$taxonomy['og_image_id'];
                if($pagination>1)$path.='?p='.$pagination;
                if($taxonomy['index_mode']==='noindex')$index=false;
                if($taxonomy['follow_mode']==='nofollow')$follow=false;
                $ogTitle=$taxonomy['og_title'];$ogDescription=$taxonomy['og_description'];
            }
        } elseif ($page==='cms') {
            if(!$cmsPage){$index=false;$follow=false;$canonical=false;}
            else{
                $content=$cmsPage['value'];
                $heading=$language==='bn'&&($content['heading_bn']??'')!==''?$content['heading_bn']:$content['heading'];
                $body=$language==='bn'&&($content['body_bn']??'')!==''?$content['body_bn']:$content['body'];
                $title=$content['seo_title']!==''?$content['seo_title']:$heading.' | AELVO';
                $description=$content['seo_description']!==''?$content['seo_description']:self::summary($body!==''?$body:CmsBlocks::plainText($content['blocks']??[],$language));
                $path=CmsPages::path($cmsPage['slug']);
                if(($content['index_mode']??'index')==='noindex')$index=false;
                if(($content['follow_mode']??'follow')==='nofollow')$follow=false;
                $ogTitle=$content['og_title']??'';
                $ogDescription=$content['og_description']??'';
            }
        } elseif ($page==='shop') {
            $title='Shop | '.$title;$path='store.php?page=shop';
            $facet=$filters;unset($facet['p']);
            foreach($facet as $key=>$value)if($value==='')unset($facet[$key]);
            if($facet){$index=false;$canonical=false;}
            else{$pagination=(int)($filters['p']??1);if($pagination>1)$path.='&p='.$pagination;}
        } elseif ($page==='info') {
            $key=is_string($_GET['key']??null)?$_GET['key']:'';
            $content=in_array($key,Content::KEYS,true)?Content::get($db,$key)['value']:[];
            $path='store.php?page=info&key='.rawurlencode($key);
            $index=$index&&Content::active($content);
            if($content){
                $heading=$language==='bn'&&($content['heading_bn']??'')!==''?$content['heading_bn']:($content['heading']??'');
                $body=$language==='bn'&&($content['body_bn']??'')!==''?$content['body_bn']:($content['body']??'');
                $title=($content['seo_title']??'')!==''?$content['seo_title']:$heading.' | AELVO';
                $description=($content['seo_description']??'')!==''?$content['seo_description']:self::summary($body);
                if(($content['index_mode']??'index')==='noindex')$index=false;
                if(($content['follow_mode']??'follow')==='nofollow')$follow=false;
                $ogTitle=$content['og_title']??'';$ogDescription=$content['og_description']??'';
            }
        } elseif($page!=='home'){$index=false;$follow=false;$canonical=false;}
        if($page==='not-found'){$title='Page not found | AELVO Fashion';$description='';}
        $url=!$canonical||$base===''?'':$base.'/'.($path===''?'':$path);
        return ['title'=>$title,'description'=>$description,'canonical'=>$url,
            'robots'=>($index?'index':'noindex').','.($follow?'follow':'nofollow'),
            'og_title'=>$ogTitle?:$title,'og_description'=>$ogDescription?:$description,
            'og_image'=>$ogImage,'og_type'=>$ogType,'og_url'=>$url];
    }
    public static function summary(string $body): string {
        preg_match('/^.{0,300}/us',trim($body),$match);
        return trim($match[0]??'');
    }
    /** Serialize only factual, server-visible product details. Never invent ratings. */
    public static function productStructuredData(array $product,array $meta,string $base): string {
        $base=rtrim($base,'/');
        if($base===''||$meta['canonical']===''||($meta['og_type']??'')!=='product')return '';
        $image=$meta['og_image'];
        $inStock=false;$sku='';
        foreach($product['variants']??[] as $variant){
            if($sku==='')$sku=(string)$variant['sku'];
            if((int)$variant['available']>0)$inStock=true;
        }
        $data=['@context'=>'https://schema.org','@type'=>'Product',
            'name'=>(string)$product['name'],'description'=>(string)$product['description'],
            'url'=>$meta['canonical']];
        if($sku!=='')$data['sku']=$sku;
        if(!empty($product['brand_name']))$data['brand']=['@type'=>'Brand','name'=>$product['brand_name']];
        if($image!=='')$data['image']=[$image];
        $range=$product['variant_price_range']??VariantPricing::range((int)$product['price_minor'],$product['variants']??[]);
        if($range['min']!==$range['max']){
            $data['offers']=['@type'=>'AggregateOffer','url'=>$meta['canonical'],
                'priceCurrency'=>'BDT',
                'lowPrice'=>number_format($range['min']/100,2,'.',''),
                'highPrice'=>number_format($range['max']/100,2,'.',''),
                'offerCount'=>count(array_filter($product['variants']??[],static fn(array $v):bool=>(int)($v['available']??0)>0)),
                'availability'=>$inStock?'https://schema.org/InStock':'https://schema.org/OutOfStock'];
        }else{
            $data['offers']=['@type'=>'Offer','url'=>$meta['canonical'],
                'priceCurrency'=>'BDT','price'=>number_format($range['min']/100,2,'.',''),
                'availability'=>$inStock?'https://schema.org/InStock':'https://schema.org/OutOfStock'];
        }
        return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
    }
}
