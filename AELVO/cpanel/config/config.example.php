<?php
declare(strict_types=1);
return [
    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'aelvo', 'user' => 'root', 'password' => ''],
    // Generate with: php -r "echo bin2hex(random_bytes(32));"
    'install_token' => 'REPLACE_WITH_64_RANDOM_HEX_CHARACTERS',
    // true on cPanel/HTTPS. false ONLY for local XAMPP development.
    'secure_cookies' => true,
    // Separate random 64-hex key, never exposed through Admin. Back up privately.
    'encryption_key' => '',
    // Double opt-in: owner must ALSO enable the configured SSLCommerz adapter in Admin.
    // Keep false on production until all payment/webhook/settlement acceptance tests pass.
    'payment_gateways_enabled' => false,
    // Only for a separate non-production _test database and sandbox keys.
    'gateway_sandbox_mode' => false,
];
