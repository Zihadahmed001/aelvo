<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Content-Type: text/plain; charset=UTF-8');header('X-Content-Type-Options: nosniff');$v=Aelvo\Seo::config($pdo);
if(empty($v['base_url'])||($v['indexing']??false)!==true){echo "User-agent: *\nDisallow: /\n";exit;}
$path=rtrim(parse_url($v['base_url'],PHP_URL_PATH)??'','/');
echo "User-agent: *\nDisallow: ".$path."/index.php\nDisallow: ".$path."/store.php?page=account\nDisallow: ".$path."/store.php?page=checkout\nDisallow: ".$path."/store.php?page=cart\nSitemap: ".$v['base_url']."/sitemap.xml\n";
