<?php
declare(strict_types=1);
// Offline contract assertions only. NO real provider call or credentials.
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/Sms.php';
use Aelvo\Input;
use Aelvo\Sms;

function assertSms(bool $condition,string $label): void {
    if(!$condition)throw new RuntimeException('SMS adapter check failed: '.$label);
}
foreach(['{"response_code":202,"success_message":"Submitted"}', '{"response_code":"202"}', '202', '"202"'] as $response) {
    assertSms(Sms::acceptedResponse($response),'202 submission contract');
}
foreach(['{"response_code":1007}', '{"error":0}', '{"response_code":200}', 'true', '<html>202</html>', 'null', '', '[202]', '{"response_code":"202abc"}', '{"success":true}'] as $response) {
    assertSms(!Sms::acceptedResponse($response),'non-202 or ambiguous response must fail closed');
}
assertSms(!Sms::acceptedResponse(str_repeat('2',16385)),'oversized reply must fail');
assertSms('88'.Input::phone('+880 17 0000 0000')==='8801700000000','provider phone format');
echo "BulkSMSBD offline parser checks passed. Real SMS delivery and database integration remain untested.\n";
