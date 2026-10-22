<?php
if(!isset($pdo,$customer,$config,$lang)){http_response_code(403);exit;}
$_SESSION['chat_guest_key']??=bin2hex(random_bytes(32));
$_SESSION['chat_request_key']??=bin2hex(random_bytes(32));
$thread=\Aelvo\CustomerChat::current($pdo,$customer,$_SESSION['chat_guest_key'])??['id'=>0,'status'=>'Open','mode'=>'Human','ai_opt_in'=>0];
$messages=$thread['id']?\Aelvo\CustomerChat::timeline($pdo,(int)$thread['id']):[];
$aiAvailable=\Aelvo\CustomerAi::settings($pdo,$config['encryption_key']??'')!==null;
?>
<section class="page aelvo-chat" aria-labelledby="chat-heading">
<nav aria-label="<?=tr('Breadcrumb','পথ নির্দেশনা')?>"><a href="./"><?=tr('Home','হোম')?></a> / <a href="store.php?page=support"><?=tr('Help','সাহায্য')?></a> / <?=tr('Chat','চ্যাট')?></nav>
<h1 id="chat-heading"><?=tr('Chat with AELVO support','AELVO সাপোর্ট চ্যাট')?></h1>
<p><?=tr('Messages are stored in your conversation. Do not share passwords, OTP, full card details or sensitive payment information. This chat does not verify orders or accept payments.','এখানে বার্তাগুলো সংরক্ষিত থাকে। পাসওয়ার্ড, OTP, কার্ডের তথ্য বা গোপন পেমেন্ট তথ্য পাঠাবেন না। চ্যাট দিয়ে অর্ডার বা পেমেন্ট যাচাই হয় না।')?></p>
<p><strong><?=tr('Status','অবস্থা')?>:</strong> <?=esc($thread['status'])?> · <?=($thread['mode']==='AI' ? tr('AI assistant (with consent)','AI সহকারী (সম্মতিসহ)') : tr('Human support queue','মানব সাপোর্টের অপেক্ষায়'))?></p>
<?php if(!$customer):?><p class="muted"><?=tr('Guest chat stays private to this browser session and will not attach to an account based on phone or email.','গেস্ট চ্যাট শুধু এই ব্রাউজার সেশনে থাকবে। ফোন বা ইমেইল মিলিয়ে অ্যাকাউন্টে যুক্ত হবে না।')?></p><?php endif;?>
<div class="chat-messages" aria-live="polite">
<?php foreach($messages as $m): ?>
<article class="chat-message"><strong><?=esc(match($m['sender']){'customer'=>tr('You','আপনি'),'assistant'=>tr('AELVO AI','AELVO AI'),'staff'=>tr('Support team','সাপোর্ট টিম'),default=>tr('System','সিস্টেম')})?></strong><p class="preserve-lines"><?=nl2br(esc($m['body']))?></p><small><?=esc($m['created_at'])?> UTC</small></article>
<?php endforeach; ?>
<?php if(!$messages):?><p><?=tr('Start a conversation below.','নিচে মেসেজ দিয়ে শুরু করুন।')?></p><?php endif; ?>
</div>
<?php if($thread['status']==='Open'): ?>
<form method="post" class="chat-form">
<input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><input type="hidden" name="action" value="chat-send">
<input type="hidden" name="request_key" value="<?=esc($_SESSION['chat_request_key'])?>">
<label><?=tr('Your message','আপনার মেসেজ')?><textarea name="message" rows="4" maxlength="2000" required></textarea></label>
<?php if(!$messages&&$aiAvailable): ?>
<label><input type="checkbox" name="ai_consent" value="1"> <?=tr('I agree to send my chat messages to the AI provider for automated assistance. I can ask for a person at any time.','স্বয়ংক্রিয় সহায়তার জন্য আমার চ্যাট মেসেজ AI সেবাদাতার কাছে পাঠাতে সম্মতি দিচ্ছি। যেকোনো সময় মানুষের সাহায্য চাইতে পারব।')?></label>
<?php elseif($thread['mode']==='AI'): ?>
<input type="hidden" name="ai_consent" value="1">
<?php endif; ?>
<button class="btn" type="submit"><?=tr('Send message','মেসেজ পাঠান')?></button>
</form>
<?php if($thread['mode']==='AI'): ?>
<form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><input type="hidden" name="action" value="chat-human"><button type="submit" class="btn secondary"><?=tr('Talk to a person (pause AI)','মানুষের সাহায্য চাই (AI বন্ধ)')?></button></form>
<?php endif; ?>
<p><a href="store.php?page=chat"><?=tr('Refresh messages','নতুন মেসেজ দেখুন')?></a> · <a href="store.php?page=support"><?=tr('Submit a support ticket','সাপোর্ট টিকিট পাঠান')?></a></p>
<?php else: ?><p><?=tr('This conversation is closed. Use Help to open a new request.','এই কথোপকথনটি বন্ধ। নতুন সাহায্যের জন্য Help দেখুন।')?></p><a class="btn secondary" href="store.php?page=support"><?=tr('Help & contact','সাহায্য ও যোগাযোগ')?></a><?php endif; ?>
</section>
