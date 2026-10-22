<?php
declare(strict_types=1);
namespace Aelvo;
if(!isset($actor,$db,$can,$e,$start,$area,$submit))exit;
$requestRegister=(new ReturnOperations($db,$actor))->customerRequests($_GET);
$requestFilters=$requestRegister['filters'];
echo '<section id="customer-returns"><h2>Customer return requests</h2><p>Acceptance provides return instructions only. Physical receipt, inspection, stock posting and refund are separate actions. No automatic return window is assumed.</p>';
echo '<form method="get" class="admin-register-filters"><input type="hidden" name="page" value="returns"><div class="field-grid"><label>Request status<select name="r_status">';
foreach(ReturnOperations::REQUEST_STATUSES as $status)echo '<option value="'.$e($status).'"'.($status===$requestFilters['status']?' selected':'').'>'.$e($status).'</option>';
echo '</select></label><label>Order reference or RMA number<input name="r_q" value="'.$e($requestFilters['q']).'" maxlength="80" placeholder="Order reference or RMA ID"></label></div><button type="submit">Find customer returns</button> <a class="action" href="?page=returns#customer-returns">Clear</a></form>';
echo '<p class="admin-results" role="status">'.number_format($requestRegister['total']).' matching requests · page '.(int)$requestRegister['page'].' of '.(int)$requestRegister['pages'].'</p>';
if(!$requestRegister['rows'])echo '<p>No matching requests.</p>';
foreach($requestRegister['rows'] as $r){
    echo '<section class="admin-case"><h3>RMA-'.(int)$r['id'].' · '.$e($r['reference']).' · '.$e($r['status']).'</h3><p>Order item #'.(int)$r['order_item_id'].' · '.$e($r['product_name'].' / '.$r['variant_label']).' · Requested: '.(int)$r['qty'].' · Physically received: '.(int)$r['received_qty'].'</p><p class="preserve-lines">'.nl2br($e($r['reason'])).'</p>';
    if($r['photo_id'])echo '<a href="return-evidence.php?id='.$e($r['photo_id']).'">View private customer evidence</a>';
    if($r['review_note'])echo '<p><strong>Review:</strong> '.$e($r['review_note']).'</p>';
    if(in_array($r['status'],['Accepted','Partial'],true)&&$can('returns','edit')){
        $url='?page=returns&amp;receive_request='.(int)$r['id'].'&amp;receive_item='.(int)$r['order_item_id'].'#receive-goods';
        echo '<p><a class="action" href="'.$url.'">Receive inspected goods for this request</a></p>';
    }
    if($r['status']==='Pending'&&$can('returns','edit')){
        $start('return-request-review',['return_request_id'=>$r['id'],'version'=>$r['version']]);
        echo '<label>Decision<select name="decision"><option value="Accepted">Accept for physical return</option><option value="Rejected">Reject with explanation</option></select></label>';
        $area('Customer-visible explanation / return instructions','review_note');$submit('Save review');
    }
    echo '</section>';
}
echo '<nav class="admin-register-pager" aria-label="Customer return request pages">';
if($requestRegister['page']>1)echo '<a class="action" href="'.$e(ReturnOperations::url('requests',$requestFilters,$requestRegister['page']-1)).'#customer-returns">&larr; Previous</a>';
if($requestRegister['page']<$requestRegister['pages'])echo '<a class="action" href="'.$e(ReturnOperations::url('requests',$requestFilters,$requestRegister['page']+1)).'#customer-returns">Next &rarr;</a>';
echo '</nav></section>';
