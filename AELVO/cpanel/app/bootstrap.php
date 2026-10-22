<?php
declare(strict_types=1);
ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e): void {
    error_log('AELVO backend error: ' . get_class($e));
    if(PHP_SAPI==='cli'){fwrite(STDERR,'AELVO error: '.get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);}
    http_response_code(503);
    echo 'Service unavailable. Check server configuration and the installation guide.';
});
if (PHP_VERSION_ID < 80200 || !extension_loaded('pdo_mysql')) {
    if(PHP_SAPI==='cli'){fwrite(STDERR,"PHP 8.2+ with PDO MySQL is required.\n");exit(1);}
    http_response_code(503); exit('PHP 8.2+ with PDO MySQL is required.');
}
$configPath = dirname(__DIR__) . '/config/config.php';
if (!is_file($configPath)) {
    if(PHP_SAPI==='cli'){fwrite(STDERR,"Configure config/config.php before running checks.\n");exit(1);}
    http_response_code(503);
    exit('Copy config/config.example.php to config/config.php and follow INSTALL.md first.');
}
$config = require $configPath;
$db = $config['db'];
if (!preg_match('/^[a-zA-Z0-9_]+$/D', $db['name'])) throw new RuntimeException('Invalid database name');
$pdo = new PDO('mysql:host='.$db['host'].';port='.(int)$db['port'].';dbname='.$db['name'].';charset=utf8mb4', $db['user'], $db['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone = '+00:00'");
require_once __DIR__.'/Security.php';

foreach (['Schema','UpgradeAudit','Tracking','Domain','VariantPricing','ProductDetails','Catalogue','Uploads','DeliveryPricing','TaxPricing','OrderTaxes','RefundTaxes','Orders','OrderAdmin','OrderCancellationRequests','AdminWorkspace','AuditLog','Settings','Team','Finance','Purchases','PurchaseRegister','SupplierRegister','Suppliers','Quarantine','AdminView','Sms','Customers','Reviews','Content','CmsBlocks','CmsPages','Seo','PublicRoutes','Taxonomy','StorefrontNavigation','Coupons','Payments','Returns','ReturnOperations','Wishlist','SavedBag','ReturnRequests','Checkout','Support','CustomerAi','CustomerChat','OwnerMail','OwnerEmailVerification','OwnerAiPolicy','OwnerAiService','OwnerAiConsole','Reports','FinanceReconciliation','FinancialPerformance','InventoryCosting','Steadfast','Courier','CourierResolution','CourierExceptions','CourierAccounting','SslCommerz','GatewayPayments','GatewayExceptions','GatewayPayoutBatches'] as $service) require_once __DIR__.'/'.$service.'.php';
