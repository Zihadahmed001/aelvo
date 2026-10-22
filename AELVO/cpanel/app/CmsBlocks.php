<?php
declare(strict_types=1);
namespace Aelvo;
use InvalidArgumentException;

/** Bounded, typed content blocks. No admin-authored HTML, script, or arbitrary URLs. */
final class CmsBlocks {
    public const TYPES=['paragraph','heading','list','quote','image','faq','link'];
    public const LIMIT=24;

    public static function normalize(mixed $input): array {
        if($input===null || $input===[])return [];
        if(!is_array($input) || count($input)>self::LIMIT)throw new InvalidArgumentException('Use at most 24 content blocks.');
        $blocks=[];
        foreach($input as $entry){
            if(!is_array($entry))throw new InvalidArgumentException('Invalid content block.');
            $kind=Input::text($entry,'kind',24,false);
            if($kind==='')continue; // Empty editor slot.
            if(!in_array($kind,self::TYPES,true))throw new InvalidArgumentException('Unknown content block type.');
            $max=$kind==='list'?3000:($kind==='paragraph'?5000:1200);
            $block=['kind'=>$kind];
            foreach(['text','text_bn'] as $field)$block[$field]=Input::text($entry,$field,$max,false);
            foreach(['detail','detail_bn'] as $field)$block[$field]=Input::text($entry,$field,5000,false);
            $block['media_id']=Input::text($entry,'media_id',32,false);
            $block['href']=Input::text($entry,'href',220,false);
            foreach(['alt','alt_bn'] as $field)$block[$field]=Input::text($entry,$field,250,false);
            if($kind==='link'){
                if($block['text']==='' || $block['href']==='')throw new InvalidArgumentException('Link blocks require a label and destination.');
                if($block['href']!=='./' && $block['href']!=='store.php?page=shop'){
                    $path=PublicRoutes::path($block['href']);
                    if(!preg_match('~^(product|category|brand|pages)/[a-z0-9-]+$~D',$path))throw new InvalidArgumentException('Links must point to a local published page, product or collection.');
                    $block['href']=$path;
                }
            }else $block['href']='';
            if($kind==='image'){
                if(!preg_match('/^[a-f0-9]{32}$/D',$block['media_id']))throw new InvalidArgumentException('Select an uploaded image for image blocks.');
                if($block['alt']==='')throw new InvalidArgumentException('Image blocks require descriptive English ALT text.');
            }else{
                if($block['text']==='')throw new InvalidArgumentException('Every '.$kind.' block needs English text.');
                if($kind==='faq' && $block['detail']==='')throw new InvalidArgumentException('FAQ blocks require an answer.');
                // Store only fields allowed for this block kind, dropping unrelated media IDs.
                $block['media_id']='';$block['alt']='';$block['alt_bn']='';
                if($kind!=='faq'){$block['detail']='';$block['detail_bn']='';}
                if($kind==='list' && count(preg_split('/\R/u',$block['text']))>30)
                    throw new InvalidArgumentException('A list has at most 30 items.');
            }
            $blocks[]=$block;
        }
        return $blocks;
    }
    public static function links(array $blocks): array {
        $links=[];
        foreach($blocks as $block)if(($block['kind']??'')==='link' && !empty($block['href']))$links[]=$block['href'];
        return array_values(array_unique($links));
    }
    public static function mediaIds(array $blocks): array {
        $ids=[];
        foreach($blocks as $block)if(($block['kind']??'')==='image' && !empty($block['media_id']))$ids[]=$block['media_id'];
        return array_values(array_unique($ids));
    }
    private static function value(array $block,string $field,string $lang): string {
        return $lang==='bn' && !empty($block[$field.'_bn'])?(string)$block[$field.'_bn']:(string)($block[$field]??'');
    }
    public static function plainText(array $blocks,string $lang='en'): string {
        $pieces=[];
        foreach($blocks as $block)if(is_array($block)&&($block['kind']??'')!=='image'){
            $pieces[]=self::value($block,'text',$lang);
            if(($block['kind']??'')==='faq')$pieces[]=self::value($block,'detail',$lang);
        }
        return trim(implode(' ',array_filter($pieces)));
    }
    private static function escaped(string $value): string {
        return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
    private static function lines(string $value): string {
        return nl2br(self::escaped($value),false);
    }
    /** Fully escaped HTML from a fixed template; not a WYSIWYG HTML sink. */
    public static function render(array $blocks,string $lang='en'): string {
        $html='';
        foreach($blocks as $block){
            if(!is_array($block) || !in_array($block['kind']??'',self::TYPES,true))continue;
            $text=self::value($block,'text',$lang);$detail=self::value($block,'detail',$lang);
            switch($block['kind']){
                case 'paragraph':$html.='<p class="cms-paragraph">'.self::lines($text).'</p>';break;
                case 'heading':$html.='<h2 class="cms-subheading">'.self::escaped($text).'</h2>';break;
                case 'quote':$html.='<blockquote class="cms-quote">'.self::lines($text).'</blockquote>';break;
                case 'list':
                    $html.='<ul class="cms-list">';
                    foreach(preg_split('/\R/u',$text)?:[] as $line)if(trim($line)!=='')$html.='<li>'.self::escaped(trim($line)).'</li>';
                    $html.='</ul>';break;
                case 'image':
                    $id=$block['media_id']??'';
                    if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))break;
                    $alt=self::value($block,'alt',$lang);
                    $caption=self::value($block,'text',$lang);
                    $html.='<figure class="cms-figure"><img src="media.php?id='.self::escaped($id).'" loading="lazy" decoding="async" alt="'.self::escaped($alt).'">';
                    if($caption!=='')$html.='<figcaption>'.self::escaped($caption).'</figcaption>';
                    $html.='</figure>';break;
                case 'link':
                    $href=$block['href']??'';
                    if(!is_string($href) || ($href!=='./' && $href!=='store.php?page=shop' && !preg_match('~^(product|category|brand|pages)/[a-z0-9-]+$~D',$href)))break;
                    $html.='<p class="cms-link"><a class="btn" href="'.self::escaped($href).'">'.self::escaped($text).'</a></p>';break;
                case 'faq':
                    $html.='<details class="cms-faq"><summary>'.self::escaped($text).'</summary><p>'.self::lines($detail).'</p></details>';break;
            }
        }
        return $html;
    }
    /** Emit only the Q&A visibly rendered from the published snapshot. */
    public static function faqJsonLd(array $blocks,string $lang='en'): string {
        $questions=[];
        foreach($blocks as $block)if(is_array($block)&&($block['kind']??'')==='faq'){
            $question=self::value($block,'text',$lang);$answer=self::value($block,'detail',$lang);
            if($question!=='' && $answer!=='')$questions[]=['@type'=>'Question','name'=>$question,'acceptedAnswer'=>['@type'=>'Answer','text'=>$answer]];
        }
        if(!$questions)return '';
        return json_encode(['@context'=>'https://schema.org','@type'=>'FAQPage','mainEntity'=>$questions],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
    }
}
