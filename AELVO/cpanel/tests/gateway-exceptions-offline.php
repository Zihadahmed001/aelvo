<?php
declare(strict_types=1);
// Offline policy / wiring tests only. NO PDO MySQL, SSLCommerz calls or bank balance checks.
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/GatewayExceptions.php';
require dirname(__DIR__).'/app/Schema.php';
use Aelvo\GatewayExceptions as Ex;
use Aelvo\Schema;
function mustGateway(bool $yes,string $message): void {if(!$yes)throw new RuntimeException('Gateway exception regression: '.$message);}
function refuseGateway(array $input): void {
    try {Ex::fields($input);}catch(InvalidArgumentException $e){return;}
    throw new RuntimeException('Invalid gateway exception input accepted.');
}
$valid=['issue_type'=>'uncertain_outcome','provider_reference'=>'SSL CASE-20261021','details'=>'Provider session response was lost. Checked merchant portal, no verified result yet.','evidence_checked'=>'1','no_financial_action'=>'1'];
mustGateway(Ex::fields($valid)[0]==='uncertain_outcome','valid uncertainty issue');
mustGateway(Ex::fields(array_replace($valid,['details'=>"First portal inquiry\nNo merchant settlement confirmed"]))[0]==='uncertain_outcome','safe multiline notes');
foreach(['risk_review','validation_discrepancy','settlement_variance','provider_dispute'] as $kind)
    mustGateway(Ex::fields(array_replace($valid,['issue_type'=>$kind]))[0]===$kind,'issue kind '.$kind);
foreach(['fake_payment_success','refund_paid','retry_charge','order_unlocked'] as $kind)refuseGateway(array_replace($valid,['issue_type'=>$kind]));
refuseGateway(array_diff_key($valid,['evidence_checked'=>true]));
refuseGateway(array_diff_key($valid,['no_financial_action'=>true]));
refuseGateway(array_replace($valid,['provider_reference'=>'https://attacker.test/?pass=secret']));
refuseGateway(array_replace($valid,['provider_reference'=>"REF\nsecond-line"]));
refuseGateway(array_replace($valid,['details'=>'too short']));
refuseGateway(array_replace($valid,['details'=>str_repeat('x',1501)]));
$eligible=[
    'uncertain_outcome'=>['Initiating','Ready','Uncertain','Review'],
    'validation_discrepancy'=>['Review','FailedVerified','CancelledVerified','Verified'],
    'risk_review'=>['RiskHold'],
    'settlement_variance'=>['Verified'],
    'provider_dispute'=>['Verified','RiskHold','Review'],
];
foreach($eligible as $issue=>$states)foreach(['Initiating','Ready','Uncertain','Review','RiskHold','SandboxValidated','Verified','FailedVerified','CancelledVerified','Unknown'] as $state)
    mustGateway(Ex::applicable($issue,$state)===in_array($state,$states,true),$issue.' policy '.$state);
mustGateway(!Ex::applicable('override_payment','Verified'),'no financial override issue');
$schema=file_get_contents(dirname(__DIR__).'/database/gateway-exceptions.sql');
mustGateway(count(Schema::statements($schema))===1,'one additive SQL statement');
mustGateway(in_array('gateway-exceptions',Schema::FILES,true) && array_search('gateway-payout-batches',Schema::FILES,true)===array_search('gateway-exceptions',Schema::FILES,true)+1,'migration appended, not reordered');
$base=dirname(__DIR__).'/';
foreach([
    'app/bootstrap.php'=>'GatewayExceptions',
    'app/Team.php'=>"['courier','payments']",
    'app/AdminView.php'=>'gateway-exception-propose',
    'public/index.php'=>"case 'gateway-exception-review'",
    'public/document.php'=>'aelvo_gateway_exception_cases',
] as $file=>$needle)mustGateway(str_contains(file_get_contents($base.$file),$needle),'wiring: '.$file);
$service=file_get_contents($base.'app/GatewayExceptions.php');
foreach(['FOR UPDATE','proposed_by','reviewed_by','observed_state','observed_unsettled_minor','request_hash','request_key','evidence_id','finance','approve'] as $needle)
    mustGateway(str_contains($service,$needle),'workflow guard '.$needle);
foreach(['UPDATE aelvo_gateway_attempts','UPDATE aelvo_orders','UPDATE aelvo_accounts','INSERT INTO aelvo_ledger','INSERT INTO aelvo_gateway_receipts','SslCommerz::request','Steadfast::request'] as $danger)
    mustGateway(!str_contains($service,$danger),'exception workflow must not independently update funds/charge/order: '.$danger);
echo "Gateway exceptions: issue validation, state eligibility, two-person evidence-only wiring and additive SQL checked offline; no MySQL/payment/provider call.\n";
