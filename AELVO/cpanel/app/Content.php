<?php
declare(strict_types=1);
namespace Aelvo;
use PDO;
use InvalidArgumentException;
use RuntimeException;
final class Content extends Service {
    public const KEYS=['hero','popup','delivery-returns','about','privacy','terms'];
    public static function get(PDO $db,string $key,bool $published=true): array {
        $q=$db->prepare('SELECT * FROM aelvo_content WHERE content_key=?');$q->execute([$key]);$r=$q->fetch();
        return ['value'=>$r&&$r[$published?'published':'draft']?json_decode($r[$published?'published':'draft'],true,32,JSON_THROW_ON_ERROR):[],'version'=>(int)($r['version']??0),'published_version'=>$r['published_version']??null];
    }
    public static function destination(string $url): string {
        if($url==='')return '';
        if(!preg_match('/^store\.php(?:\?[a-zA-Z0-9_=&%.-]+)?$/D',$url))throw new InvalidArgumentException('Use an internal store.php link.');
        return $url;
    }
    private function media(string $id,bool $video=false): string {
        if($id==='')return '';
        $q=$this->db->prepare('SELECT m.mime FROM aelvo_media m JOIN aelvo_content_media c ON c.media_id=m.id WHERE m.id=?');$q->execute([$id]);$mime=$q->fetchColumn();
        if(!$mime || ($video?!in_array($mime,['video/mp4','video/webm'],true):!in_array($mime,['image/jpeg','image/png','image/webp'],true)))throw new InvalidArgumentException('Choose an uploaded content '.($video?'video':'image').'.');return $id;
    }
    public function save(array $v): void {
        $this->allow('content','edit');$key=Input::text($v,'content_key',80);$version=Input::integer($v['version']??0,0);
        if(!in_array($key,self::KEYS,true))throw new InvalidArgumentException('Invalid content type.');
        $data=[];foreach(['heading','heading_bn','subtitle','subtitle_bn','body','body_bn','button','button_bn','button2','button2_bn','tagline','tagline_bn'] as $field)$data[$field]=Input::text($v,$field,str_starts_with($field,'body')?30000:1000,false);
        foreach(['seo_title'=>180,'seo_description'=>600,'og_title'=>180,'og_description'=>600] as $seoField=>$max)$data[$seoField]=Input::text($v,$seoField,$max,false);
        foreach(['index_mode'=>['index','noindex'],'follow_mode'=>['follow','nofollow']] as $seoField=>$choices) {
            $mode=Input::text($v,$seoField,12,false)?:$choices[0];
            if(!in_array($mode,$choices,true))throw new InvalidArgumentException('Invalid page robots selection.');
            $data[$seoField]=$mode;
        }
        $data['destination']=self::destination(Input::text($v,'destination',300,false));$data['destination2']=self::destination(Input::text($v,'destination2',300,false));
        $data['media_type']=($v['media_type']??'image')==='video'?'video':'image';
        $data['image']=$this->media(Input::text($v,'image',32,false));$data['video']=$this->media(Input::text($v,'video',32,false),true);
        $data['enabled']=($v['enabled']??'0')==='1';$data['delay']=Input::integer($v['delay']??3,0,60);
        $data['start']=Input::text($v,'start',20,false);$data['end']=Input::text($v,'end',20,false);
        foreach(['start','end'] as $field)if($data[$field]!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$data[$field])||strtotime($data[$field].' UTC')===false))throw new InvalidArgumentException('Use valid UTC schedule dates.');
        if($data['start']&&$data['end']&&strcmp($data['end'],$data['start'])<=0)throw new InvalidArgumentException('End must be after start.');
        if($data['enabled']&&$data['media_type']==='video'&&(!$data['video']||!$data['image']))throw new InvalidArgumentException('A video banner needs both a video and a poster image.');
        $canSeo=Security::can($this->db,$this->actor,'seo','edit');
        $this->transaction(function()use($key,$version,$data,$canSeo){
            if(!$canSeo){
                $old=[];
                if($version>0){
                    $read=$this->db->prepare('SELECT draft FROM aelvo_content WHERE content_key=? FOR UPDATE');
                    $read->execute([$key]);$oldJson=$read->fetchColumn();
                    if($oldJson!==false)$old=json_decode($oldJson,true,32,JSON_THROW_ON_ERROR);
                }
                foreach(['seo_title'=>'','seo_description'=>'','og_title'=>'','og_description'=>'','index_mode'=>'index','follow_mode'=>'follow'] as $field=>$default)
                    $data[$field]=$old[$field]??$default;
            }
            $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if($version===0){$q=$this->db->prepare('INSERT INTO aelvo_content(content_key,draft) VALUES (?,?)');$q->execute([$key,$json]);}
            else{$q=$this->db->prepare('UPDATE aelvo_content SET draft=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE content_key=? AND version=?');$q->execute([$json,$key,$version]);if($q->rowCount()!==1)throw new RuntimeException('Draft changed. Reload first.',409);}
            $this->audit('content.draft-saved:'.$key);
        });
    }
    public function publish(array $v): void {
        $this->allow('content','edit');$key=Input::text($v,'content_key',80);$version=Input::integer($v['version']??null);
        if(($v['confirm']??'')!=='1')throw new InvalidArgumentException('Confirm the reviewed draft before publishing.');
        $this->transaction(function()use($key,$version){
            $q=$this->db->prepare('UPDATE aelvo_content SET published=draft,published_version=version,version=version+1,updated_at=UTC_TIMESTAMP() WHERE content_key=? AND version=?');$q->execute([$key,$version]);
            if($q->rowCount()!==1)throw new RuntimeException('Draft changed. Review it again.',409);$this->audit('content.published:'.$key.':'.$version);
        });
    }
    public static function active(array $v): bool {
        return ($v['enabled']??false)===true && (empty($v['start'])||strtotime($v['start'].' UTC')<=time()) && (empty($v['end'])||strtotime($v['end'].' UTC')>time());
    }
    public static function publicMedia(PDO $db,string $id): bool {
        foreach(['hero','popup'] as $key){$v=self::get($db,$key)['value'];if(self::active($v)&&in_array($id,[$v['image']??'',$v['video']??''],true))return true;}return false;
    }
}
