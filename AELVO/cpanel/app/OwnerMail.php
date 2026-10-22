<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use RuntimeException;
use PDO;

/** Minimal TLS-only SMTP transport for verified Owner mail; no PHP mail() fallback. */
final class OwnerMail {
    public static function validate(array $v): array {
        $host=trim((string)($v['host']??''));
        if(!preg_match('/\A(?=.{4,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/iD',$host))throw new InvalidArgumentException('Use a public SMTP DNS hostname, not an IP or localhost.');
        $security=$v['security']??'';
        $port=filter_var($v['port']??null,FILTER_VALIDATE_INT);
        if(!in_array($security,['tls','starttls'],true)||(($security==='tls'&&$port!==465)||($security==='starttls'&&$port!==587)))throw new InvalidArgumentException('Use SMTP TLS on port 465 or STARTTLS on 587.');
        $username=trim((string)($v['username']??''));$from=trim((string)($v['from']??''));$password=(string)($v['password']??'');
        if(strlen($username)<1||strlen($username)>254||preg_match('/[\r\n\0]/',$username)||!filter_var($from,FILTER_VALIDATE_EMAIL)||strlen($from)>254||preg_match('/[<>\r\n\0]/',$from)||strlen($password)<1||strlen($password)>500)throw new InvalidArgumentException('Valid SMTP username, sender email and password required.');
        return ['host'=>strtolower($host),'port'=>$port,'security'=>$security,'username'=>$username,'from'=>$from,'password'=>$password];
    }
    public static function credentials(PDO $db,string $keyHex): ?array {
        if(!function_exists('sodium_crypto_secretbox_open')||!preg_match('/\A[a-f0-9]{64}\z/D',$keyHex))return null;
        $q=$db->prepare('SELECT ciphertext FROM aelvo_provider_secrets WHERE provider=?');$q->execute(['smtp']);$stored=$q->fetchColumn();
        if(!is_string($stored))return null;
        $blob=base64_decode($stored,true);if($blob===false||strlen($blob)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)return null;
        $json=sodium_crypto_secretbox_open(substr($blob,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($blob,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),hex2bin($keyHex));
        if($json===false)return null;
        try{$data=json_decode($json,true,12,JSON_THROW_ON_ERROR);return is_array($data)?self::validate($data):null;}catch(\Throwable){return null;}
    }
    public static function send(array $settings,string $to,string $subject,string $message): bool {
        $s=self::validate($settings);
        if(!filter_var($to,FILTER_VALIDATE_EMAIL)||preg_match('/[<>\r\n\0]/',$to)||strlen($subject)>150||preg_match('/[\r\n\0]/',$subject)||strlen($message)>4000)throw new InvalidArgumentException('Invalid Owner email message.');
        $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$s['host'],'SNI_enabled'=>true]]);
        $remote=($s['security']==='tls'?'tls://':'tcp://').$s['host'].':'.$s['port'];
        $fp=@stream_socket_client($remote,$errno,$errstr,5,STREAM_CLIENT_CONNECT,$ctx);
        if(!is_resource($fp))throw new RuntimeException('SMTP connection unavailable.');
        try {
            stream_set_timeout($fp,8);
            self::expect($fp,[220]);
            self::write($fp,"EHLO aelvo.example\r\n");
            $ehlo=self::expect($fp,[250]);
            if($s['security']==='starttls'){
                if(!preg_match('/\bSTARTTLS\b/i',$ehlo))throw new RuntimeException('SMTP TLS not offered.');
                self::write($fp,"STARTTLS\r\n");self::expect($fp,[220]);
                if(@stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)!==true)throw new RuntimeException('SMTP TLS negotiation failed.');
                self::write($fp,"EHLO aelvo.example\r\n");$ehlo=self::expect($fp,[250]);
            }
            if(!preg_match('/\bAUTH\b[^\r\n]*\bLOGIN\b/i',$ehlo))throw new RuntimeException('SMTP authenticated login unavailable.');
            self::write($fp,"AUTH LOGIN\r\n");self::expect($fp,[334]);
            self::write($fp,base64_encode($s['username'])."\r\n");self::expect($fp,[334]);
            self::write($fp,base64_encode($s['password'])."\r\n");self::expect($fp,[235]);
            self::write($fp,'MAIL FROM:<'.$s['from'].">\r\n");self::expect($fp,[250]);
            self::write($fp,'RCPT TO:<'.$to.">\r\n");self::expect($fp,[250,251]);
            self::write($fp,"DATA\r\n");self::expect($fp,[354]);
            $body=str_replace(["\r\n","\r"],"\n",$message);
            $body=preg_replace('/^\./m','..',$body);
            $headers='From: <'.$s['from'].">\r\nTo: <".$to.">\r\nSubject: ".$subject."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
            self::write($fp,$headers."\r\n".str_replace("\n","\r\n",$body)."\r\n.\r\n");self::expect($fp,[250]);
            self::write($fp,"QUIT\r\n");return true;
        } finally {fclose($fp);}
    }
    private static function write($stream,string $data): void {
        $size=strlen($data);$sent=0;
        while($sent<$size){$n=@fwrite($stream,substr($data,$sent));if($n===false||$n===0)throw new RuntimeException('SMTP write failed.');$sent+=$n;}
    }
    private static function expect($stream,array $codes): string {
        $whole='';
        for($i=0;$i<32;$i++){
            $line=fgets($stream,2048);
            if($line===false || !preg_match('/\A([0-9]{3})([ -])/',$line,$m))throw new RuntimeException('SMTP response invalid.');
            $whole.=$line;
            if(strlen($whole)>16000)throw new RuntimeException('SMTP response too large.');
            if($m[2]===' '){if(!in_array((int)$m[1],$codes,true))throw new RuntimeException('SMTP provider rejected operation.');return $whole;}
        }
        throw new RuntimeException('SMTP response incomplete.');
    }
}
