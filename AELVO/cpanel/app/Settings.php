<?php
declare(strict_types=1);
namespace Aelvo;
use InvalidArgumentException;
use RuntimeException;
final class Settings extends Service {
    public static function get(\PDO $db,string $key): array {
        $q=$db->prepare('SELECT payload,version FROM aelvo_settings WHERE setting_key=?');$q->execute([$key]);$row=$q->fetch();
        return $row?['value'=>json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR),'version'=>(int)$row['version']]:['value'=>[],'version'=>0];
    }
    public function theme(array $data): void {
        $this->allow('settings','edit');
        $accent=Input::text($data,'accent',7);$surface=Input::text($data,'surface',7);
        if(!preg_match('/^#[0-9a-f]{6}$/iD',$accent) || !preg_match('/^#[0-9a-f]{6}$/iD',$surface)) throw new InvalidArgumentException('Choose valid theme colours.');
        $this->save('theme',['accent'=>$accent,'surface'=>$surface],Input::integer($data['version']??0,0));
    }
    public function delivery(array $v): void {
        $this->allow('settings','edit');
        $data=['cod_enabled'=>($v['cod_enabled']??'0')==='1','dhaka_minor'=>Input::money($v['dhaka']??''),'outside_minor'=>Input::money($v['outside']??'')];
        if($data['dhaka_minor']>1000000 || $data['outside_minor']>1000000)throw new InvalidArgumentException('Delivery charge must be at most BDT 10,000.');
        $type=Input::text($v,'advance_type',20,false)?:'none';
        if(!in_array($type,['none','fixed','percent'],true))throw new InvalidArgumentException('Choose a valid advance rule.');
        $data['advance_type']=$type;$data['advance_value']=$type==='none'?0:($type==='percent'?Input::integer($v['advance_value']??null,1,100):Input::money($v['advance_value']??null));
        // Optional merchant-defined value tiers. Blank means disabled, never free by default.
        $free=Input::text($v,'free_threshold',20,false);
        $data['free_threshold_minor']=$free===''?0:Input::money($free);
        if($data['free_threshold_minor']>100000000)throw new InvalidArgumentException('Free-delivery threshold is too high.');
        $data['value_tiers']=DeliveryPricing::parseTiers(Input::text($v,'value_tiers',1200,false));
        $highMin=Input::text($v,'high_value_advance_min',20,false);
        $data['high_value_advance_min_minor']=$highMin===''?0:Input::money($highMin);
        if($data['high_value_advance_min_minor']>100000000)throw new InvalidArgumentException('Advance value threshold is too high.');
        $highType=Input::text($v,'high_value_advance_type',20,false)?:'none';
        if(!in_array($highType,['none','fixed','percent'],true))throw new InvalidArgumentException('Choose a valid value-based advance rule.');
        $data['high_value_advance_type']=$data['high_value_advance_min_minor']>0?$highType:'none';
        $data['high_value_advance_value']=$data['high_value_advance_type']==='none'?0:($highType==='percent'?Input::integer($v['high_value_advance_value']??null,1,100):Input::money($v['high_value_advance_value']??null));
        $this->save('delivery',$data,Input::integer($v['version']??0,0));
    }
    /** Do not infer Bangladesh VAT registration or a legal rate. Owner selects policy. */
    public function tax(array $v): void {
        $this->owner();
        $mode=Input::text($v,'mode',16);
        $percent=Input::text($v,'rate_percent',12);
        $rate=TaxPricing::parseRate($percent);
        $shipping=Input::text($v,'shipping_taxable',1);
        if(!in_array($mode,['off','exclusive'],true)||!in_array($shipping,['0','1'],true))
            throw new InvalidArgumentException('Invalid tax configuration.');
        $data=TaxPricing::settings(['mode'=>$mode,'rate_basis_points'=>$mode==='off'?0:$rate,'shipping_taxable'=>$shipping==='1']);
        $this->save('tax',$data,Input::integer($v['version']??0,0));
    }
    public function paymentInstructions(array $v): void {
        $this->owner();$this->save('payment-instructions',['text'=>Input::text($v,'text',6000,false),'text_bn'=>Input::text($v,'text_bn',12000,false)],Input::integer($v['version']??0,0));
    }
    private function save(string $key,array $data,int $version): void {
        $this->transaction(function() use($key,$data,$version) {
            $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if($version===0) { $q=$this->db->prepare('INSERT INTO aelvo_settings(setting_key,payload) VALUES (?,?)');$q->execute([$key,$json]); }
            else { $q=$this->db->prepare('UPDATE aelvo_settings SET payload=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE setting_key=? AND version=?');$q->execute([$json,$key,$version]);if($q->rowCount()!==1) throw new RuntimeException('Settings changed. Reload before saving.',409); }
            $this->audit('settings.saved:'.$key);
        });
    }
    /** Owner-only provider-specific settings. Blank key retains an existing valid key. */
    public function bulkSmsBd(array $v, string $keyHex): void {
        $this->owner();
        if (!function_exists('sodium_crypto_secretbox') ||
            !preg_match('/^[a-f0-9]{64}$/D', $keyHex)) {
            throw new RuntimeException('Configure a private encryption key and PHP Sodium before saving credentials.');
        }
        $senderid = Input::text($v, 'senderid', 30);
        if (!preg_match('/^[A-Za-z0-9]{3,30}$/D', $senderid)) {
            throw new InvalidArgumentException('Use the approved alphanumeric BulkSMSBD Sender ID (3–30 characters).');
        }
        $language = Input::text($v, 'otp_language', 2);
        if (!in_array($language, ['bn', 'en'], true)) throw new InvalidArgumentException('Invalid OTP language.');
        $key = Input::text($v, 'api_key', 300, false);
        if ($key === '') {
            // Never delete or silently clear stored credentials on a blank field.
            $old = Sms::loadCredentials($this->db, $keyHex);
            $key = $old['api_key'];
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $key)) throw new InvalidArgumentException('Invalid API key.');
        $payload = json_encode(['api_key' => $key, 'senderid' => $senderid, 'otp_language' => $language], JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = base64_encode($nonce . sodium_crypto_secretbox($payload, $nonce, hex2bin($keyHex)));
        $this->transaction(function () use ($cipher) {
            $q = $this->db->prepare('INSERT INTO aelvo_provider_secrets(provider,ciphertext) VALUES (?,?) ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext),updated_at=UTC_TIMESTAMP()');
            $q->execute(['bulksmsbd', $cipher]);
            $this->audit('provider.credentials-saved:bulksmsbd');
        });
    }

    /** Owner-only Steadfast adapter credentials; saving does not book a parcel or test connectivity. */
    public function steadfast(array $v,string $keyHex): void {
        $this->owner();
        if(!function_exists('sodium_crypto_secretbox')||!preg_match('/^[a-f0-9]{64}$/D',$keyHex))throw new RuntimeException('Configure encryption first.');
        $api=Input::text($v,'api_key',300,false);$secret=Input::text($v,'secret_key',300,false);
        if($api===''||$secret===''){
            $old=Steadfast::credentials($this->db,$keyHex);
            $api=$api===''?$old['api_key']:$api;
            $secret=$secret===''?$old['secret_key']:$secret;
        }
        if(!Steadfast::validKey($api)||!Steadfast::validKey($secret))throw new InvalidArgumentException('Enter valid Steadfast API and Secret keys.');
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher=base64_encode($nonce.sodium_crypto_secretbox(json_encode(['api_key'=>$api,'secret_key'=>$secret],JSON_THROW_ON_ERROR),$nonce,hex2bin($keyHex)));
        $this->transaction(function()use($cipher){
            $q=$this->db->prepare('INSERT INTO aelvo_provider_secrets(provider,ciphertext) VALUES (?,?) ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext),updated_at=UTC_TIMESTAMP()');
            $q->execute(['steadfast',$cipher]);$this->audit('provider.credentials-saved:steadfast');
        });
    }

    /** Owner-only SSLCommerz credentials and mode. Disabled until explicitly enabled here and in server config. */
    public function sslCommerz(array $v,string $keyHex): void {
        $this->owner();
        if(!function_exists('sodium_crypto_secretbox')||!preg_match('/^[a-f0-9]{64}$/D',$keyHex))throw new RuntimeException('Configure private encryption before saving gateway credentials.');
        $mode=Input::text($v,'mode',10);
        if(!in_array($mode,['sandbox','live'],true))throw new InvalidArgumentException('Choose a valid gateway environment.');
        $enabled=($v['enabled']??'0')==='1';
        $id=Input::text($v,'store_id',30,false);$password=Input::text($v,'store_password',120,false);
        if($id===''||$password===''){
            $old=SslCommerz::credentials($this->db,$keyHex);
            $id=$id!==''?$id:$old['store_id'];$password=$password!==''?$password:$old['store_password'];
        }
        if(!SslCommerz::valid($id,30)||!SslCommerz::valid($password,120))throw new InvalidArgumentException('Invalid SSLCommerz Store ID or Password.');
        $q=$this->db->query("SELECT COUNT(*) FROM aelvo_gateway_attempts WHERE state IN ('Initiating','Ready','Uncertain','Review','RiskHold')");
        if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('Pending gateway attempts exist. Reconcile them before changing credentials or environment.');
        $json=json_encode(['store_id'=>$id,'store_password'=>$password,'mode'=>$mode,'enabled'=>$enabled],JSON_THROW_ON_ERROR);
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher=base64_encode($nonce.sodium_crypto_secretbox($json,$nonce,hex2bin($keyHex)));
        $this->transaction(function()use($cipher,$mode,$enabled){
            $q=$this->db->prepare('INSERT INTO aelvo_provider_secrets(provider,ciphertext) VALUES (?,?) ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext),updated_at=UTC_TIMESTAMP()');
            $q->execute(['sslcommerz',$cipher]);$this->audit('provider.sslcommerz-saved:'.$mode.':'.($enabled?'enabled':'disabled'));
        });
    }

    /** Dedicated Owner-only customer model settings. No connection test or model request on save. */
    public function customerAi(array $v,string $keyHex): void {
        $this->owner();
        if(!function_exists('sodium_crypto_secretbox')||!preg_match('/^[a-f0-9]{64}$/D',$keyHex))throw new RuntimeException('Private Sodium encryption is required.');
        $model=Input::text($v,'model',80);
        if(!CustomerAi::validModel($model))throw new InvalidArgumentException('Select a supported model.');
        $enabled=($v['enabled']??'0')==='1';
        $apiKey=Input::text($v,'api_key',250,false);
        if($apiKey===''){
            $prior=CustomerAi::settings($this->db,$keyHex,false);
            $apiKey=$prior['api_key']??'';
        }
        if(!preg_match('/^sk-[A-Za-z0-9_\-]{12,240}$/D',$apiKey))throw new InvalidArgumentException('Enter a private OpenAI API key; saving is not a connectivity test.');
        $json=json_encode(['api_key'=>$apiKey,'model'=>$model,'enabled'=>$enabled],JSON_THROW_ON_ERROR);
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher=base64_encode($nonce.sodium_crypto_secretbox($json,$nonce,hex2bin($keyHex)));
        $this->transaction(function()use($cipher,$enabled){
            $q=$this->db->prepare('INSERT INTO aelvo_provider_secrets(provider,ciphertext) VALUES (?,?) ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext),updated_at=UTC_TIMESTAMP()');
            $q->execute(['customer-openai',$cipher]);$this->audit('provider.customer-ai-saved:'.($enabled?'enabled':'disabled'));
        });
    }

    /** SMTP is an optional Owner-only credential. Saving never sends email or marks verification complete. */
    public function smtp(array $v,string $keyHex): void {
        $this->owner();
        if(!function_exists('sodium_crypto_secretbox')||!preg_match('/\A[a-f0-9]{64}\z/D',$keyHex))throw new RuntimeException('Private Sodium encryption is required.');
        $old=OwnerMail::credentials($this->db,$keyHex);
        if(trim((string)($v['password']??''))==='' && $old!==null)$v['password']=$old['password'];
        $fields=OwnerMail::validate($v);
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher=base64_encode($nonce.sodium_crypto_secretbox(json_encode($fields,JSON_THROW_ON_ERROR),$nonce,hex2bin($keyHex)));
        $this->transaction(function()use($cipher){
            $q=$this->db->prepare('INSERT INTO aelvo_provider_secrets(provider,ciphertext) VALUES (?,?) ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext),updated_at=UTC_TIMESTAMP()');
            $q->execute(['smtp',$cipher]);$this->audit('provider.smtp-credentials-saved');
        });
    }

    public function provider(array $v,string $keyHex): void {
        $this->owner();
        if(!function_exists('sodium_crypto_secretbox') || !preg_match('/^[a-f0-9]{64}$/D',$keyHex)) throw new RuntimeException('Configure a private encryption key and PHP Sodium before saving credentials.');
        $provider=Input::text($v,'provider',40);
        if(!in_array($provider,['smsnetbd','google','facebook','whatsapp','messenger'],true)) throw new InvalidArgumentException('Unknown provider');
        $secret=Input::text($v,'credentials',16000);
        $decoded=json_decode($secret,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($decoded) || array_is_list($decoded)) throw new InvalidArgumentException('Credentials must be a JSON object.');
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher=base64_encode($nonce.sodium_crypto_secretbox(json_encode($decoded,JSON_THROW_ON_ERROR),$nonce,hex2bin($keyHex)));
        $this->transaction(function() use($provider,$cipher) {
            $q=$this->db->prepare('INSERT INTO aelvo_provider_secrets(provider,ciphertext) VALUES (?,?) ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext),updated_at=UTC_TIMESTAMP()');$q->execute([$provider,$cipher]);$this->audit('provider.credentials-saved:'.$provider);
        });
    }
}
