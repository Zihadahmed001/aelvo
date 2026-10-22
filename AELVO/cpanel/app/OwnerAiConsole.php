<?php
declare(strict_types=1);
namespace Aelvo;

use DomainException;
use InvalidArgumentException;
use PDO;

/** Owner-only verification and exact-change REVIEW. AI inference and execution are not enabled. */
final class OwnerAiConsole {
    private static function service(PDO $db,array $actor,string $keyHex): OwnerAiService {
        self::access($actor);
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$keyHex))throw new DomainException('Owner AI server-side secret is unavailable.');
        $smtp=OwnerMail::credentials($db,$keyHex);
        if($smtp===null)throw new DomainException('SMTP is not configured. Save credentials in Owner Settings first.');
        $key=hash_hmac('sha256','aelvo:owner-ai:challenge:v1',hex2bin($keyHex));
        return new OwnerAiService($db,(int)$actor['id'],$key,static fn(string $email,string $otp):bool=>OwnerMail::send($smtp,$email,'AELVO Owner AI verification code',"Your AELVO Owner AI verification code is: ".$otp."\nExpires in 5 minutes. If this was not you, sign out and review account security."),(array)[],(string)$actor['password_hash']);
    }
    private static function access(array $actor): void {OwnerEmailVerification::identity($actor);}
    public static function handle(PDO $db,array $actor,string $action,array $form,string $keyHex): void {
        self::access($actor); // Security::actor and CSRF were already checked by the controller.
        if($action==='owner-ai-revoke'){unset($_SESSION['owner_ai_grant']);return;}
        if($action==='owner-email-request'){
            $smtp=OwnerMail::credentials($db,$keyHex);
            if(!$smtp)throw new DomainException('Configure SMTP first under Settings.');
            OwnerEmailVerification::request($db,$actor,$keyHex,static fn(string $email,string $otp):bool=>OwnerMail::send($smtp,$email,'AELVO Owner email verification',"Your AELVO original Owner email verification code: ".$otp."\nThis code expires in 5 minutes. Do not share it."));
            return;
        }
        if($action==='owner-email-verify'){
            OwnerEmailVerification::verify($db,$actor,$keyHex,Input::text($form,'code',6));
            // A verified email alone is never an Owner AI grant.
            unset($_SESSION['owner_ai_grant']);return;
        }
        OwnerAiPolicy::owner($actor,(int)$actor['owner_id']);
        $service=self::service($db,$actor,$keyHex);
        if($action==='owner-ai-request'){$service->requestCode($actor);unset($_SESSION['owner_ai_grant']);return;}
        if($action==='owner-ai-verify'){
            $grant=$service->verify($actor,Input::text($form,'code',6),Input::text($form,'password',72));
            $_SESSION['owner_ai_grant']=$grant;
            return;
        }
        $grant=$_SESSION['owner_ai_grant']??null;
        if(!is_array($grant))throw new DomainException('Verify Owner AI access again.');
        OwnerAiPolicy::grant($grant,(int)$actor['id'],time());
        if($action==='owner-ai-propose'){
            $productId=Input::integer($form['product_id']??null);
            $name=Input::text($form,'name',180);
            $nameBn=Input::text($form,'name_bn',180,false);
            $description=Input::text($form,'description',6000,false);
            $descriptionBn=Input::text($form,'description_bn',6000,false);
            $q=$db->prepare("SELECT id,version FROM aelvo_products WHERE id=? AND status<>'Archived'");$q->execute([$productId]);$p=$q->fetch(PDO::FETCH_ASSOC);
            if(!$p)throw new InvalidArgumentException('Product does not exist or has been archived.');
            // Approval is an exact, expiring review record, NOT an execution request.
            $service->propose($actor,$grant,'catalogue.text.update',[
                'product_id'=>$productId,'name'=>$name,'name_bn'=>$nameBn,
                'description'=>$description,'description_bn'=>$descriptionBn,
            ],'product:'.$productId.':v'.(int)$p['version']);
            return;
        }
        if($action==='owner-ai-approve'){
            if(($form['approve_exact']??'')!=='1')throw new DomainException('Explicit exact-change confirmation required.');
            $id=Input::text($form,'proposal_id',32);
            $digest=Input::text($form,'digest',64);
            if(!preg_match('/\A[a-f0-9]{32}\z/D',$id)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest))throw new DomainException('Invalid proposal reference.');
            $service->approve($actor,$grant,$id,$digest);
            // Never call execute from this HTTP controller.
            return;
        }
        throw new DomainException('Unknown Owner AI operation.');
    }
    public static function render(PDO $db,array $actor,string $keyHex): void {
        self::access($actor);
        $e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $form=static function(string $action)use($e):void{
            echo '<form method="post"><input type="hidden" name="csrf" value="'.$e($_SESSION['csrf']??'').'"><input type="hidden" name="action" value="'.$e($action).'">';
        };
        echo '<h1>Owner AI · verification &amp; exact-change review</h1><p><strong>Execution locked.</strong> Only the original Owner may use this workspace. No model requests, financial actions, stock edits, staff permission changes, shell/SQL execution, or code deployments are available here. An approved proposal does not change the storefront. Verified backup, staging and rollback are still required before any future release capability.</p>';
        $hasKey=(bool)preg_match('/\A[a-f0-9]{64}\z/D',$keyHex);
        $smtp=$hasKey?OwnerMail::credentials($db,$keyHex):null;
        echo '<p>Original Owner: '.$e($actor['email']).' · Email: <strong>'.(empty($actor['email_verified_at'])?'Not verified':'Verified').'</strong> · SMTP: <strong>'.($smtp?'Credentials saved, delivery not tested':'Not configured').'</strong>.</p>';
        if(!$smtp || !$hasKey){echo '<p role="status">Owner verification is locked until encrypted SMTP is configured under <a href="?page=settings">Settings</a>. Saving credentials will not mark mail as verified.</p>';return;}
        if(empty($actor['email_verified_at'])){
            echo '<h2>1 · Verify original Owner email</h2><p>A 6-digit code will be sent to your signed-in Owner account email. The message must be actually accepted by SMTP before a code can be used.</p>';
            $form('owner-email-request');echo '<button>Send Owner email code</button></form>';
            $form('owner-email-verify');echo '<label>Code from email<input name="code" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></label><button>Verify email</button></form>';return;
        }
        $grant=$_SESSION['owner_ai_grant']??null;
        $grantReady=false;
        if(is_array($grant)){try{OwnerAiPolicy::grant($grant,(int)$actor['id'],time());$grantReady=true;}catch(DomainException){unset($_SESSION['owner_ai_grant']);}}
        echo '<h2>2 · Reauthorize Owner AI access</h2><p>Owner login + another emailed 6-digit code + current account password. Session-bound grant lasts at most 15 minutes, and becomes idle-invalid after 5 minutes.</p>';
        $form('owner-ai-request');echo '<button>Send Owner AI code</button></form>';
        $form('owner-ai-verify');echo '<label>Email code<input name="code" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required></label><label>Owner password<input name="password" type="password" maxlength="72" autocomplete="current-password" required></label><button>Verify for review access</button></form>';
        if(!$grantReady){echo '<p role="status">Exact-change review is unavailable until reauthorization succeeds.</p>';return;}
        $form('owner-ai-revoke');echo '<button>End Owner AI access</button></form>';
        echo '<h2>3 · Stage a nonfinancial text-only change</h2><p>This is a manual proposal/approval workflow, not generated AI content. No changes are executed. Source product version is recorded for future stale-change checks.</p>';
        $form('owner-ai-propose');
        echo '<label>Product ID<input type="number" name="product_id" min="1" max="100000000" required></label>';
        echo '<label>Product title (English)<input name="name" maxlength="180" required></label>';
        echo '<label>Product title (বাংলা)<input name="name_bn" maxlength="180"></label>';
        echo '<label>Description (English)<textarea name="description" maxlength="6000" rows="3"></textarea></label>';
        echo '<label>Description (বাংলা)<textarea name="description_bn" maxlength="6000" rows="3"></textarea></label><button>Save proposal for exact review (no product update)</button></form>';
        $q=$db->prepare('SELECT id,operation,payload,base_version,digest,status,expires_at FROM aelvo_owner_ai_proposals WHERE owner_id=? ORDER BY expires_at DESC LIMIT 20');$q->execute([(int)$actor['id']]);
        echo '<h2>Recent Owner approvals</h2><p>Exact payload and digest below are immutable. Approvals expire at the stated time and never trigger execution automatically.</p>';
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $p){
            echo '<section class="owner-proposal"><h3>'.$e($p['operation']).' · '.$e($p['status']).'</h3>';
            echo '<p>Base version: '.$e($p['base_version']).' · Expires (UTC): '.$e(gmdate('Y-m-d H:i:s',(int)$p['expires_at'])).'</p>';
            echo '<pre style="overflow-x:auto;white-space:pre-wrap">'.$e($p['payload']).'</pre>';
            echo '<p>SHA-256: <code>'.$e($p['digest']).'</code></p>';
            if($p['status']==='proposed' && (int)$p['expires_at']>time()){
                $form('owner-ai-approve');echo '<input type="hidden" name="proposal_id" value="'.$e($p['id']).'"><input type="hidden" name="digest" value="'.$e($p['digest']).'">';
                echo '<label><input type="checkbox" name="approve_exact" value="1" required> I personally reviewed this exact payload and SHA-256. Approval will NOT apply it.</label><button>Approve exact draft (no execution)</button></form>';
            }
            echo '</section>';
        }
    }
}
