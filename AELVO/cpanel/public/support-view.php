<?php
if(!isset($pdo,$page,$lang)){http_response_code(404);exit;}
$_SESSION['support_form_token']??=bin2hex(random_bytes(32));
$success=$_SESSION['support_success']??null;unset($_SESSION['support_success']);
?>
<section class="page support-page">
 <nav aria-label="<?=tr('Breadcrumb','পথ নির্দেশনা')?>"><a href="./"><?=tr('Home','হোম')?></a> / <?=tr('Help & contact','সাহায্য ও যোগাযোগ')?></nav>
 <div class="support-heading">
  <span class="eyebrow">AELVO FASHION / SUPPORT</span>
  <h1><?=tr('How can we help?','আমরা কীভাবে সাহায্য করতে পারি?')?></h1>
  <p><?=tr('Ask a product question, share an order concern or report a website problem.','পণ্য, অর্ডার কিংবা ওয়েবসাইটের সমস্যা নিয়ে আমাদের জানান।')?></p>
 </div>
 <?php if($success!==null):?><div class="support-success" role="status"><h2><?=tr('Request received','আপনার অনুরোধ জমা হয়েছে')?></h2><p><?=tr('Your reference is','আপনার রেফারেন্স নম্বর')?> <strong>#<?=(int)$success?></strong>. <?=tr('Our team will review it. This does not verify a payment or order.','আমাদের টিম এটি দেখবে। এর মাধ্যমে কোনো পেমেন্ট বা অর্ডার যাচাই হয় না।')?></p></div><?php endif;?>
 <p><a class="btn secondary" href="store.php?page=chat"><?=tr('Open support chat','সাপোর্ট চ্যাট খুলুন')?></a></p>
 <div class="support-columns">
  <div class="support-intro"><h2><?=tr('Before sending','পাঠানোর আগে')?></h2><p><?=tr('You can track an order with its own verification process. Do not include a password, OTP, full card details, or payment secrets in your message or screenshot.','অর্ডার ট্র্যাকিংয়ের নিজস্ব যাচাই পদ্ধতি আছে। মেসেজ বা ছবিতে পাসওয়ার্ড, OTP, কার্ড নম্বর বা গোপন পেমেন্ট তথ্য দেবেন না।')?></p>
   <a class="btn secondary" href="store.php?page=tracking"><?=tr('Track my order','অর্ডার ট্র্যাক করুন')?></a>
   <?php if($customer):?>
    <h3><?=tr('Your recent support requests','আপনার সাম্প্রতিক অনুরোধ')?></h3>
    <ul><?php foreach(Aelvo\Support::ownTickets($pdo,(int)$customer['id']) as $ticket):?><li><a href="store.php?page=support&amp;ticket=<?=(int)$ticket['id']?>">#<?=(int)$ticket['id']?> · <?=esc($ticket['subject'])?></a> — <?=esc($ticket['status'])?></li><?php endforeach;?></ul>
    <?php
    $selectedId=filter_var($_GET['ticket']??null,FILTER_VALIDATE_INT);
    $selected=$selectedId&&$selectedId>0?Aelvo\Support::ownDetail($pdo,(int)$customer['id'],$selectedId):null;
    if($selected):?>
    <section class="support-request-detail" aria-labelledby="support-request-title">
     <h3 id="support-request-title">#<?=(int)$selected['id']?> · <?=esc($selected['subject'])?></h3>
     <p><strong><?=tr('Current status','বর্তমান অবস্থা')?>:</strong> <?=esc($selected['status'])?></p>
     <p class="preserve-lines"><?=nl2br(esc($selected['message']))?></p>
     <h4><?=tr('Updates from support','সাপোর্ট টিমের আপডেট')?></h4>
     <?php if(!$selected['updates']):?><p><?=tr('No account updates have been posted yet.','এখনও কোনো আপডেট দেওয়া হয়নি।')?></p><?php endif;?>
     <?php foreach($selected['updates'] as $update):?><article class="support-public-update"><p><strong><?=esc($update['status'])?></strong> · <time><?=esc($update['created_at'])?> UTC</time></p><p class="preserve-lines"><?=nl2br(esc($update['body']))?></p></article><?php endforeach;?>
    </section>
    <?php elseif($selectedId):?><p role="status"><?=tr('That support request is unavailable in this account.','এই অ্যাকাউন্টে অনুরোধটি পাওয়া যায়নি।')?></p><?php endif;?>
   <?php endif;?>
  </div>
  <form method="post" enctype="multipart/form-data" class="support-form">
   <input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>">
   <input type="hidden" name="action" value="support-submit">
   <input type="hidden" name="request_key" value="<?=esc($_SESSION['support_form_token'])?>">
   <label><?=tr('What do you need help with?','কী বিষয়ে সাহায্য দরকার?')?><select name="topic" required><?php $topicsBn=['contact'=>'সাধারণ যোগাযোগ','order'=>'অর্ডার সম্পর্কে প্রশ্ন','product'=>'পণ্য সম্পর্কে প্রশ্ন','bug'=>'ওয়েবসাইটের সমস্যা রিপোর্ট'];foreach(Aelvo\Support::TOPICS as $topic=>$label):?><option value="<?=esc($topic)?>"><?=esc($lang==='bn'?$topicsBn[$topic]:$label)?></option><?php endforeach;?></select></label>
   <label><?=tr('Your name','আপনার নাম')?><input name="customer_name" maxlength="120" autocomplete="name" value="<?=esc($customer['name']??'')?>" required></label>
   <div class="support-fields"><label><?=tr('Email','ইমেইল')?><input type="email" name="email" maxlength="254" autocomplete="email" value="<?=esc($customer['email']??'')?>"></label>
   <label><?=tr('Mobile (Bangladesh)','মোবাইল (বাংলাদেশ)')?><input type="tel" name="phone" maxlength="50" autocomplete="tel" value="<?=esc($customer['phone']??'')?>"></label></div>
   <p class="muted small"><?=tr('Guests must enter at least one contact method. An email or phone here does not sign you in.','গেস্টদের অন্তত একটি যোগাযোগের মাধ্যম দিতে হবে। এতে লগইন হবে না।')?></p>
   <label><?=tr('Order reference (optional, unverified)','অর্ডার রেফারেন্স (ঐচ্ছিক, যাচাই হয়নি)')?><input name="order_reference" maxlength="40"></label>
   <label><?=tr('Subject','বিষয়')?><input name="subject" maxlength="180" required></label>
   <label><?=tr('Describe your request','বিস্তারিত লিখুন')?><textarea name="message" minlength="15" maxlength="6000" rows="6" required></textarea></label>
   <label><?=tr('Screenshot (optional, max 4 MB)','স্ক্রিনশট (ঐচ্ছিক, সর্বোচ্চ ৪ এমবি)')?><input type="file" name="screenshot" accept="image/jpeg,image/png,image/webp"></label>
   <button class="btn" type="submit"><?=tr('Send request','অনুরোধ পাঠান')?></button>
  </form>
 </div>
</section>
