<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/PublicRoutes.php';
require dirname(__DIR__).'/app/CmsBlocks.php';
use Aelvo\CmsBlocks;
function blockCheck(bool $condition,string $message):void {if(!$condition)throw new RuntimeException('CMS blocks regression: '.$message);}
function blockReject(array $blocks,string $reason):void {
    try{CmsBlocks::normalize($blocks);throw new RuntimeException('Accepted unsafe block: '.$reason);}
    catch(InvalidArgumentException $e){}
}
$imgId=str_repeat('a',32);
$payload=[
    ['kind'=>'heading','text'=>'Delivery & returns','text_bn'=>'ডেলিভারি ও রিটার্ন'],
    ['kind'=>'paragraph','text'=>'<script>alert(1)</script> & care.','text_bn'=>'যত্ন নিন।'],
    ['kind'=>'list','text'=>'One\nTwo','text_bn'=>'এক\nদুই'],
    ['kind'=>'image','text'=>'Photo','media_id'=>$imgId,'alt'=>'Leather bag photo','alt_bn'=>'চামড়ার ব্যাগ'],
    ['kind'=>'link','text'=>'Browse shoes','text_bn'=>'জুতা দেখুন','href'=>'category/shoes'],
    ['kind'=>'faq','text'=>'<script>FAQ?</script>','text_bn'=>'প্রশ্ন?', 'detail'=>'Answered. </script><script>bad()</script>','detail_bn'=>'উত্তর।'],
];
$data=CmsBlocks::normalize($payload);
blockCheck(count($data)===6,'accepts typed bilingual content');
blockCheck(CmsBlocks::mediaIds($data)===$expected=[$imgId],'published image references');
$html=CmsBlocks::render($data);
blockCheck(!str_contains($html,'<script>') && str_contains($html,'&lt;script&gt;'),'XSS-safe rendered text');
blockCheck(str_contains($html,'loading="lazy"') && str_contains($html,'alt="Leather bag photo"'),'accessible deferred CMS image');
blockCheck(str_contains($html,'href="category/shoes"'),'same-site CTA markup');
blockCheck(str_contains($html,'<details class="cms-faq">'),'render expandable FAQ');
$schema=CmsBlocks::faqJsonLd($data);
$decoded=json_decode($schema,true,32,JSON_THROW_ON_ERROR);
blockCheck($decoded['@type']==='FAQPage' && count($decoded['mainEntity'])===1,'valid visible FAQPage schema');
blockCheck(!str_contains($schema,'</script>'),'safe JSON-LD embedding');
blockCheck(str_contains(CmsBlocks::render($data,'bn'),'ডেলিভারি ও রিটার্ন'),'Bangla rendering');
blockCheck(CmsBlocks::faqJsonLd([['kind'=>'paragraph','text'=>'Nothing']])==='','omit FAQ schema on non-FAQ page');
blockReject([['kind'=>'link','text'=>'Click','href'=>'https://evil.example/']],'external URL rejected');
blockReject([['kind'=>'raw_html','text'=>'<h1>Unsafe</h1>']],'HTML type');
blockReject([['kind'=>'faq','text'=>'Only question']],'missing answer');
blockReject([['kind'=>'image','media_id'=>'not-a-media-id','alt'=>'foo']],'invalid media ID');
blockReject([['kind'=>'image','media_id'=>$imgId]],'missing accessible ALT');
blockReject(array_fill(0,25,['kind'=>'paragraph','text'=>'x']),'excessive blocks');
blockReject([['kind'=>'paragraph','text'=>['array']]],'nested injected form value');
blockCheck(CmsBlocks::normalize([['kind'=>'']])===[],'empty editor row ignored');
blockCheck(CmsBlocks::plainText(CmsBlocks::normalize([['kind'=>'paragraph','text'=>'Legacy plus blocks']]))==='Legacy plus blocks','SEO fallback from blocks');
echo "Typed CMS content, HTML escaping, JSON-LD, input bounds and multilingual offline checks passed. No MySQL or browser acceptance performed.\n";
