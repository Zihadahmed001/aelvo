<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/TaxPricing.php';
require dirname(__DIR__).'/app/Payments.php';
use Aelvo\TaxPricing;
use Aelvo\Payments;
function taxCheck(bool $ok,string $description):void {if(!$ok)throw new RuntimeException($description);echo "PASS: $description\n";}
function rejectTax(callable $fn):void {try{$fn();}catch(InvalidArgumentException){return;}throw new RuntimeException('Invalid tax configuration accepted');}
$disabled=TaxPricing::calculate([],100000,12000);
taxCheck($disabled['tax_minor']===0 && $disabled['rate_basis_points']===0 && !$disabled['shipping_taxable'],'old stores remain tax disabled');
$seven=['mode'=>'exclusive','rate_basis_points'=>750,'shipping_taxable'=>false];
$goods=TaxPricing::calculate($seven,90000,12000);
taxCheck($goods['tax_minor']===6750 && $goods['taxable_merchandise_minor']===90000 && $goods['taxable_shipping_minor']===0,'7.5% applies after discount, excluding shipping');
$shipping=TaxPricing::calculate([...$seven,'shipping_taxable'=>true],90000,12000);
taxCheck($shipping['tax_minor']===7650 && $shipping['taxable_shipping_minor']===12000,'shipping tax must be explicitly enabled');
taxCheck(TaxPricing::calculate(['mode'=>'exclusive','rate_basis_points'=>500,'shipping_taxable'=>false],10,0)['tax_minor']===1,'single final half-up rounding');
taxCheck(TaxPricing::parseRate('7.50')===750 && TaxPricing::parseRate('0.01')===1 && TaxPricing::parseRate('100')===10000,'percentage parsed in integer basis points');
foreach(['-1','1.001','1e2','101','7,5','0.0001','nan'] as $bad)rejectTax(fn()=>TaxPricing::parseRate($bad));
rejectTax(fn()=>TaxPricing::calculate(['mode'=>'exclusive','rate_basis_points'=>0,'shipping_taxable'=>false],100,100));
rejectTax(fn()=>TaxPricing::calculate(['mode'=>'exclusive','rate_basis_points'=>13000,'shipping_taxable'=>false],100,100));
rejectTax(fn()=>TaxPricing::calculate($seven,-1,100));
rejectTax(fn()=>TaxPricing::calculate($seven,100,-1));
taxCheck(Payments::advance(['advance_type'=>'percent','advance_value'=>25],10000+750)===2688,'advance calculated from tax-inclusive payable');
$checkout=file_get_contents(dirname(__DIR__).'/app/Checkout.php');
$orders=file_get_contents(dirname(__DIR__).'/app/Orders.php');
taxCheck(substr_count($checkout,'TaxPricing::calculate(')===2 && str_contains($checkout,'Tax settings changed'),'quote and placement reprice tax');
taxCheck(str_contains($checkout,'OrderTaxes::record(') && str_contains($orders,'OrderTaxes::record('),'both customer and staff orders persist snapshots');
$schema=file_get_contents(dirname(__DIR__).'/app/Schema.php');
taxCheck(str_contains($schema,"'order-cancellations','order-tax'"),'tax migration appended after all older schemas');
echo "Offline tax arithmetic, validation, and structural wiring passed. This does not run MySQL migrations or browser/transaction tests.\n";
