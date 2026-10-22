<?php
declare(strict_types=1);
namespace Aelvo;
use InvalidArgumentException;
use RuntimeException;
final class Uploads extends Service {
    public function content(array $file): string {
        $this->allow('content','edit');
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('Choose an image or video.');
        $bytes=filesize($file['tmp_name']);if(!$bytes||$bytes>30*1024*1024)throw new InvalidArgumentException('Content media must be under 30 MB.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','video/mp4'=>'mp4','video/webm'=>'webm'];
        if(!isset($types[$mime]))throw new InvalidArgumentException('Use JPEG, PNG, WebP, MP4 or WebM.');
        if(str_starts_with($mime,'image/')&&!getimagesize($file['tmp_name']))throw new InvalidArgumentException('Invalid image.');
        $id=bin2hex(random_bytes(16));$disk=$id.'.'.$types[$mime];$dir=dirname(__DIR__).'/storage/media';
        if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Storage unavailable.');
        $target=$dir.'/'.$disk;if(!move_uploaded_file($file['tmp_name'],$target))throw new RuntimeException('Upload failed.');chmod($target,0600);
        try{return $this->transaction(function()use($id,$disk,$mime,$bytes,$file){
            $q=$this->db->prepare('INSERT INTO aelvo_media(id,disk_name,original_name,mime,bytes,is_public,owner_id) VALUES (?,?,?,?,?,0,?)');$q->execute([$id,$disk,substr(basename((string)($file['name']??'media')),0,180),$mime,$bytes,$this->actor['id']]);
            $q=$this->db->prepare('INSERT INTO aelvo_content_media(media_id) VALUES (?)');$q->execute([$id]);$this->audit('content.media-uploaded:'.$id);return $id;
        });}catch(\Throwable $e){unlink($target);throw $e;}
    }
    public function document(array $file,string $module): string {
        if(!in_array($module,['finance','purchases','returns','supplier-payments','courier-collection','courier-remittance','courier-resolution','courier-exceptions','payments'],true)) throw new InvalidArgumentException('Invalid document module');
        if(in_array($module,['courier-collection','courier-remittance'],true)){
            $this->allow('courier','edit');$this->allow('finance','edit');
        } elseif(in_array($module,['courier-resolution','courier-exceptions'],true)){
            $this->allow('courier','edit');
        } elseif($module==='payments'){
            $this->allow('payments','edit');$this->allow('finance','edit');
        } else $this->allow($module==='supplier-payments'?'purchases':$module,in_array($module,['returns','supplier-payments'],true)?'edit':'create');
        if($module==='supplier-payments')$this->allow('finance','edit');
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('Upload a document');
        $bytes=filesize($file['tmp_name']);if(!$bytes || $bytes>10*1024*1024)throw new InvalidArgumentException('Document must be under 10 MB');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$types=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if(!isset($types[$mime]))throw new InvalidArgumentException('Use PDF, JPEG, PNG or WebP');
        if($mime!=='application/pdf' && !getimagesize($file['tmp_name']))throw new InvalidArgumentException('Invalid image');
        $id=bin2hex(random_bytes(16));$disk=$id.'.'.$types[$mime];$directory=dirname(__DIR__).'/storage/media';
        if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Document storage unavailable');
        $target=$directory.'/'.$disk;if(!move_uploaded_file($file['tmp_name'],$target))throw new RuntimeException('Could not save document');chmod($target,0600);
        try {$q=$this->db->prepare('INSERT INTO aelvo_media(id,disk_name,original_name,mime,bytes,is_public,owner_id) VALUES (?,?,?,?,?,0,?)');$q->execute([$id,$disk,substr(basename((string)($file['name']??'document')),0,180),$mime,$bytes,$this->actor['id']]);return $id;}
        catch(\Throwable $e){unlink($target);throw $e;}
    }
    public function product(array $file,int $product,string $colour): string {
        $this->allow('products','edit');
        if (strlen($colour)>80) throw new InvalidArgumentException('Invalid colour');
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Choose an image to upload.');
        $bytes=filesize($file['tmp_name']);
        if ($bytes===false || $bytes<1 || $bytes>10*1024*1024) throw new InvalidArgumentException('Image must be under 10 MB.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!isset($types[$mime]) || !getimagesize($file['tmp_name'])) throw new InvalidArgumentException('Use a valid JPEG, PNG or WebP image.');
        $id=bin2hex(random_bytes(16)); $disk=$id.'.'.$types[$mime];$directory=dirname(__DIR__).'/storage/media';
        if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('Upload directory unavailable');
        $target=$directory.'/'.$disk;
        if (!move_uploaded_file($file['tmp_name'],$target)) throw new RuntimeException('Upload could not be saved');
        chmod($target,0600);
        try {
            return $this->transaction(function() use($file,$product,$colour,$id,$disk,$mime,$bytes) {
                $q=$this->db->prepare("SELECT id FROM aelvo_products WHERE id=? AND status<>'Archived' FOR UPDATE");$q->execute([$product]);if (!$q->fetch()) throw new InvalidArgumentException('Product not found');
                if($colour!=='') { $q=$this->db->prepare('SELECT id FROM aelvo_variants WHERE product_id=? AND colour=? LIMIT 1');$q->execute([$product,$colour]);if (!$q->fetch()) throw new InvalidArgumentException('Create the colour variant before uploading its image.'); }
                $name=substr(basename((string)($file['name']??'image')),0,180);
                $q=$this->db->prepare('INSERT INTO aelvo_media(id,disk_name,original_name,mime,bytes,is_public,owner_id) VALUES (?,?,?,?,?,1,?)');$q->execute([$id,$disk,$name,$mime,$bytes,$this->actor['id']]);
                $q=$this->db->prepare('INSERT INTO aelvo_product_media(product_id,media_id,colour,position) SELECT ?,?,?,COALESCE(MAX(position),-1)+1 FROM aelvo_product_media WHERE product_id=?');$q->execute([$product,$id,$colour,$product]);$this->audit('product.image-added:'.$product);return $id;
            });
        } catch(\Throwable $e) { unlink($target);throw $e; }
    }
    /** Make this already-owned photo the primary image. Never modify other products' media. */
    public function makePrimary(array $data): int {
        $this->allow('products','edit');
        $product=Input::integer($data['product_id']??null);
        $version=Input::integer($data['version']??null,1);
        $media=Input::text($data,'media_id',32);
        if(!preg_match('/^[a-f0-9]{32}$/D',$media))throw new InvalidArgumentException('Invalid image identifier.');
        return $this->transaction(function()use($product,$version,$media):int {
            $q=$this->db->prepare("SELECT version FROM aelvo_products WHERE id=? AND status<>'Archived' FOR UPDATE");$q->execute([$product]);
            $current=$q->fetchColumn();
            if($current===false)throw new InvalidArgumentException('Product not found.');
            if((int)$current!==$version)throw new RuntimeException('Product changed. Reload before changing its photos.',409);
            $q=$this->db->prepare('SELECT media_id FROM aelvo_product_media WHERE product_id=? AND media_id=? FOR UPDATE');
            $q->execute([$product,$media]);if(!$q->fetch())throw new InvalidArgumentException('Photo does not belong to this product.');
            $this->db->prepare('UPDATE aelvo_product_media SET position=position+1 WHERE product_id=?')->execute([$product]);
            $this->db->prepare('UPDATE aelvo_product_media SET position=0 WHERE product_id=? AND media_id=?')->execute([$product,$media]);
            $q=$this->db->prepare('UPDATE aelvo_products SET version=version+1 WHERE id=? AND version=?');$q->execute([$product,$version]);
            if($q->rowCount()!==1)throw new RuntimeException('Product changed. Reload its gallery.',409);
            $this->audit('product.primary-image-changed:'.$product);return $product;
        });
    }

}
