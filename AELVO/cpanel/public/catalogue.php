<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
echo json_encode(['products'=>Aelvo\Catalogue::publicProducts($pdo),'theme'=>Aelvo\Settings::get($pdo,'theme')['value']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
