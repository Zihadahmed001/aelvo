<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Bounded Steadfast adapter. Never conflates a provider status with payment or stock. */
final class Steadfast {
    private const BASE='https://portal.steadfast.com.bd/api/v1';
    public static function credentials(PDO $db,string $encryptionKey): array {
        if(!function_exists('sodium_crypto_secretbox_open') || !preg_match('/^[0-9a-f]{64}$/D',$encryptionKey))throw new RuntimeException('Private encryption key is not configured.');
        $q=$db->prepare('SELECT ciphertext FROM aelvo_provider_secrets WHERE provider=?');$q->execute(['steadfast']);$encoded=$q->fetchColumn();
        if(!is_string($encoded)||$encoded==='')throw new RuntimeException('Steadfast credentials are not configured.');
        $data=base64_decode($encoded,true);
        if(!is_string($data)||strlen($data)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new RuntimeException('Invalid Steadfast credentials.');
        $plain=sodium_crypto_secretbox_open(substr($data,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($data,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),hex2bin($encryptionKey));
        if(!is_string($plain))throw new RuntimeException('Could not decrypt Steadfast credentials.');
        $v=json_decode($plain,true,8,JSON_THROW_ON_ERROR);
        if(!is_array($v)||!self::validKey($v['api_key']??null)||!self::validKey($v['secret_key']??null))throw new RuntimeException('Steadfast credential fields are missing.');
        return ['api_key'=>$v['api_key'],'secret_key'=>$v['secret_key']];
    }
    public static function validKey(mixed $value): bool {return is_string($value)&&strlen($value)>=6&&strlen($value)<=300&&!preg_match('/[\x00-\x20\x7f]/',$value);}
    public static function bookingPayload(array $row): array {
        $phone=Input::phone((string)$row['recipient_phone']);
        $address=trim((string)$row['recipient_address']);
        if(strlen((string)$row['recipient_name'])>100)throw new InvalidArgumentException('Courier name must be at most 100 bytes.');
        if($address===''||strlen($address)>250)throw new InvalidArgumentException('Courier address must be 1–250 bytes.');
        if((int)$row['cod_minor']<0||(int)$row['cod_minor']>10000000000)throw new InvalidArgumentException('COD amount is invalid.');
        return ['invoice'=>(string)$row['invoice'],'recipient_name'=>(string)$row['recipient_name'],'recipient_phone'=>$phone,'recipient_address'=>$address,'cod_amount'=>round((int)$row['cod_minor']/100,2)];
    }
    public static function parseBooking(array $response,string $invoice,int $codMinor): array {
        $consignment=$response['consignment']??null;
        if(($response['status']??null)!==200||!is_array($consignment)||!ctype_digit((string)($consignment['consignment_id']??'')))throw new RuntimeException('Steadfast booking not confirmed; reconcile with the provider before any retry.');
        if((string)($consignment['invoice']??'')!==$invoice)throw new RuntimeException('Courier returned a different invoice. Manual reconciliation required.');
        $actual=$consignment['cod_amount']??null;
        if(!is_numeric($actual)||(int)round((float)$actual*100)!==$codMinor)throw new RuntimeException('Courier returned a different COD amount. Manual reconciliation required.');
        $tracking=(string)($consignment['tracking_code']??'');$status=(string)($consignment['status']??'');
        if(strlen($tracking)>100||strlen($status)>80)throw new RuntimeException('Unexpected courier response; reconcile manually.');
        return ['consignment_id'=>(string)$consignment['consignment_id'],'tracking_code'=>$tracking,'remote_status'=>$status];
    }
    public static function parseStatus(array $response): string {
        $status=$response['delivery_status']??null;
        if(($response['status']??null)!==200||!is_string($status)||!in_array($status,['pending','in_review','hold','delivered_approval_pending','partial_delivered_approval_pending','cancelled_approval_pending','unknown_approval_pending','delivered','partial_delivered','cancelled','unknown'],true))throw new RuntimeException('Courier status was not verifiable.');
        return $status;
    }
    /** Actual HTTPS only; no redirect, fixed host, no secret in URL or logs. */
    public static function request(array $credentials,string $method,string $path,?array $payload=null): array {
        if(!in_array($method,['GET','POST'],true)||!preg_match('~^/(?:create_order|status_by_cid/[0-9]{1,30})$~D',$path))throw new InvalidArgumentException('Courier API route not allowed.');
        if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is required for courier integration.');
        $curl=curl_init(self::BASE.$path);if($curl===false)throw new RuntimeException('Courier transport unavailable.');
        $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_HTTPHEADER=>['Api-Key: '.$credentials['api_key'],'Secret-Key: '.$credentials['secret_key'],'Content-Type: application/json','Accept: application/json'],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
        if($method==='POST'){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=json_encode($payload??[],JSON_THROW_ON_ERROR);}
        curl_setopt_array($curl,$options);$body=curl_exec($curl);$code=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        if(!is_string($body)||strlen($body)>65536||$code!==200)throw new RuntimeException('Courier response was unavailable or rejected; reconcile before retry.');
        $data=json_decode($body,true,12,JSON_THROW_ON_ERROR);
        if(!is_array($data)||array_is_list($data))throw new RuntimeException('Courier returned an invalid response.');
        return $data;
    }
}
