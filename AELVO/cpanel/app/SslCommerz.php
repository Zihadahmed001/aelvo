<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Strict SSLCOMMERZ hosted-checkout adapter. Only fixed provider hosts are contacted. */
final class SslCommerz {
    private const HOSTS=['sandbox'=>'https://sandbox.sslcommerz.com','live'=>'https://securepay.sslcommerz.com'];

    public static function credentials(PDO $db,string $key): array {
        if(!function_exists('sodium_crypto_secretbox_open') || !preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('The private encryption key is unavailable.');
        $q=$db->prepare('SELECT ciphertext FROM aelvo_provider_secrets WHERE provider=?');$q->execute(['sslcommerz']);$encoded=$q->fetchColumn();
        if(!is_string($encoded)||$encoded==='')throw new RuntimeException('SSLCOMMERZ has not been configured.');
        $raw=base64_decode($encoded,true);
        if(!is_string($raw)||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new RuntimeException('Invalid encrypted gateway configuration.');
        $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),hex2bin($key));
        if(!is_string($plain))throw new RuntimeException('Cannot decrypt the gateway configuration.');
        $v=json_decode($plain,true,6,JSON_THROW_ON_ERROR);
        if(!is_array($v)||!self::valid($v['store_id']??null,30)||!self::valid($v['store_password']??null,120)||!in_array($v['mode']??null,['sandbox','live'],true))throw new RuntimeException('Gateway credentials are incomplete.');
        return $v;
    }
    public static function valid(mixed $v,int $max): bool {return is_string($v)&&strlen($v)>=3&&strlen($v)<=$max&&!preg_match('/[\x00-\x20\x7f]/',$v);}
    public static function base(string $mode): string {return self::HOSTS[$mode]??throw new InvalidArgumentException('Invalid gateway environment.');}
    public static function money(int $minor): string {
        if($minor<0||$minor>9999999999)throw new InvalidArgumentException('Invalid payment amount.');
        return intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT);
    }
    public static function amount(mixed $value): int {
        if(!is_string($value)||!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',$value))throw new InvalidArgumentException('Invalid gateway amount.');
        return Input::money($value);
    }
    public static function hostedUrl(mixed $url,string $mode): string {
        if(!is_string($url)||strlen($url)>800)throw new RuntimeException('Invalid hosted payment URL.');
        $parts=parse_url($url);$allowed=parse_url(self::base($mode),PHP_URL_HOST);
        if(!$parts||($parts['scheme']??'')!=='https'||($parts['host']??'')!==$allowed||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])||!str_starts_with($parts['path']??'','/'))throw new RuntimeException('Provider supplied an untrusted payment URL.');
        return $url;
    }
    public static function validateResponse(array $result,array $attempt,string $valId): array {
        if(!in_array($result['status']??null,['VALID','VALIDATED'],true)||($result['APIConnect']??'DONE')!=='DONE')throw new RuntimeException('Gateway transaction is not validated.');
        if(!hash_equals((string)$attempt['tran_id'],(string)($result['tran_id']??''))||!hash_equals($valId,(string)($result['val_id']??'')))throw new RuntimeException('Transaction identity does not match.');
        if(($result['currency']??'')!=='BDT'||self::amount($result['amount']??null)!==(int)$attempt['expected_minor'])throw new RuntimeException('Gateway currency or amount differs from the order.');
        if(isset($result['value_a'])&&$result['value_a']!==''&&!hash_equals((string)$attempt['id'],(string)$result['value_a']))throw new RuntimeException('Gateway order binding differs.');
        $bank=$result['bank_tran_id']??null;
        if(!is_string($bank)||$bank===''||strlen($bank)>120)throw new RuntimeException('Missing bank transaction evidence.');
        if(!in_array((string)($result['risk_level']??''),['0','1'],true))throw new RuntimeException('Gateway risk assessment is missing.');
        return ['risk'=>(string)$result['risk_level']==='1','bank_reference'=>$bank];
    }
    /** Bounded TLS-verified request, no redirects, never echo or log credentials. */
    public static function request(string $mode,string $path,array $fields,bool $post): array {
        if(!in_array($path,['/gwprocess/v4/api.php','/validator/api/validationserverAPI.php'],true))throw new InvalidArgumentException('Gateway API path is not allowed.');
        if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is required for gateway processing.');
        $url=self::base($mode).$path;
        if(!$post)$url.='?'.http_build_query($fields,'','&',PHP_QUERY_RFC3986);
        $curl=curl_init($url);if($curl===false)throw new RuntimeException('Gateway transport unavailable.');
        $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>18,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json']];
        if($post){$opts[CURLOPT_POST]=true;$opts[CURLOPT_POSTFIELDS]=http_build_query($fields,'','&',PHP_QUERY_RFC3986);}
        curl_setopt_array($curl,$opts);$body=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        if(!is_string($body)||strlen($body)>65536||$http!==200)throw new RuntimeException('Gateway response unavailable; check the transaction with SSLCOMMERZ before retrying.');
        $result=json_decode($body,true,12,JSON_THROW_ON_ERROR);
        if(!is_array($result)||array_is_list($result))throw new RuntimeException('Gateway returned an invalid response.');
        return $result;
    }
}
