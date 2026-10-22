<?php
declare(strict_types=1);
namespace Aelvo;
use PDO;
use InvalidArgumentException;
use RuntimeException;

/** Additional bilingual content pages: isolated drafts and explicit public releases. */
final class CmsPages extends Service {
    public static function slug(string $slug): string {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) || strlen($slug)>180)
            throw new InvalidArgumentException('Choose a lowercase, hyphen-separated page slug.');
        return $slug;
    }
    public static function path(string $slug): string { return 'pages/'.self::slug($slug); }
    public static function draft(PDO $db,int $id): ?array {
        $q=$db->prepare('SELECT * FROM aelvo_cms_pages WHERE id=?');$q->execute([$id]);
        return $q->fetch()?:null;
    }
    public static function published(PDO $db,int $id): ?array {
        $q=$db->prepare('SELECT id,published_slug,published,published_version FROM aelvo_cms_pages WHERE id=?');
        $q->execute([$id]);$row=$q->fetch();
        if (!$row || !$row['published_slug'] || !$row['published'])return null;
        $data=json_decode($row['published'],true,32,JSON_THROW_ON_ERROR);
        if (!is_array($data) || !Content::active($data))return null;
        return ['id'=>(int)$row['id'],'slug'=>$row['published_slug'],'value'=>$data,'published_version'=>$row['published_version']];
    }
    /** Stream only released public snapshots; private drafts never leave Admin. */
    public static function publishedPages(PDO $db): \Generator {
        $q=$db->query('SELECT id,published_slug,published,published_version FROM aelvo_cms_pages WHERE published IS NOT NULL AND published_slug IS NOT NULL ORDER BY id');
        foreach($q as $row){
            $data=json_decode($row['published'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($data) || !Content::active($data))continue;
            yield ['id'=>(int)$row['id'],'slug'=>$row['published_slug'],'value'=>$data,'published_version'=>$row['published_version']];
        }
    }
    /** Enforce both a published revision and enabled visibility for public image reads. */
    public static function publishedMedia(PDO $db,string $mediaId): bool {
        $q=$db->prepare('SELECT p.id,p.published FROM aelvo_cms_published_media cm JOIN aelvo_cms_pages p ON p.id=cm.page_id WHERE cm.media_id=? AND p.published IS NOT NULL');
        $q->execute([$mediaId]);
        foreach($q as $page){
            $published=json_decode($page['published'],true,32,JSON_THROW_ON_ERROR);
            if(is_array($published) && Content::active($published))return true;
        }
        return false;
    }
    public static function navigation(PDO $db,int $limit=20): array {
        $result=[];
        foreach(self::publishedPages($db) as $page){
            if(empty($page['value']['show_footer']))continue;
            $result[]=$page;
            if(count($result)>=max(1,min($limit,50)))break;
        }
        return $result;
    }
    private static function payload(array $v): array {
        $slug=self::slug(Input::text($v,'slug',180));
        $data=['slug'=>$slug];
        foreach(['heading'=>180,'heading_bn'=>180,'body'=>50000,'body_bn'=>50000,'seo_title'=>180,'seo_description'=>600,'og_title'=>180,'og_description'=>600] as $key=>$max)
            $data[$key]=Input::text($v,$key,$max,!in_array($key,['heading_bn','body','body_bn','seo_title','seo_description','og_title','og_description'],true));
        $data['blocks']=CmsBlocks::normalize($v['blocks']??[]);
        $data['enabled']=($v['enabled']??'0')==='1';
        $data['show_footer']=($v['show_footer']??'0')==='1';
        foreach(['index_mode'=>['index','noindex'],'follow_mode'=>['follow','nofollow']] as $key=>$choices){
            $data[$key]=Input::text($v,$key,12);
            if(!in_array($data[$key],$choices,true))throw new InvalidArgumentException('Invalid page search visibility.');
        }
        return $data;
    }
    public function save(array $v): int {
        $this->allow('content','edit');
        $id=Input::integer($v['id']??0,0);
        $version=Input::integer($v['version']??0,0);
        $payload=self::payload($v);
        $canSeo=Security::can($this->db,$this->actor,'seo','edit');
        return $this->transaction(function()use($id,$version,$payload,$canSeo):int {
            if(!$canSeo){
                $old=[];
                if($id!==0){
                    $read=$this->db->prepare('SELECT draft FROM aelvo_cms_pages WHERE id=? FOR UPDATE');
                    $read->execute([$id]);$existing=$read->fetchColumn();
                    if($existing!==false)$old=json_decode($existing,true,32,JSON_THROW_ON_ERROR);
                }
                foreach(['seo_title'=>'','seo_description'=>'','og_title'=>'','og_description'=>'','index_mode'=>'index','follow_mode'=>'follow'] as $key=>$default)
                    $payload[$key]=$old[$key]??$default;
            }
            $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if(strlen($json)>220000)throw new InvalidArgumentException('Page content exceeds the allowed size.');
            if($id===0){
                if($version!==0)throw new InvalidArgumentException('New page must not have an existing revision.');
                $q=$this->db->prepare('INSERT INTO aelvo_cms_pages(draft) VALUES (?)');$q->execute([$json]);
                $id=(int)$this->db->lastInsertId();
            }else{
                $q=$this->db->prepare('UPDATE aelvo_cms_pages SET draft=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND version=?');
                $q->execute([$json,$id,$version]);
                if($q->rowCount()!==1)throw new RuntimeException('Page draft changed. Reload before editing.',409);
            }
            $this->audit('cms.draft-saved:'.$id);return $id;
        });
    }
    public function publish(array $v): int {
        $this->allow('content','edit');
        $id=Input::integer($v['id']??null);
        $version=Input::integer($v['version']??null);
        if(($v['confirm']??'')!=='1')throw new InvalidArgumentException('Confirm the reviewed page revision.');
        return $this->transaction(function()use($id,$version):int {
            $q=$this->db->prepare('SELECT * FROM aelvo_cms_pages WHERE id=? FOR UPDATE');$q->execute([$id]);$record=$q->fetch();
            if(!$record || (int)$record['version']!==$version)throw new RuntimeException('Page draft changed. Review again.',409);
            $data=json_decode($record['draft'],true,32,JSON_THROW_ON_ERROR);
            $slug=self::slug((string)($data['slug']??''));
            if(($data['heading']??'')==='')throw new InvalidArgumentException('English page heading is required to publish.');
            $blocks=CmsBlocks::normalize($data['blocks']??[]);
            $mediaIds=CmsBlocks::mediaIds($blocks);
            foreach(CmsBlocks::links($blocks) as $href){
                if($href==='./' || $href==='store.php?page=shop' || $href===self::path($slug))continue;
                $target=PublicRoutes::resolve($this->db,$href);
                if(!$target || !in_array($target['type'],['product','category','brand','cms'],true))
                    throw new InvalidArgumentException('Content button must point to a currently published and active AELVO page.');
            }
            if($mediaIds){
                $placeholders=implode(',',array_fill(0,count($mediaIds),'?'));
                $q=$this->db->prepare('SELECT m.id FROM aelvo_media m JOIN aelvo_content_media c ON c.media_id=m.id WHERE m.id IN ('.$placeholders.') AND m.mime IN (\'image/jpeg\',\'image/png\',\'image/webp\') FOR UPDATE');
                $q->execute($mediaIds);
                if(count($q->fetchAll(PDO::FETCH_COLUMN))!==count($mediaIds))throw new InvalidArgumentException('All CMS images must be uploaded through the content media manager.');
            }
            $oldSlug=(string)($record['published_slug']?:$slug);
            // Switch the published snapshot before route ownership checks. A rollback
            // restores both the live page and all historical route records.
            $q=$this->db->prepare('UPDATE aelvo_cms_pages SET published=draft,published_slug=?,published_version=version,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND version=?');
            $q->execute([$slug,$id,$version]);
            if($q->rowCount()!==1)throw new RuntimeException('Page draft changed. Review again.',409);
            PublicRoutes::assignEntity($this->db,'cms',$id,$oldSlug,$slug,(int)$this->actor['id']);
            // Replace public image ownership in the SAME publication transaction.
            $q=$this->db->prepare('DELETE FROM aelvo_cms_published_media WHERE page_id=?');$q->execute([$id]);
            if(!empty($data['enabled'])){
                $q=$this->db->prepare('INSERT INTO aelvo_cms_published_media(page_id,media_id) VALUES (?,?)');
                foreach($mediaIds as $mediaId)$q->execute([$id,$mediaId]);
            }
            $this->audit('cms.published:'.$id.':'.$version);return $id;
        });
    }
}
