<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Domain.php';
require dirname(__DIR__).'/app/Steadfast.php';
require dirname(__DIR__).'/app/Schema.php';
use Aelvo\Steadfast;
use Aelvo\Schema;
function check(bool $ok,string $case):void{if(!$ok)throw new RuntimeException($case);}
function rejected(callable $fn,string $case):void{try{$fn();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new RuntimeException('Expected rejection: '.$case);}
$row=['invoice'=>'AEL-261021-ABCDEF0123','recipient_name'=>'Name','recipient_phone'=>'01712345678','recipient_address'=>'Dhaka, Bangladesh','cod_minor'=>150025];
$payload=Steadfast::bookingPayload($row);
check($payload['cod_amount']===1500.25&&$payload['recipient_phone']==='01712345678','Exact COD cents and phone');
$remote=['status'=>200,'consignment'=>['consignment_id'=>12345,'invoice'=>$row['invoice'],'cod_amount'=>1500.25,'tracking_code'=>'SF123','status'=>'in_review']];
$p=Steadfast::parseBooking($remote,$row['invoice'],150025);
check($p['consignment_id']==='12345'&&$p['remote_status']==='in_review','Only validated consignment accepted');
rejected(fn()=>Steadfast::parseBooking([...$remote,'status'=>202],$row['invoice'],150025),'Unconfirmed HTTP acceptance');
rejected(fn()=>Steadfast::parseBooking($remote,'ANOTHER',150025),'Invoice mismatch');
rejected(fn()=>Steadfast::parseBooking($remote,$row['invoice'],200025),'COD mismatch');
rejected(fn()=>Steadfast::parseBooking(['status'=>200],$row['invoice'],150025),'No consignment');
rejected(fn()=>Steadfast::bookingPayload([...$row,'recipient_address'=>str_repeat('A',251)]),'Address limit');
check(Steadfast::parseStatus(['status'=>200,'delivery_status'=>'delivered'])==='delivered','Documented delivery status');
rejected(fn()=>Steadfast::parseStatus(['status'=>200,'delivery_status'=>'paid']), 'No fabricated payment status');
rejected(fn()=>Steadfast::request(['api_key'=>'abc','secret_key'=>'abc'],'GET','/../../internal'),'SSRF route rejection');
check(in_array('courier',Schema::FILES,true),'Courier ordered schema');
$statements=Schema::statements(file_get_contents(dirname(__DIR__).'/database/courier.sql'));
check(count($statements)===4,'Courier schema splits into four tables');
echo "Courier adapter validations and bundled courier schema parsed (offline; no MySQL or provider calls).\n";
