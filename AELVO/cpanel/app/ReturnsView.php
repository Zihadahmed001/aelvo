<?php
declare(strict_types=1);
namespace Aelvo;
// Included only by the authenticated Admin renderer.
if(!isset($actor,$db,$can))exit;
echo '<h1>Returns & refunds</h1><p>Receive and inspect goods, post sellable stock, review a refund, then record its actual payout. Each step is separate.</p>';
require __DIR__.'/ReturnRequestsView.php';
if($can('returns','edit')){
    echo '<h2 id="receive-goods">Receive inspected goods</h2>';
    $prefillRequest=filter_var($_GET['receive_request']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:'';
    $prefillItem=filter_var($_GET['receive_item']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:'';
    $start('return-receive',['request_key'=>bin2hex(random_bytes(32))]);
    $field('Customer request ID (if requested online)','return_request_id',(string)$prefillRequest,'number',false);
    $field('Delivered order-item ID','order_item_id',(string)$prefillItem,'number');$field('Quantity received','qty','1','number');
    $field('Actual received variant ID','received_variant_id','','number');$field('Sellable units accepted','sellable_qty','0','number');
    echo '<p>The remaining units stay damaged/quarantined. Wrong-variant goods must stay quarantined for reconciliation.</p>';
    $area('Customer return reason','reason');$area('Physical inspection findings','inspection');
    echo '<label><input type="checkbox" name="confirmed" value="1" required> I physically received, matched and inspected these goods.</label>';$submit('Record inspection');
}
$itemRegister=(new ReturnOperations($db,$actor))->deliveredItems($_GET);
$itemFilters=$itemRegister['filters'];
echo '<section id="delivered-items"><h2>Delivered item references</h2><p>Find an actual order item before recording physical receipt. Showing items with an unreceived quantity by default.</p>';
echo '<form method="get" class="admin-register-filters"><input type="hidden" name="page" value="returns"><div class="field-grid"><label>Quantity status<select name="i_status">';
foreach(['outstanding'=>'Some units not yet received','all'=>'All delivered items'] as $value=>$label)echo '<option value="'.$e($value).'"'.($itemFilters['status']===$value?' selected':'').'>'.$e($label).'</option>';
echo '</select></label><label>Order reference or item ID<input name="i_q" maxlength="80" value="'.$e($itemFilters['q']).'"></label></div><button type="submit">Find delivered items</button></form>';
echo '<p class="admin-results" role="status">'.number_format($itemRegister['total']).' matching items · page '.(int)$itemRegister['page'].' of '.(int)$itemRegister['pages'].'</p>';
echo '<div class="table-scroll"><table><thead><tr><th>Order</th><th>Item / variant</th><th>Product</th><th>Delivered / received</th><th>Action</th></tr></thead><tbody>';
foreach($itemRegister['rows'] as $r){
    echo '<tr><td>'.$e($r['reference']).' (#'.(int)$r['order_id'].')</td><td>#'.(int)$r['id'].' / #'.(int)$r['variant_id'].'</td><td>'.$e($r['product_name'].' · '.$r['variant_label']).'</td><td>'.(int)$r['qty'].' / '.(int)$r['received'].'</td><td>';
    if($can('returns','edit')&&(int)$r['qty']>(int)$r['received'])echo '<a class="action" href="?page=returns&amp;receive_item='.(int)$r['id'].'#receive-goods">Review physical receipt</a>';
    else echo 'No available quantity or read-only';
    echo '</td></tr>';
}
if(!$itemRegister['rows'])echo '<tr><td colspan="5">No delivered items match the filters.</td></tr>';
echo '</tbody></table></div><nav class="admin-register-pager" aria-label="Delivered item pages">';
if($itemRegister['page']>1)echo '<a class="action" href="'.$e(ReturnOperations::url('items',$itemFilters,$itemRegister['page']-1)).'#delivered-items">&larr; Previous</a>';
if($itemRegister['page']<$itemRegister['pages'])echo '<a class="action" href="'.$e(ReturnOperations::url('items',$itemFilters,$itemRegister['page']+1)).'#delivered-items">Next &rarr;</a>';
echo '</nav></section>';
$receiptRegister=(new ReturnOperations($db,$actor))->inspectedReceipts($_GET);
$receiptFilters=$receiptRegister['filters'];
echo '<section id="receipt-queue"><h2>Received goods &amp; stock disposition</h2><p>Inspected but unposted sellable goods are shown first. Damaged/wrong-variant stock must remain quarantined.</p>';
echo '<form method="get" class="admin-register-filters"><input type="hidden" name="page" value="returns"><div class="field-grid"><label>Stock disposition<select name="s_status">';
foreach(['unposted'=>'Sellable stock awaiting posting','posted'=>'Already posted','all'=>'All inspected receipts'] as $value=>$label)echo '<option value="'.$e($value).'"'.($receiptFilters['status']===$value?' selected':'').'>'.$e($label).'</option>';
echo '</select></label><label>Order reference or return ID<input name="s_q" maxlength="80" value="'.$e($receiptFilters['q']).'"></label></div><button type="submit">Find inspected returns</button></form>';
echo '<p class="admin-results" role="status">'.number_format($receiptRegister['total']).' matching receipts · page '.(int)$receiptRegister['page'].' of '.(int)$receiptRegister['pages'].'</p><div class="table-scroll"><table><thead><tr><th>Return / order</th><th>Received / sellable / quarantined</th><th>Inspection</th><th>Stock action</th></tr></thead><tbody>';
foreach($receiptRegister['rows'] as $r){
    echo '<tr><td>#'.(int)$r['id'].' / '.$e($r['reference']).'</td><td>'.(int)$r['qty'].' / '.($r['sellable_qty']===null?'—':(int)$r['sellable_qty']).' / '.($r['damaged_qty']===null?'—':(int)$r['damaged_qty']).'</td><td>'.$e($r['reason']).'<br>'.$e($r['inspection']??'Legacy receipt').'</td><td>';
    if((int)$r['restocked'])echo 'Stock posted';
    elseif((int)$r['sellable_qty']>0&&$can('returns','edit')&&$can('inventory','edit')){$start('return-restock',['return_id'=>$r['id']]);$submit('Post inspected stock');}
    else echo 'Not restocked';echo '</td></tr>';
}
if(!$receiptRegister['rows'])echo '<tr><td colspan="4">No matching inspected returns.</td></tr>';
echo '</tbody></table></div><nav class="admin-register-pager" aria-label="Inspected return pages">';
if($receiptRegister['page']>1)echo '<a class="action" href="'.$e(ReturnOperations::url('receipts',$receiptFilters,$receiptRegister['page']-1)).'#receipt-queue">&larr; Previous</a>';
if($receiptRegister['page']<$receiptRegister['pages'])echo '<a class="action" href="'.$e(ReturnOperations::url('receipts',$receiptFilters,$receiptRegister['page']+1)).'#receipt-queue">Next &rarr;</a>';
echo '</nav></section>';
// Refund amounts, destinations and payout evidence require finance access too.
if(!$can('finance','view')){echo '<p>Finance view permission is required to review refund information.</p>';return;}
if($can('returns','edit')&&$can('finance','edit')){
    echo '<h2>Propose refund</h2><p>Pending and approved requests reserve their amount against verified receipts. No money moves on proposal or approval. Verify the original payer destination; alternative destinations need a separately agreed policy.</p>';
    $start('refund-propose',['request_key'=>bin2hex(random_bytes(32))]);$field('Order ID','order_id','','number');$field('Return ID (optional for cancellation / adjustment)','return_id','','number',false);$field('Refund amount BDT','amount');
    $field('Tax INCLUDED in refund BDT (0.00 if none)','tax_refund','0.00');
    echo '<p>Explicit tax allocation is required for taxed orders. This tax share is part of (not added to) the refund amount; check the original order tax snapshot and applicable refund policy. Never guess a tax amount.</p>';
    echo '<label>Pay from account<select name="account_id">';foreach($db->query('SELECT id,name FROM aelvo_accounts WHERE active=1') as $a)echo '<option value="'.(int)$a['id'].'">'.$e($a['name']).'</option>';echo '</select></label><label>Method<select name="method">';foreach(['Cash','Bank','bKash','Nagad','Rocket'] as $m)echo '<option>'.$m.'</option>';echo '</select></label>';
    $field('Original payer destination / cash recipient','destination');$area('Refund reason and amount calculation','reason');$submit('Submit for review');
}
$refundRegister=(new ReturnOperations($db,$actor))->refunds($_GET);$refundFilters=$refundRegister['filters'];
echo '<section id="refund-queue"><h2>Refund approval & payout queue</h2><p>A proposal/approval never sends money. Only an independently verified payout, recorded with evidence, credits a completed refund.</p>';
echo '<form method="get" class="admin-register-filters"><input type="hidden" name="page" value="returns"><div class="field-grid"><label>Refund status<select name="f_status">';
foreach(ReturnOperations::REFUND_STATUSES as $status)echo '<option value="'.$e($status).'"'.($refundFilters['status']===$status?' selected':'').'>'.$e($status).'</option>';
echo '</select></label><label>Order reference or refund ID<input name="f_q" maxlength="80" value="'.$e($refundFilters['q']).'"></label></div><button type="submit">Find refunds</button></form>';
echo '<p class="admin-results" role="status">'.number_format($refundRegister['total']).' matching refunds · page '.(int)$refundRegister['page'].' of '.(int)$refundRegister['pages'].'</p>';
if(!$refundRegister['rows'])echo '<p>No refund requests match the filters.</p>';
foreach($refundRegister['rows'] as $r){
    $summary=Payments::summary($db,(int)$r['order_id']);$held=Returns::committed($db,(int)$r['order_id']);
    echo '<section><h3>REF-'.(int)$r['id'].' · '.$e($r['reference']).' · '.$e($r['status']).'</h3><p>Proposed ৳'.number_format($r['amount_minor']/100,2).' · '.$e($r['method']).' · '.$e($r['account_name']).'<br>Destination: '.$e($r['destination']).'<br>Tax included in refund: '.($r['tax_refund_minor']===null?'Unallocated (legacy — review required)':'৳'.number_format((int)$r['tax_refund_minor']/100,2)).'<br>Verified received: ৳'.number_format($summary['paid_minor']/100,2).' · Already refunded: ৳'.number_format($summary['refunded_minor']/100,2).' · Committed: ৳'.number_format($held/100,2).' · Uncommitted: ৳'.number_format(max(0,$summary['net_received_minor']-$held)/100,2).'</p><p>'.$e($r['reason']).'</p>';
    if($r['review_note'])echo '<p>Review: '.$e($r['review_note']).'</p>';
    if($can('returns','edit')&&$can('finance','edit')&&in_array($r['status'],['Pending','Approved'],true)){
        $start('refund-review',['refund_request_id'=>$r['id']]);
        echo '<label>Decision<select name="decision"><option value="Rejected">Reject / revoke unpaid approval</option>';if($r['status']==='Pending')echo '<option value="Approved">Approve this exact amount and destination</option>';echo '</select></label>';
        $area('Review reason / verification details','review_note');echo '<label><input type="checkbox" name="destination_verified" value="1"> I verified this destination against the original payment and payer.</label>';$submit('Save review');
        if($r['status']==='Approved'){
            $start('refund-record',['refund_request_id'=>$r['id'],'request_key'=>bin2hex(random_bytes(32))]);$field('Unique completed payout reference','reference');$area('Payout verification details','reason');
            echo '<label>Payout evidence (required)<input type="file" name="document" accept="application/pdf,image/jpeg,image/png,image/webp" required></label><label><input type="checkbox" name="confirmed" value="1" required> The exact approved amount was paid from this account to this destination. I verified the payout.</label>';$submit('Record completed payout');
        }
    }echo '</section>';
}
echo '<nav class="admin-register-pager" aria-label="Refund request pages">';
if($refundRegister['page']>1)echo '<a class="action" href="'.$e(ReturnOperations::url('refunds',$refundFilters,$refundRegister['page']-1)).'#refund-queue">&larr; Previous</a>';
if($refundRegister['page']<$refundRegister['pages'])echo '<a class="action" href="'.$e(ReturnOperations::url('refunds',$refundFilters,$refundRegister['page']+1)).'#refund-queue">Next &rarr;</a>';
echo '</nav></section>';
echo '<h2>Completed refunds</h2><div class="table-scroll"><table><tr><th>Order</th><th>Amount</th><th>Method / reference</th><th>Evidence</th></tr>';
foreach($db->query('SELECT r.*,e.document_id FROM aelvo_refunds r JOIN aelvo_finance_events e ON e.id=r.event_id ORDER BY r.id DESC LIMIT 100') as $r)echo '<tr><td>#'.(int)$r['order_id'].'</td><td>৳'.number_format($r['amount_minor']/100,2).'</td><td>'.$e($r['method'].' / '.$r['reference']).'</td><td>'.($r['document_id']?'<a href="document.php?id='.$e($r['document_id']).'">Private evidence</a>':'Legacy record').'</td></tr>';
echo '</table></div>';
