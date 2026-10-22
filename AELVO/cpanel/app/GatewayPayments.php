<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Hosted payments: a verified charge is a gateway receivable, NOT credited bank cash. */
final class GatewayPayments extends Service {
    /** These states require independently reconciling provider outcome before order operations. */
    public static function blocksOrderAction(string $state): bool {
        return !in_array($state,['Verified','FailedVerified','CancelledVerified'],true);
    }
    /** Caller must lock the order row FIRST before requesting a write lock here.
     * All payment/fulfilment writers share the order -> attempt lock order.
     */
    public static function unresolvedForOrder(PDO $db,int $order,bool $lock=false): ?string {
        $sql="SELECT state FROM aelvo_gateway_attempts WHERE order_id=? AND state NOT IN ('Verified','FailedVerified','CancelledVerified') ORDER BY id LIMIT 1";
        $q=$db->prepare($sql.($lock?' FOR UPDATE':''));$q->execute([$order]);
        $state=$q->fetchColumn();return $state===false?null:(string)$state;
    }
    public static function assertNoUnresolved(PDO $db,int $order): void {
        if(self::unresolvedForOrder($db,$order,true)!==null)
            throw new InvalidArgumentException('An online payment may still be processing. Reconcile the gateway transaction before collecting another payment, cancelling, dispatching or booking courier.');
    }
    public static function enabled(array $c,array $config): bool {
        return ($c['enabled']??false)===true && ($config['payment_gateways_enabled']??false)===true &&
            (($c['mode']??'')==='live'||(($c['mode']??'')==='sandbox'&&($config['gateway_sandbox_mode']??false)===true));
    }
    private static function site(PDO $db): string {
        $v=Settings::get($db,'seo')['value'];$base=rtrim((string)($v['base_url']??''),'/');
        $parsed=parse_url($base);
        if(!$parsed||($parsed['scheme']??'')!=='https'||!isset($parsed['host'])||isset($parsed['user'])||isset($parsed['pass'])||isset($parsed['fragment'])||isset($parsed['query'])||isset($parsed['port']))throw new RuntimeException('A public HTTPS storefront base URL must be configured before gateway use.');
        return $base;
    }
    /** Bound to an already-placed, phone-verified checkout session; no phone lookup or order-id-only access. */
    public static function initiate(PDO $db,string $session,string $checkoutId,string $choice,string $email,string $city,string $key,array $config): string {
        $checkout=(new Checkout($db,$session))->get($checkoutId);
        $order=(int)($checkout['order_id']??0);
        if($order<1)throw new InvalidArgumentException('Place and verify the order before starting payment.');
        if(!in_array($choice,['advance','full'],true))throw new InvalidArgumentException('Choose the payment amount.');
        $email=trim($email);$city=trim($city);
        if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($city)<2||strlen($city)>80||preg_match('/[\x00-\x1f\x7f]/',$city))throw new InvalidArgumentException('Enter a valid contact email and delivery city for the gateway.');
        $c=SslCommerz::credentials($db,$key);
        if(!self::enabled($c,$config))throw new RuntimeException('Online gateway is not enabled.');
        $base=self::site($db);
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT * FROM aelvo_orders WHERE id=? FOR UPDATE');$q->execute([$order]);$o=$q->fetch();
            if(!$o||in_array($o['status'],['Cancelled','Delivered'],true))throw new InvalidArgumentException('Payment is unavailable for this order.');
            $q=$db->prepare('SELECT id FROM aelvo_courier_shipments WHERE order_id=? FOR UPDATE');$q->execute([$order]);
            if($q->fetchColumn()!==false)throw new InvalidArgumentException('Courier booking exists; request an authorized payment reconciliation.');
            $q=$db->prepare('SELECT * FROM aelvo_gateway_attempts WHERE order_id=? ORDER BY created_at DESC,id DESC FOR UPDATE');$q->execute([$order]);$attempts=$q->fetchAll();
            foreach($attempts as $a){
                // No blind retry on timeout / uncertain IPN / unverified failure: money may have moved.
                if(!in_array($a['state'],['FailedVerified','CancelledVerified'],true))throw new InvalidArgumentException('A gateway transaction already exists. Reconcile it in Admin before any new request.');
            }
            $sum=Payments::summary($db,$order);
            if($sum['refunded_minor']>0||Returns::committed($db,$order)>0)
                throw new InvalidArgumentException('An existing refund or refund review must be reconciled before requesting another online payment.');
            $q=$db->prepare("SELECT id FROM aelvo_payment_proofs WHERE order_id=? AND status='Pending' LIMIT 1 FOR UPDATE");
            $q->execute([$order]);
            if($q->fetchColumn()!==false)
                throw new InvalidArgumentException('A manual payment claim is awaiting verification. Resolve it before starting another charge.');
            $due=$sum['due_minor'];$amount=$choice==='advance'?$sum['advance_due_minor']:$due;
            if($amount<1||$amount>$due)throw new InvalidArgumentException('No eligible amount remains for online payment.');
            $id=bin2hex(random_bytes(16));$tran='AEL'.strtoupper(bin2hex(random_bytes(12)));
            $q=$db->prepare("INSERT INTO aelvo_gateway_attempts(id,order_id,mode,tran_id,expected_minor,customer_email,customer_city) VALUES (?,?,?,?,?,?,?)");
            $q->execute([$id,$order,$c['mode'],$tran,$amount,$email,$city]);$db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        // Network calls occur outside DB transactions. An ambiguous outcome stays blocked for review.
        $details=[
            'store_id'=>$c['store_id'],'store_passwd'=>$c['store_password'],'total_amount'=>SslCommerz::money($amount),
            'currency'=>'BDT','tran_id'=>$tran,
            'success_url'=>$base.'/gateway-return.php','fail_url'=>$base.'/gateway-return.php','cancel_url'=>$base.'/gateway-return.php',
            'ipn_url'=>$base.'/gateway-ipn.php','cus_name'=>mb_substr((string)$o['customer_name'],0,80),
            'cus_email'=>$email,'cus_add1'=>mb_substr((string)$o['address'],0,140),
            'cus_city'=>$city,'cus_country'=>'Bangladesh','cus_phone'=>$o['phone'],
            'shipping_method'=>'YES','ship_name'=>mb_substr((string)$o['customer_name'],0,80),
            'ship_add1'=>mb_substr((string)$o['address'],0,140),'ship_city'=>$city,'ship_country'=>'Bangladesh',
            'product_name'=>'AELVO Fashion order '.$o['reference'],'product_category'=>'Fashion','product_profile'=>'general',
            'value_a'=>$id,
        ];
        try{
            $r=SslCommerz::request($c['mode'],'/gwprocess/v4/api.php',$details,true);
            if(($r['status']??'')!=='SUCCESS')throw new RuntimeException('Payment session was not accepted.');
            $url=SslCommerz::hostedUrl($r['GatewayPageURL']??null,$c['mode']);
            $sessionKey=$r['sessionkey']??'';
            if(!is_string($sessionKey)||strlen($sessionKey)>120)throw new RuntimeException('Unexpected gateway session ID.');
            $q=$db->prepare("UPDATE aelvo_gateway_attempts SET state='Ready',gateway_url=?,session_key=? WHERE id=? AND state='Initiating'");
            $q->execute([$url,$sessionKey,$id]);
            // Callback could have already validated a very fast payment; it must not be overwritten.
            return $url;
        }catch(\Throwable $e){
            $q=$db->prepare("UPDATE aelvo_gateway_attempts SET state='Uncertain' WHERE id=? AND state='Initiating'");$q->execute([$id]);
            throw new RuntimeException('Gateway session outcome is uncertain. Do not retry until Admin reconciles the transaction.');
        }
    }
    /** A remote callback is only a claim. Independently request provider validation before any recording. */
    public static function notify(PDO $db,array $posted,string $key): string {
        $tran=$posted['tran_id']??null;$val=$posted['val_id']??null;
        if(!is_string($tran)||!preg_match('/^[a-zA-Z0-9_-]{6,30}$/D',$tran))throw new InvalidArgumentException('Invalid gateway reference.');
        if(!is_string($val)||!preg_match('/^[a-zA-Z0-9_-]{8,60}$/D',$val))throw new InvalidArgumentException('Missing validation ID.');
        if(!in_array($posted['status']??null,['VALID','VALIDATED'],true))return 'PENDING'; // fail/cancel callbacks are untrusted hints
        $q=$db->prepare('SELECT * FROM aelvo_gateway_attempts WHERE tran_id=?');$q->execute([$tran]);$a=$q->fetch();
        if(!$a)throw new InvalidArgumentException('Unknown transaction.');
        if($a['state']==='Verified'&&hash_equals((string)$a['val_id'],$val))return 'VERIFIED';
        if($a['state']==='SandboxValidated'&&hash_equals((string)$a['val_id'],$val))return 'SANDBOX';
        if(in_array($a['state'],['Verified','SandboxValidated'],true))throw new InvalidArgumentException('Different validation ID cannot replace a final payment result.');
        $c=SslCommerz::credentials($db,$key);
        if($a['mode']!==$c['mode'])throw new RuntimeException('Provider environment changed; manual reconciliation required.');
        $result=SslCommerz::request($a['mode'],'/validator/api/validationserverAPI.php',[
            'val_id'=>$val,'store_id'=>$c['store_id'],'store_passwd'=>$c['store_password'],'format'=>'json','v'=>'1'
        ],false);
        try{$validated=SslCommerz::validateResponse($result,$a,$val);}catch(\Throwable $e){
            $q=$db->prepare("UPDATE aelvo_gateway_attempts SET state='Review',provider_result=? WHERE id=? AND state NOT IN ('Verified','SandboxValidated')");
            $q->execute([json_encode(['reason'=>'provider-identity-or-amount-mismatch'],JSON_THROW_ON_ERROR),$a['id']]);
            throw $e;
        }
        $db->beginTransaction();
        try {
            // Lock order first, matching manual receipts and initiation lock order.
            $q=$db->prepare('SELECT * FROM aelvo_orders WHERE id=? FOR UPDATE');$q->execute([$a['order_id']]);$order=$q->fetch();
            $q=$db->prepare('SELECT * FROM aelvo_gateway_attempts WHERE id=? FOR UPDATE');$q->execute([$a['id']]);$current=$q->fetch();
            if($current['state']==='Verified'&&hash_equals((string)$current['val_id'],$val)){$db->commit();return 'VERIFIED';}
            if($current['state']==='SandboxValidated'&&hash_equals((string)$current['val_id'],$val)){$db->commit();return 'SANDBOX';}
            if(in_array($current['state'],['Verified','SandboxValidated'],true))throw new InvalidArgumentException('Attempt was finalized by another notification.');
            // An audited terminal failure cannot be reversed silently by a late notification;
            // similarly, one attempt must not be rebound to a different validation identifier.
            if(in_array($current['state'],['FailedVerified','CancelledVerified'],true))
                throw new RuntimeException('Previously reconciled failure received a conflicting success; require manual investigation.');
            if($current['val_id']!==null&&!hash_equals((string)$current['val_id'],$val))
                throw new InvalidArgumentException('Different validation ID for an existing gateway attempt requires manual reconciliation.');
            $state='Review';
            if($validated['risk'])$state='RiskHold';
            elseif($a['mode']==='sandbox')$state='SandboxValidated';
            elseif($order&&$order['status']!=='Cancelled'&&Payments::summary($db,(int)$order['id'])['refunded_minor']===0&&Returns::committed($db,(int)$order['id'])===0&&Payments::summary($db,(int)$order['id'])['due_minor'] >= (int)$a['expected_minor']){
                $q=$db->prepare('SELECT id FROM aelvo_courier_shipments WHERE order_id=? FOR UPDATE');$q->execute([$order['id']]);
                if($q->fetchColumn()===false){
                    $q=$db->prepare('INSERT INTO aelvo_gateway_receipts(attempt_id,order_id,amount_minor,val_id,bank_reference) VALUES (?,?,?,?,?)');
                    $q->execute([$a['id'],$a['order_id'],$a['expected_minor'],$val,$validated['bank_reference']]);
                    $state='Verified';
                    $sum=Payments::summary($db,(int)$a['order_id']);
                    $q=$db->prepare('UPDATE aelvo_orders SET payment_status=? WHERE id=?');
                    $q->execute([$sum['due_minor']===0?'Paid':'Partial',$a['order_id']]);
                }
            }
            // Store only bounded, non-sensitive verification metadata. Never card numbers or credentials.
            $q=$db->prepare('UPDATE aelvo_gateway_attempts SET state=?,val_id=?,bank_reference=?,provider_result=? WHERE id=?');
            $q->execute([$state,$val,$validated['bank_reference'],json_encode(['verified_status'=>$result['status'],'risk_level'=>$validated['risk']?'1':'0','currency'=>'BDT'],JSON_THROW_ON_ERROR),$a['id']]);
            $q=$db->prepare('INSERT INTO aelvo_audit(actor_id,event) VALUES (NULL,?)');$q->execute(['gateway.validation:'.$state.':'.$a['id']]);
            $db->commit();return strtoupper($state);
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Manually confirmed settlement from a provider: fees and cash are separate, idempotent postings. */
    public function settle(array $v): int {
        $this->allow('payments','edit');$this->allow('finance','edit');
        if(($v['confirmed']??'')!=='1')throw new InvalidArgumentException('Independently confirm the credited funds and withholding in your provider and bank/wallet statements.');
        $attempt=Input::text($v,'attempt_id',32);$account=Input::integer($v['account_id']??null);
        $amount=Input::money($v['received']??null);$fee=Input::money($v['fee']??'0');
        if($amount<1||$fee<0)throw new InvalidArgumentException('A positive bank/wallet credit is required.');
        $reference=Input::text($v,'reference',120);$refKey=GatewayPayoutBatches::referenceKey($reference);$document=Input::text($v,'document_id',32);
        $raw=Input::text($v,'request_key',64);
        if(!preg_match('/^[a-f0-9]{64}$/D',$raw)||!preg_match('/^[a-f0-9]{32}$/D',$attempt)||!preg_match('/^[a-f0-9]{32}$/D',$document))throw new InvalidArgumentException('Reload the form and attach private settlement evidence.');
        $key=hash('sha256','gateway-settlement:'.$this->actor['id'].':'.$raw);
        $hash=hash('sha256',json_encode([$attempt,$account,$amount,$fee,$reference,$document],JSON_THROW_ON_ERROR));
        return $this->transaction(function()use($attempt,$account,$amount,$fee,$reference,$refKey,$document,$key,$hash){
            $q=$this->db->prepare('SELECT * FROM aelvo_gateway_receipts WHERE attempt_id=? FOR UPDATE');$q->execute([$attempt]);$receipt=$q->fetch();
            if(!$receipt)throw new InvalidArgumentException('A verified live gateway receipt is required. Sandbox and risk holds cannot settle.');
            $q=$this->db->prepare('SELECT id,request_hash FROM aelvo_finance_events WHERE request_key=?');$q->execute([$key]);$previous=$q->fetch();
            if($previous){if(!hash_equals($previous['request_hash'],$hash))throw new RuntimeException('The settlement request was changed.',409);$q=$this->db->prepare('SELECT id FROM aelvo_gateway_settlements WHERE event_id=?');$q->execute([$previous['id']]);return (int)$q->fetchColumn();}
            $q=$this->db->prepare('SELECT COALESCE(SUM(received_minor+fee_minor),0) FROM aelvo_gateway_settlements WHERE attempt_id=?');$q->execute([$attempt]);
            $oldPosted=(int)$q->fetchColumn();
            $q=$this->db->prepare('SELECT COALESCE(SUM(credited_minor+fee_minor),0) FROM aelvo_gateway_payout_allocations WHERE attempt_id=?');$q->execute([$attempt]);
            if($amount+$fee>(int)$receipt['amount_minor']-$oldPosted-(int)$q->fetchColumn())throw new InvalidArgumentException('This payout exceeds unsettled gateway funds.');
            $q=$this->db->prepare('SELECT id FROM aelvo_media WHERE id=? AND owner_id=? AND is_public=0');$q->execute([$document,$this->actor['id']]);
            if(!$q->fetchColumn())throw new InvalidArgumentException('Verified private settlement evidence is required.');
            $q=$this->db->prepare("SELECT balance_minor FROM aelvo_accounts WHERE id=? AND active=1 AND kind IN ('Bank','Mobile wallet') FOR UPDATE");$q->execute([$account]);$balance=$q->fetchColumn();
            if($balance===false||(int)$balance+$amount>1000000000000)throw new InvalidArgumentException('Destination account unavailable or balance limit exceeded.');
            $q=$this->db->prepare('SELECT id FROM aelvo_gateway_payout_batches WHERE account_id=? AND bank_reference_key=?');$q->execute([$account,$refKey]);
            if($q->fetchColumn()!==false)throw new RuntimeException('This statement reference was already credited through a payout batch.',409);
            $q=$this->db->prepare('SELECT id FROM aelvo_gateway_settlements WHERE account_id=? AND LOWER(TRIM(reference))=? LIMIT 1');
            $q->execute([$account,strtolower(preg_replace('/ +/',' ',$reference))]);
            if($q->fetchColumn()!==false)throw new RuntimeException('This bank statement reference was already recorded.',409);
            $event=bin2hex(random_bytes(16));
            $q=$this->db->prepare("INSERT INTO aelvo_finance_events(id,request_key,request_hash,kind,explanation,document_id,actor_id) VALUES (?,?,?,'gateway-settlement',?,?,?)");
            $q->execute([$event,$key,$hash,'Actual SSLCommerz payout '.$reference.'; withheld fee '.SslCommerz::money($fee).' BDT',$document,$this->actor['id']]);
            $after=(int)$balance+$amount;$q=$this->db->prepare('UPDATE aelvo_accounts SET balance_minor=? WHERE id=?');$q->execute([$after,$account]);
            $q=$this->db->prepare('INSERT INTO aelvo_ledger(event_id,account_id,delta_minor,balance_after_minor) VALUES (?,?,?,?)');$q->execute([$event,$account,$amount,$after]);
            $q=$this->db->prepare('INSERT INTO aelvo_gateway_settlements(attempt_id,account_id,event_id,received_minor,fee_minor,reference,document_id,actor_id) VALUES (?,?,?,?,?,?,?,?)');
            $q->execute([$attempt,$account,$event,$amount,$fee,$reference,$document,$this->actor['id']]);$id=(int)$this->db->lastInsertId();
            $this->audit('gateway.settlement-recorded:'.$id);return $id;
        });
    }
}
