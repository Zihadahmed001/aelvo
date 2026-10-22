<?php
if(!isset($db,$actor,$e,$start,$submit)){http_response_code(403);exit;}
$threads=\Aelvo\CustomerChat::staffList($db,$actor);
$selected=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);
?>
<h1>Website support conversations</h1>
<p>Both <code>support</code> and <code>channel.website</code> permissions are required. Customer identity is never inferred from a telephone or order reference. Staff takeover stops AI replies; this does not connect WhatsApp or Messenger.</p>
<p><a href="?page=support">← Support tickets</a></p>
<div class="table-scroll"><table>
<thead><tr><th>Conversation</th><th>Mode</th><th>Status</th><th>Agent</th><th>Last activity UTC</th></tr></thead>
<tbody>
<?php foreach($threads as $t): ?>
<tr><td><a href="?page=support&amp;mode=chat&amp;id=<?=(int)$t['id']?>">#<?=(int)$t['id']?></a></td><td><?=$e($t['mode'])?></td><td><?=$e($t['status'])?></td><td><?=$t['assigned_to']!==null?'#'.(int)$t['assigned_to']:'Unassigned'?></td><td><?=$e($t['updated_at'])?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if($selected && $selected>0):
$t=\Aelvo\CustomerChat::staffThread($db,$actor,(int)$selected);
$messages=\Aelvo\CustomerChat::timeline($db,(int)$t['id']);
?>
<section class="chat-workspace">
<h2>Conversation #<?=(int)$t['id']?></h2>
<p>Status: <?=$e($t['status'])?> · Mode: <?=$e($t['mode'])?> · Assignee: <?=$t['assigned_to']!==null?'#'.(int)$t['assigned_to']:'Unassigned'?></p>
<div class="chat-messages" aria-label="Conversation history">
<?php foreach($messages as $m): ?>
<article class="chat-message"><p><strong><?=$e(ucfirst($m['sender']))?></strong> · <time><?=$e($m['created_at'])?> UTC</time></p><p class="preserve-lines"><?=nl2br($e($m['body']))?></p></article>
<?php endforeach; ?>
<?php if(!$messages):?><p>No messages yet.</p><?php endif; ?>
</div>
<?php if($t['status']==='Open'&&\Aelvo\Security::can($db,$actor,'support','edit')&&\Aelvo\Security::can($db,$actor,'channel.website','edit')): ?>
<?php if($t['mode']!=='Human'||$t['assigned_to']===null||((int)$t['assigned_to']!==(int)$actor['id']&&!empty($actor['is_owner']))): ?>
<?php $start('chat-staff',['chat_action'=>'takeover','thread_id'=>$t['id'],'version'=>$t['version']]);$submit('Take over chat (pause AI)'); ?>
<?php endif; ?>
<?php if($t['mode']==='Human'&&(int)($t['assigned_to']??0)===(int)$actor['id']): ?>
<?php $start('chat-staff',['chat_action'=>'reply','thread_id'=>$t['id'],'version'=>$t['version']]); ?>
<label>Public reply (visible only to this conversation owner)<textarea name="message" maxlength="2000" rows="4" required></textarea></label>
<?php $submit('Send reply'); ?>
<?php $start('chat-staff',['chat_action'=>'close','thread_id'=>$t['id'],'version'=>$t['version']]);$submit('Close conversation'); ?>
<?php endif; endif; ?>
</section>
<?php endif; ?>
