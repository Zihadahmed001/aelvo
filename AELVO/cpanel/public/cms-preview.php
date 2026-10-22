<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
Aelvo\Security::start((bool)$config['secure_cookies']);
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'");
$actor=Aelvo\Security::actor($pdo);
if(!$actor || !Aelvo\Security::can($pdo,$actor,'content','view')){http_response_code(404);exit('Preview not found.');}
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$record=$id?Aelvo\CmsPages::draft($pdo,(int)$id):null;
if(!$record){http_response_code(404);exit('Page draft not found.');}
$data=json_decode($record['draft'],true,32,JSON_THROW_ON_ERROR);
if(!is_array($data)){http_response_code(404);exit('Page draft not found.');}
$lang=($_GET['lang']??'en')==='bn'?'bn':'en';
$pick=static fn(string $key):string=>$lang==='bn' && !empty($data[$key.'_bn'])?(string)$data[$key.'_bn']:(string)($data[$key]??'');
$escape=static fn($value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?><!doctype html><html lang="<?=$lang?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><base href="<?= $escape(Aelvo\PublicRoutes::localUrl()) ?>"><title>Private draft preview — <?= $escape($pick('heading')) ?> · AELVO</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="assets/styles.css"><link rel="stylesheet" href="customer.css"></head><body><header class="store-header"><span class="wordmark">AELVO<small>FASHION</small></span><nav class="store-nav">Private draft preview · Version <?= (int)$record['version'] ?></nav><div class="header-actions"><a href="cms-preview.php?id=<?=(int)$record['id']?>&amp;lang=<?= $lang==='en'?'bn':'en' ?>"><?= $lang==='en'?'বাংলা':'English' ?></a></div></header><main><section class="page cms-page"><p class="notice">Only signed-in staff with content-view permission can access this saved draft. Publishing is a separate action.</p><h1><?= $escape($pick('heading')) ?></h1><div class="preserve-lines"><?= $escape($pick('body')) ?></div><div class="cms-reading"><?= Aelvo\CmsBlocks::render($data['blocks']??[],$lang) ?></div></section></main></body></html>
