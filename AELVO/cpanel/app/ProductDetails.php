<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use PDO;

/** Optional, merchant-supplied product facts. No invented labels or costs. */
final class ProductDetails {
    public static function nullableCode(array $data,string $key,int $max=80): ?string {
        $value=Input::text($data,$key,$max,false);
        if($value==='')return null;
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/D',$value))
            throw new InvalidArgumentException('Use letters, digits, dot, slash, dash or underscore for '.$key.'.');
        return $value;
    }
    public static function optionalPositive(array $data,string $key,int $max): ?int {
        $raw=$data[$key]??'';
        if($raw===null || $raw==='')return null;
        return Input::integer($raw,1,$max);
    }
    public static function tags(array $data): string {
        $raw=Input::text($data,'tags',800,false);
        if($raw==='')return '';
        $parts=array_map('trim',explode(',',$raw));
        if(count($parts)>12)throw new InvalidArgumentException('Use at most 12 product tags.');
        $tags=[];$seen=[];
        foreach($parts as $tag){
            if($tag==='' || strlen($tag)>60 || preg_match('/[\x00-\x1f\x7f<>]/',$tag))
                throw new InvalidArgumentException('Product tags must be 1–60 bytes and contain no markup.');
            $key=function_exists('mb_strtolower')?mb_strtolower($tag,'UTF-8'):strtolower($tag);
            if(isset($seen[$key]))throw new InvalidArgumentException('Duplicate product tag.');
            $seen[$key]=true;$tags[]=$tag;
        }
        return implode(', ',$tags);
    }
    public static function parse(array $data): array {
        $dimensions=[];
        foreach(['length_mm','width_mm','height_mm'] as $key)$dimensions[$key]=self::optionalPositive($data,$key,20000);
        $defined=count(array_filter($dimensions,static fn($v)=>$v!==null));
        if($defined!==0 && $defined!==3)throw new InvalidArgumentException('Enter all three dimensions in millimetres, or leave them all blank.');
        return [
            'parent_sku'=>self::nullableCode($data,'parent_sku'),
            'barcode'=>self::nullableCode($data,'barcode'),
            'short_en'=>Input::text($data,'short_en',600,false),
            'short_bn'=>Input::text($data,'short_bn',600,false),
            'material_en'=>Input::text($data,'material_en',160,false),
            'material_bn'=>Input::text($data,'material_bn',160,false),
            'care_en'=>Input::text($data,'care_en',1000,false),
            'care_bn'=>Input::text($data,'care_bn',1000,false),
            'weight_grams'=>self::optionalPositive($data,'weight_grams',1000000),
            ...$dimensions,'tags_csv'=>self::tags($data),
            'low_stock_alert'=>Input::integer($data['low_stock_alert']??'5',0,1000000),
        ];
    }
    public static function variantAttrs(array $data): array {
        return [Input::text($data,'material',100,false),Input::text($data,'style',100,false)];
    }
    /** Must be called INSIDE the already protected product-save SQL transaction. */
    public static function persist(PDO $db,int $productId,array $details): void {
        $keys=array_keys($details);
        // IMPORTANT: Do not use ON DUPLICATE KEY UPDATE: it could match a
        // different product via the unique parent_sku/barcode and overwrite it.
        $lock=$db->prepare('SELECT product_id FROM aelvo_product_details WHERE product_id=? FOR UPDATE');
        $lock->execute([$productId]);
        if($lock->fetchColumn()!==false){
            $set=implode(',',array_map(static fn($key)=>$key.'=?',$keys));
            $q=$db->prepare('UPDATE aelvo_product_details SET '.$set.' WHERE product_id=?');
            $q->execute([...array_values($details),$productId]);
        }else{
            $columns=implode(',',$keys);
            $params=implode(',',array_fill(0,count($keys)+1,'?'));
            $q=$db->prepare('INSERT INTO aelvo_product_details (product_id,'.$columns.') VALUES ('.$params.')');
            $q->execute([$productId,...array_values($details)]);
        }
    }
    public static function forProducts(PDO $db,array $ids): array {
        if(!$ids)return [];
        $ids=array_values(array_unique(array_map('intval',$ids)));
        $q=$db->prepare('SELECT * FROM aelvo_product_details WHERE product_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
        $q->execute($ids);
        $out=[];foreach($q as $row)$out[(int)$row['product_id']]=$row;
        return $out;
    }
}
