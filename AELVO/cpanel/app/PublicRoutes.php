<?php
declare(strict_types=1);
namespace Aelvo;
use PDO;
use InvalidArgumentException;
use RuntimeException;

/** Persistent, exact-match, same-site URL ownership. Never accepts regex or external URLs. */
final class PublicRoutes extends Service {
    public static function productPath(string $slug): string {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug) || strlen($slug)>180) {
            throw new InvalidArgumentException('Invalid product URL slug.');
        }
        return 'product/'.$slug;
    }
    public static function entityPath(string $kind,string $slug): string {
        if (!in_array($kind,['product','category','brand','cms'],true) ||
            !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug) || strlen($slug)>180) {
            throw new InvalidArgumentException('Choose a lowercase, SEO-safe URL slug.');
        }
        return ($kind==='cms'?'pages':$kind).'/'.$slug;
    }
    public static function path(string $input): string {
        if (strlen($input)>220 || !preg_match('~^/?[a-z0-9]+(?:[a-z0-9/_-]*[a-z0-9])?/?$~D',$input)) {
            throw new InvalidArgumentException('Use a lowercase local path without query, fragment or protocol.');
        }
        $path=trim($input,'/');
        if (strlen($path)>200 || str_contains($path,'//') || str_contains($path,'..')) {
            throw new InvalidArgumentException('Invalid URL path.');
        }
        $first=explode('/',$path,2)[0];
        if (in_array($first,['admin','account','api','assets','bin','cart','checkout','config','database','index.php','install','login','media.php','robots.txt','search','setup.php','sitemap.xml','store.php','storage','tests','theme.php','wishlist'],true)) {
            throw new InvalidArgumentException('This path is reserved by the application.');
        }
        return $path;
    }
    /** Use the executable's script directory, never a user-controlled Host header. */
    public static function localUrl(string $path=''): string {
        $script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??'/store.php'));
        $prefix=rtrim(str_replace('\\','/',dirname($script)),'/');
        $prefix=$prefix==='.'?'':$prefix;
        return $prefix.'/'.ltrim($path,'/');
    }
    public static function lookup(PDO $db,string $path): ?array {
        $q=$db->prepare('SELECT path,route_kind,entity_type,entity_id,destination_path,enabled FROM aelvo_public_routes WHERE path=?');
        $q->execute([$path]);return $q->fetch()?:null;
    }
    public static function liveProduct(PDO $db,int $id): ?array {
        $q=$db->prepare("SELECT id,slug FROM aelvo_products WHERE id=? AND status='Active' LIMIT 1");
        $q->execute([$id]);return $q->fetch()?:null;
    }
    private static function liveEntity(PDO $db,string $kind,int $id): ?array {
        if($kind==='product')return self::liveProduct($db,$id);
        if($kind==='cms') {
            $page=CmsPages::published($db,$id);
            return $page?['id'=>$page['id'],'slug'=>$page['slug']]:null;
        }
        if(!in_array($kind,['category','brand'],true))return null;
        $q=$db->prepare('SELECT id,slug FROM '.($kind==='category'?'aelvo_categories':'aelvo_brands').' WHERE id=? AND status=?');
        $q->execute([$id,'Active']);return $q->fetch()?:null;
    }
    /** Resolve only a verified current owner, or a direct permanent redirect to one. */
    public static function resolve(PDO $db,string $path): ?array {
        $route=self::lookup($db,$path);
        if (!$route || !(int)$route['enabled'])return null;
        if($route['route_kind']==='current'){
            $kind=$route['entity_type'];
            $record=self::liveEntity($db,$kind,(int)$route['entity_id']);
            return $record && self::entityPath($kind,$record['slug'])===$path
                ?['type'=>$kind,'id'=>(int)$record['id']]:null;
        }
        if ($route['route_kind']!=='redirect' || !$route['destination_path'])return null;
        $target=self::lookup($db,$route['destination_path']);
        if (!$target || $target['route_kind']!=='current' || !(int)$target['enabled'])return null;
        $kind=$target['entity_type'];
        $record=self::liveEntity($db,$kind,(int)$target['entity_id']);
        if (!$record || self::entityPath($kind,$record['slug'])!==$target['path'])return null;
        return ['type'=>'redirect','destination'=>$target['path']];
    }
    public static function assignProduct(PDO $db,int $id,string $oldSlug,string $newSlug,int $actorId): void {
        self::assignEntity($db,'product',$id,$oldSlug,$newSlug,$actorId);
    }
    /** A slug change and its route ownership must commit atomically. Old names stay reserved. */
    public static function assignEntity(PDO $db,string $kind,int $id,string $oldSlug,string $newSlug,int $actorId): void {
        if (!$db->inTransaction())throw new RuntimeException('URL changes require a transaction.');
        $old=self::entityPath($kind,$oldSlug);$new=self::entityPath($kind,$newSlug);
        $owner=$kind.':'.$id;
        $q=$db->prepare('SELECT * FROM aelvo_public_routes WHERE current_owner=? FOR UPDATE');
        $q->execute([$owner]);$current=$q->fetch();
        if (!$current) {
            // The first assignment also supports legacy rows predating this schema.
            $q=$db->prepare("INSERT INTO aelvo_public_routes(path,route_kind,entity_type,entity_id,current_owner,created_by) VALUES (?,'current',?,?,?,?)");
            $q->execute([$old,$kind,$id,$owner,$actorId]);
        } elseif ($current['path']!==$old || $current['entity_type']!==$kind) {
            throw new RuntimeException('Public URL ownership needs review before editing.',409);
        }
        if($old===$new)return;
        if(self::lookup($db,$new)!==null)throw new InvalidArgumentException('This URL has been used or reserved. Choose a new slug.');
        $q=$db->prepare("UPDATE aelvo_public_routes SET route_kind='redirect',current_owner=NULL,destination_path=? WHERE path=? AND current_owner=?");
        $q->execute([$new,$old,$owner]);
        if($q->rowCount()!==1)throw new RuntimeException('URL changed concurrently. Retry.',409);
        // Redirect history always reaches the current path, never a chain.
        $q=$db->prepare("UPDATE aelvo_public_routes SET destination_path=? WHERE route_kind='redirect' AND destination_path=?");
        $q->execute([$new,$old]);
        $q=$db->prepare("INSERT INTO aelvo_public_routes(path,route_kind,entity_type,entity_id,current_owner,created_by) VALUES (?,'current',?,?,?,?)");
        $q->execute([$new,$kind,$id,$owner,$actorId]);
    }
    /** Manual aliases point only to current public catalogue paths. */
    public function addRedirect(array $v): void {
        $this->allow('seo','edit');
        $source=self::path(Input::text($v,'source',220));
        $dest=self::path(Input::text($v,'destination',220));
        if ($source===$dest)throw new InvalidArgumentException('A redirect cannot point to itself.');
        $this->transaction(function()use($source,$dest){
            if (self::lookup($this->db,$source))throw new InvalidArgumentException('This URL already belongs to another route or redirect.');
            $resolved=self::resolve($this->db,$dest);
            if (!$resolved || !in_array($resolved['type'],['product','category','brand','cms'],true))throw new InvalidArgumentException('Destination must be a currently published canonical URL.');
            $q=$this->db->prepare("INSERT INTO aelvo_public_routes(path,route_kind,entity_type,destination_path,created_by) VALUES (?,'redirect','manual',?,?)");
            $q->execute([$source,$dest,$this->actor['id']]);
            $this->audit('seo.redirect-created');
        });
    }
    public function toggleRedirect(array $v): void {
        $this->allow('seo','edit');
        $path=self::path(Input::text($v,'source',220));
        $enabled=($v['enabled']??'')==='1';
        $this->transaction(function()use($path,$enabled){
            $q=$this->db->prepare("SELECT destination_path,enabled FROM aelvo_public_routes WHERE path=? AND route_kind='redirect' AND entity_type='manual' FOR UPDATE");
            $q->execute([$path]);$row=$q->fetch();
            if (!$row)throw new InvalidArgumentException('Manual redirect not found.');
            if ($enabled) {
                $dest=self::resolve($this->db,$row['destination_path']);
                if (!$dest || !in_array($dest['type'],['product','category','brand','cms'],true))throw new InvalidArgumentException('Destination is no longer a published canonical URL.');
            }
            $q=$this->db->prepare('UPDATE aelvo_public_routes SET enabled=? WHERE path=?');$q->execute([(int)$enabled,$path]);
            $this->audit('seo.redirect-'.($enabled?'enabled':'disabled'));
        });
    }
}
