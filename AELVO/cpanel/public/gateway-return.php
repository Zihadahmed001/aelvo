<?php
declare(strict_types=1);
// Browser redirects, including "success" callbacks, do not authenticate funds.
header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
header('Content-Security-Policy: default-src \'none\'; style-src \'self\'; frame-ancestors \'none\'');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payment status pending — AELVO</title><link rel="stylesheet" href="customer.css"></head><body><main class="page"><h1>Payment status pending verification</h1><p>If you submitted a payment, AELVO will independently verify it with the payment provider. A successful redirect does not mean the order is paid.</p><p>Do not send money again while the transaction is awaiting verification. You can check your original checkout or contact support.</p><a href="store.php?page=account">My account</a> · <a href="store.php?page=support">Contact support</a></main></body></html>
