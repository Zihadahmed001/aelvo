<?php
declare(strict_types=1);
namespace Aelvo;
final class AdminView {
    /** One list of real Admin module routes, reused for desktop and mobile. */
    private static function moduleGroups(): array {
        return [
            'Workspace'=>['dashboard'=>'Overview','products'=>'Products','inventory'=>'Inventory','orders'=>'Orders'],
            'Operations'=>['purchases'=>'Purchases','courier'=>'Courier','returns'=>'Returns & refunds','support'=>'Support inbox','reviews'=>'Reviews'],
            'Finance'=>['finance'=>'Accounts','payments'=>'Payments','reports'=>'Reports'],
            'Store & growth'=>['content'=>'Banners & pages','seo'=>'SEO','coupons'=>'Coupons'],
            'Administration'=>['settings'=>'Settings','team'=>'Team & permissions','activity'=>'Activity & audit','owner-ai'=>'Owner AI approvals'],
        ];
    }

    private static function permittedModules(\PDO $db, array $actor): array {
        $groups=[];
        foreach(self::moduleGroups() as $heading=>$links){
            foreach($links as $key=>$label){
                if(in_array($key,['team','owner-ai','activity'],true)
                    ? (bool)($actor['is_owner']??false)
                    : Security::can($db,$actor,$key,'view')) $groups[$heading][$key]=$label;
            }
        }
        return $groups;
    }

    /** Render only links authorized by the same RBAC checks as the route handler. */
    public static function navigation(\PDO $db,array $actor,string $page): void {
        echo '<nav class="admin-menu" aria-label="Administration modules">';
        foreach(self::permittedModules($db,$actor) as $heading=>$links){
            echo '<span class="admin-nav-label">'.htmlspecialchars($heading,ENT_QUOTES,'UTF-8').'</span>';
            foreach($links as $key=>$label){
                echo '<a href="?page='.$key.'" '.($page===$key?'aria-current="page"':'').'>'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</a>';
            }
        }
        echo '</nav>';
    }

    /** Pure layout choice. Never includes a tab whose module is not authorized. */
    public static function mobileTabModel(array $permitted, string $page): array {
        $defaults=['dashboard'=>'Overview','orders'=>'Orders','inventory'=>'Stock','finance'=>'Accounts'];
        $tabs=[];
        foreach($defaults as $key=>$label)if(in_array($key,$permitted,true))$tabs[$key]=$label;
        $keys=array_keys($tabs);
        $index=array_search($page,$keys,true);
        return ['tabs'=>$tabs,'active'=>$index===false?count($tabs):$index,
            'more_active'=>$index===false,'count'=>count($tabs)+1];
    }

    private static function mobileIcon(string $key): string {
        // Fixed SVG markup only: no arbitrary user attributes, URLs or icon names.
        $paths=[
            'dashboard'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            'orders'=>'<path d="M5 7h14l-1.5 14h-11L5 7Z"/><path d="M9 8V5a3 3 0 0 1 6 0v3"/>',
            'inventory'=>'<path d="m3 7 9-4 9 4-9 4-9-4Z"/><path d="M3 12l9 4 9-4M3 17l9 4 9-4"/>',
            'finance'=>'<rect x="3" y="6" width="18" height="15" rx="2"/><path d="M3 10h18M6 6V4a1 1 0 0 1 1-1h12"/><circle cx="16.5" cy="15.5" r="1"/>',
            'more'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
        ];
        return '<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.($paths[$key]??$paths['more']).'</svg>';
    }

    /** Permission-filtered in-place module finder (navigation only, never a data search). */
    public static function moduleFinder(\PDO $db,array $actor): void {
        $groups=self::permittedModules($db,$actor);
        echo '<details class="admin-module-finder" data-admin-finder><summary>Find a module <span aria-hidden="true">⌕</span></summary>';
        echo '<div class="admin-module-finder-panel"><label for="admin-module-query">Find an Admin module</label>';
        echo '<input id="admin-module-query" type="search" autocomplete="off" maxlength="80" placeholder="Orders, stock, reports…" data-admin-module-query>';
        echo '<nav aria-label="Authorized module results" data-admin-module-links>';
        foreach($groups as $links)foreach($links as $key=>$label){
            echo '<a href="?page='.$key.'" data-admin-module-link>'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</a>';
        }
        echo '</nav><p class="admin-module-finder-empty" data-admin-module-empty hidden>No matching authorized module.</p></div></details>';
    }

    /** Approved five-position mobile workspace, with permissions checked on every link. */
    public static function mobileNavigation(\PDO $db,array $actor,string $page): void {
        $groups=self::permittedModules($db,$actor);
        $permitted=[];
        foreach($groups as $links)$permitted=array_merge($permitted,array_keys($links));
        $model=self::mobileTabModel($permitted,$page);
        echo '<nav class="admin-mobile-tabs admin-tabs-count-'.(int)$model['count'].' admin-tabs-active-'.(int)$model['active'].'" aria-label="Mobile administration">';
        foreach($model['tabs'] as $key=>$label){
            $active=$page===$key;
            echo '<a href="?page='.$key.'" class="admin-mobile-tab'.($active?' active':'').'" '.($active?'aria-current="page"':'').'>'.self::mobileIcon($key).'<span>'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</span></a>';
        }
        echo '<details class="admin-mobile-more"'.($model['more_active']?' data-active="true"':'').'>';
        echo '<summary class="admin-mobile-tab'.($model['more_active']?' active':'').'" aria-label="More Admin modules">'.self::mobileIcon('more').'<span>More</span></summary>';
        echo '<div class="admin-mobile-more-panel"><strong class="admin-mobile-more-heading">Admin modules</strong>';
        foreach($groups as $heading=>$links){
            echo '<div class="admin-mobile-more-section"><span>'.htmlspecialchars($heading,ENT_QUOTES,'UTF-8').'</span>';
            foreach($links as $key=>$label){
                echo '<a href="?page='.$key.'" '.($page===$key?'aria-current="page"':'').'>'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</a>';
            }
            echo '</div>';
        }
        echo '</div></details></nav>';
    }

    public static function render(\PDO $db,array $actor,string $page,string $encryptionKey): void {
        $e=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $can=fn($m,$a='view')=>Security::can($db,$actor,$m,$a);
        $start=function(string $action,array $hidden=[]) use($e): void { echo '<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.$e($_SESSION['csrf']).'"><input type="hidden" name="action" value="'.$e($action).'">';foreach($hidden as $key=>$value) echo '<input type="hidden" name="'.$e($key).'" value="'.$e($value).'">'; };
        $field=function(string $label,string $name,string $value='',string $type='text',bool $required=true) use($e): void { echo '<label>'.$e($label).'<input name="'.$e($name).'" type="'.$e($type).'" value="'.$e($value).'" '.($required?'required':'').'></label>'; };
        $area=function(string $label,string $name,string $value='') use($e): void { echo '<label>'.$e($label).'<textarea name="'.$e($name).'" rows="4">'.$e($value).'</textarea></label>'; };
        $submit=function(string $label='Save'): void { echo '<button>'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</button></form>'; };

        if ($page==='activity') {
            // The entry route also verifies Owner identity; the service enforces it again.
            $log=new AuditLog($db,$actor);
            $filters=AuditLog::filters($_GET);
            $result=$log->search($filters);
            $qs=static fn(array $fields):string=>http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
            echo '<h1>Activity &amp; audit</h1><p>Original Owner only. Events below are server-recorded actions, not evidence of payment, delivery or stock movement. This legacy log stores actor, event and UTC timestamp; a complete before/after, IP and user-agent trail is not yet implemented.</p>';
            echo '<form class="admin-audit-filters" method="get"><input type="hidden" name="page" value="activity"><div class="field-grid">';
            echo '<label>From (UTC)<input type="date" name="from" required value="'.$e($filters['from']).'"></label>';
            echo '<label>To (UTC)<input type="date" name="to" required value="'.$e($filters['to']).'"></label>';
            echo '<label>Search event<input name="event" maxlength="80" value="'.$e($filters['event']).'" placeholder="e.g. inventory.adjust"></label>';
            echo '<label>Actor ID (blank = all, system = automated)<input name="actor" value="'.$e($filters['actor']).'" placeholder="Owner/staff ID or system"></label>';
            echo '</div><div class="admin-audit-actions"><button type="submit">Filter activity</button> <a class="action" href="?page=activity">Reset</a></div></form>';
            $query=['from'=>$filters['from'],'to'=>$filters['to'],'event'=>$filters['event'],'actor'=>$filters['actor']];
            echo '<p class="admin-results">'.number_format($result['total']).' recorded events · page '.(int)$result['page'].' of '.(int)$result['pages'].'</p>';
            echo '<p><a class="action" href="audit-export.php?'.$e($qs($query)).'">Export most recent 1,000 matching records (CSV)</a></p>';
            echo '<div class="table-scroll admin-audit-table"><table><thead><tr><th>When (UTC)</th><th>Actor</th><th>Recorded action</th><th>Event ID</th></tr></thead><tbody>';
            foreach($result['rows'] as $r){
                $who=$r['actor_id']===null?'System / anonymous':(($r['actor_name']?:'Deleted/unknown staff').' · #'.$r['actor_id']);
                echo '<tr><td><time>'.$e($r['created_at']).'</time></td><td>'.$e($who).'</td><td><code>'.$e($r['event']).'</code></td><td>'.(int)$r['id'].'</td></tr>';
            }
            if(!$result['rows'])echo '<tr><td colspan="4">No audit events match these filters.</td></tr>';
            echo '</tbody></table></div><nav class="admin-register-pager" aria-label="Activity log pages">';
            if($result['page']>1)echo '<a class="action" href="?page=activity&amp;'.$e($qs($query+['p'=>$result['page']-1])).'">← Previous</a>';
            if($result['page']<$result['pages'])echo '<a class="action" href="?page=activity&amp;'.$e($qs($query+['p'=>$result['page']+1])).'">Next →</a>';
            echo '</nav>';return;
        }
        if($page==='reports') {
            $defaultTo=gmdate('Y-m-d');$defaultFrom=gmdate('Y-m-d',strtotime('-29 days'));
            $from=is_string($_GET['from']??null)?$_GET['from']:$defaultFrom;
            $to=is_string($_GET['to']??null)?$_GET['to']:$defaultTo;
            $summary=(new Reports($db,$actor))->snapshot($from,$to);
            echo '<h1>Operational reports</h1><p>Amounts below are independently labelled. Order value is not verified cash, supplier payments are not all COGS, and no unsupported profit figure is shown.</p>';
            echo '<form method="get"><input type="hidden" name="page" value="reports"><div class="field-grid"><label>From (UTC)<input type="date" name="from" value="'.$e($from).'" required></label><label>To (UTC)<input type="date" name="to" value="'.$e($to).'" required></label></div><button type="submit">Apply date range</button></form>';
            echo '<p><a class="action" href="report-export.php?from='.rawurlencode($from).'&amp;to='.rawurlencode($to).'">Export up to 1,000 orders (CSV)</a></p>';
            echo '<div class="metric-grid">';
            $labels=['order_count'=>'Orders placed','order_placed_minor'=>'Order value placed · not cash','delivered_minor'=>'Currently delivered orders created in range · not cash','verified_receipts_minor'=>'Direct/manual verified receipts · excludes gateway/COD','paid_refunds_minor'=>'Recorded paid refunds','recorded_expenses_minor'=>'Recorded operating expenses','purchase_value_minor'=>'Purchases booked · not cash paid','supplier_paid_minor'=>'Supplier payments recorded','returns_count'=>'Return records created','courier_booked_minor'=>'Steadfast COD booked · not cash','courier_collected_minor'=>'Courier reported collected · not cash','courier_remitted_minor'=>'Verified courier cash remitted','courier_fees_minor'=>'Documented courier fees withheld','gateway_validated_minor'=>'Live gateway charges validated · not cash','gateway_cash_settled_minor'=>'Gateway bank/wallet payout credited','gateway_fees_minor'=>'Gateway fees withheld from payouts'];
            foreach($labels as $key=>$label){$value=$summary['measures'][$key];echo '<section><h2>'.(str_ends_with($key,'_minor')?'৳'.number_format($value/100,2):number_format($value)).'</h2><p>'.$e($label).'</p></section>';}
            echo '</div><p><strong>Courier-held receivable right now:</strong> ৳'.number_format($summary['courier_held_minor']/100,2).' (not merchant cash). <strong>Booking attempts needing manual review:</strong> '.(int)$summary['courier_unknown_count'].'.</p><p><strong>Gateway funds not yet independently settled:</strong> ৳'.number_format($summary['gateway_unsettled_minor']/100,2).' (provider receivable, not merchant bank cash). <strong>Gateway attempts requiring reconciliation:</strong> '.(int)$summary['gateway_needs_review_count'].'.</p><h2>Orders by current status</h2><div class="table-scroll"><table><tr><th>Status</th><th>Count</th><th>Order value</th></tr>';
            foreach($summary['orders'] as $r)echo '<tr><td>'.$e($r['status']).'</td><td>'.(int)$r['count'].'</td><td>৳'.number_format((int)$r['total_minor']/100,2).'</td></tr>';
            echo '</table></div><h2>Inventory movements during selected period</h2><div class="table-scroll"><table><tr><th>Movement reason</th><th>Events</th><th>On-hand change</th><th>Reservation change</th></tr>';
            foreach($summary['movements'] as $movement)echo '<tr><td>'.$e($movement['source_type']).'</td><td>'.(int)$movement['entries'].'</td><td>'.(int)$movement['on_hand_change'].'</td><td>'.(int)$movement['reservation_change'].'</td></tr>';
            echo '</table></div><h2>Current sellable stock (not date-filtered)</h2><div class="metric-grid">';
            foreach(['on_hand'=>'On hand','reserved'=>'Reserved','quarantined'=>'Quarantined','available'=>'Available'] as $key=>$label)echo '<section><h2>'.number_format((int)($summary['stock'][$key]??0)).'</h2><p>'.$label.'</p></section>';
            echo '</div><h2>Low-stock and out-of-stock variants (first 100)</h2><div class="table-scroll"><table><tr><th>Product / variant</th><th>SKU</th><th>On hand</th><th>Reserved</th><th>Quarantined</th><th>Available</th></tr>';
            foreach($summary['low_stock'] as $r)echo '<tr><td>'.$e($r['name'].' · '.$r['colour'].' / '.$r['size']).'</td><td>'.$e($r['sku']).'</td><td>'.(int)$r['stock'].'</td><td>'.(int)$r['reserved'].'</td><td>'.(int)$r['quarantined'].'</td><td>'.(int)$r['available'].'</td></tr>';
            echo '</table></div><h2>Current recorded accounts (not date-filtered)</h2><div class="table-scroll"><table><tr><th>Account</th><th>Type</th><th>Recorded balance</th></tr>';
            foreach($summary['accounts'] as $r)echo '<tr><td>'.$e($r['name']).'</td><td>'.$e($r['kind']).'</td><td>৳'.number_format((int)$r['balance_minor']/100,2).'</td></tr>';
            if($can('finance','view')) {
                $costs=(new Reports($db,$actor))->costCoverage($from,$to);
                echo '<h2>FIFO stock valuation &amp; COGS coverage</h2><p>Known COGS below records only delivery postings during the selected UTC period, NOT a full profit figure. Unknown-cost stock is never valued at zero. Remaining stock values are current, not historical. Inventory mismatches require reconciliation before further movement.</p><div class="metric-grid">';
                foreach([
                    ['Known cost of delivered units in period','৳'.number_format($costs['known_cogs_minor']/100,2)],
                    ['Delivered units with known cost',number_format($costs['known_sold_units'])],
                    ['Delivered units with unknown cost',number_format($costs['unknown_sold_units'])],
                    ['Old delivered lines lacking cost history',number_format($costs['historic_delivered_lines_without_cost'])],
                    ['Known value of remaining stocked layers','৳'.number_format($costs['known_remaining_inventory_minor']/100,2)],
                    ['Unpriced units in existing layers',number_format($costs['unknown_inventory_units'])],
                    ['Variants whose physical stock disagrees with layers',number_format($costs['stock_layer_mismatch_variants'])],
                    ['Known cost of manual reductions and write-offs in period','৳'.number_format($costs['known_disposal_cost_minor']/100,2)],
                    ['Write-off units with unknown cost in period',number_format($costs['unknown_disposed_units'])],
                ] as [$label,$value])echo '<section><h2>'.$e($value).'</h2><p>'.$e($label).'</p></section>';
                echo '</div>';
                $performance=(new FinancialPerformance($db,$actor))->period($from,$to);
                echo '<h2>Financial performance · event-date bridge (UTC)</h2>';
                echo '<p>Delivered order totals are recognized on first delivery date; refunds on actual payout date; sellable return cost is credited when posted; write-offs, expenses and provider fees use their own event dates. This is <strong>NOT verified net profit, P&amp;L, tax, or cash flow</strong>. Older refunds may relate to sales outside this date range. No supplier payment is treated as COGS, and gateway/courier collections are not treated as bank cash.</p>';
                echo '<div class="metric-grid">';
                foreach([
                    ['Merchandise subtotal of delivered orders','delivered_merchandise_minor'],
                    ['Delivery charged on delivered orders','delivery_charged_minor'],
                    ['Configured tax charged on delivered orders (excluded from bridge)','tax_charged_minor'],
                    ['Discounts already applied at checkout','discounts_applied_minor'],
                    ['Delivered-order value (net discounts; includes delivery and configured tax)','delivered_minor'],
                    ['Actually paid refunds (regardless of sale date)','paid_refunds_minor'],
                    ['Known delivery COGS','known_cogs_minor'],
                    ['Known sellable-return cost reversal','known_return_reversal_minor'],
                    ['Known disposal/write-off cost','known_disposals_minor'],
                    ['Explicitly refunded tax (included in paid refunds)','tax_refunded_minor'],
                    ['Recorded operating expenses','operating_expenses_minor'],
                    ['Gateway settlement fees','gateway_fees_minor'],
                    ['Courier remittance fees','courier_fees_minor'],
                ] as [$label,$key])echo '<section><h2>৳'.number_format($performance[$key]/100,2).'</h2><p>'.$e($label).'</p></section>';
                echo '</div><p><strong>Recorded-components subtotal (not net profit):</strong> ৳'.number_format($performance['recorded_bridge_minor']/100,2).'. This number is incomplete and must not be used for pricing, profit distribution or tax filings.</p>';
                echo '<h3>Unresolved accounting coverage</h3><div class="metric-grid">';
                foreach([
                    ['Unpriced units sold','unknown_cogs_units'],
                    ['Delivery lines missing FIFO postings','delivered_lines_missing_postings'],
                    ['Sellable returns with unknown cost','unknown_restock_units'],
                    ['Restocked returns missing FIFO layer','return_layers_missing'],
                    ['Disposed units with unknown cost','unknown_disposal_units'],
                    ['Delivered orders without delivery events (historical)','orders_missing_delivery_events'],
                    ['Delivered orders with inconsistent pricing totals','invalid_discount_orders'],
                    ['Taxed orders missing an immutable tax snapshot','taxed_orders_missing_snapshots'],
                    ['Taxed paid refunds missing allocation','taxed_refunds_missing_allocations'],
                ] as [$label,$key])echo '<section><h2>'.number_format($performance[$key]).'</h2><p>'.$e($label).'</p></section>';
                echo '</div><ul>';
                foreach($performance['warnings'] as $warning)echo '<li>'.$e($warning).'</li>';
                echo '</ul><h3>Delivered order cost/refund review (first 100)</h3><p><a class="action" href="financial-performance-export.php?from='.rawurlencode($from).'&amp;to='.rawurlencode($to).'">Export financial review (CSV; 100 delivered orders)</a></p><p>Orders are filtered by the date first delivered; refund and return totals in these rows are lifetime amounts as of this report, not restricted to the date range. This is a review aid, not final realized profit per order.</p>';
                echo '<div class="table-scroll"><table><tr><th>Order</th><th>Delivered UTC</th><th>Order total</th><th>Tax in order</th><th>Recorded COGS</th><th>Cost unknown qty</th><th>Unposted lines</th><th>Paid refunds to date</th><th>Tax included in paid refunds</th><th>Sellable return cost to date</th><th>Unpriced return units</th></tr>';
                foreach((new FinancialPerformance($db,$actor))->deliveredOrderReview($from,$to) as $line){
                    echo '<tr><td>'.$e($line['reference']).'</td><td>'.$e($line['delivered_at']).'</td>';
                    foreach(['total_minor','tax_minor','known_cost_minor'] as $key)echo '<td>৳'.number_format((int)$line[$key]/100,2).'</td>';
                    foreach(['unknown_qty','unposted_lines'] as $key)echo '<td>'.(int)$line[$key].'</td>';
                    foreach(['lifetime_paid_refunds_minor','lifetime_refunded_tax_minor','lifetime_return_reversal_minor'] as $key)echo '<td>৳'.number_format((int)$line[$key]/100,2).'</td>';
                    echo '<td>'.(int)$line['lifetime_unpriced_return_units'].'</td></tr>';
                }
                echo '</table></div>';

                $audit=(new FinanceReconciliation($db,$actor))->inspect();
                echo '<h2>Financial reconciliation and exceptions</h2><p>Read-only current-state checks, not restricted by the date selector. Exceptions require independent evidence and audited corrections; no balances are automatically changed.</p><p><a class="action" href="reconciliation-export.php">Export current exceptions (CSV; up to 100 per category)</a></p>';
                echo '<div class="metric-grid">';
                foreach([
                    ['Supplier due on received purchases','৳'.number_format($audit['supplier_due_minor']/100,2)],
                    ['Account / ledger variances',count($audit['account_variances'])],
                    ['Unbalanced transfers',count($audit['unbalanced_transfers'])],
                    ['Cash posting discrepancies',count($audit['cash_posting_issues'])],
                    ['Supplier overpayments', $audit['supplier_overpaid_count']],
                    ['Received lines missing landed cost',$audit['uncosted_purchase_lines']],
                    ['Orders with receipt / refund exceptions',count($audit['order_issues'])],
                    ['Courier booking attempts to review',$audit['courier_pending_count']],
                    ['Gateway payment attempts to review',$audit['gateway_pending_count']],
                ] as [$label,$value]) echo '<section><h2>'.$e($value).'</h2><p>'.$e($label).'</p></section>';
                echo '</div>';
                foreach([
                    'Account balance different from ledger'=>['accounts',$audit['account_variances'],['Account','Recorded','Ledger sum','Last snapshot','Variance'],fn($r)=>[$r['name'],'৳'.number_format($r['balance_minor']/100,2),'৳'.number_format($r['posted_minor']/100,2),'৳'.number_format($r['latest_snapshot_minor']/100,2),'৳'.number_format($r['variance_minor']/100,2)]],
                    'Transfer ledger not balanced'=>['transfers',$audit['unbalanced_transfers'],['Event','Entries','Net change'],fn($r)=>[$r['id'],$r['entries'],'৳'.number_format($r['net_minor']/100,2)]],
                    'Cash documents / ledger postings disagree'=>['cash_events',$audit['cash_posting_issues'],['Event','Kind','Expected cash delta','Actual ledger delta'],fn($r)=>[$r['id'],$r['kind'],'৳'.number_format($r['expected_minor']/100,2),'৳'.number_format($r['posted_minor']/100,2)]],
                    'Unpaid or overpaid received suppliers'=>['suppliers',$audit['supplier_dues'],['Purchase','Supplier','Due','Overpaid'],fn($r)=>[$r['reference'],$r['supplier'],'৳'.number_format($r['outstanding_minor']/100,2),'৳'.number_format($r['overpaid_minor']/100,2)]],
                    'Order payment / refund exceptions'=>['orders',$audit['order_issues'],['Order','Total','Recorded collections','Refunds'],fn($r)=>[$r['reference'],'৳'.number_format($r['total_minor']/100,2),'৳'.number_format($r['collected_minor']/100,2),'৳'.number_format($r['refunded_minor']/100,2)]],
                    'Courier COD exceptions'=>['courier',$audit['courier_issues'],['Invoice','Booked COD','Collected','Remittance + fees'],fn($r)=>[$r['invoice'],'৳'.number_format($r['cod_minor']/100,2),'৳'.number_format($r['collected_minor']/100,2),'৳'.number_format(((int)$r['remitted_minor']+(int)$r['fee_minor'])/100,2)]],
                    'Gateway validation / payout exceptions'=>['gateway',$audit['gateway_issues'],['Transaction','State','Expected','Validated','Paid + fees'],fn($r)=>[$r['tran_id'],$r['state'],'৳'.number_format($r['expected_minor']/100,2),'৳'.number_format($r['validated_minor']/100,2),'৳'.number_format(((int)$r['settled_minor']+(int)$r['fee_minor'])/100,2)]],
                ] as $heading=>[$type,$rows,$headers,$cells]) {
                    if(!$rows&&!$audit['truncated'][$type])continue;
                    echo '<h3>'.$e($heading).'</h3>';
                    if($audit['truncated'][$type])echo '<p>First 100 exceptions only; additional records require a larger audited export.</p>';
                    if(!$rows)continue;
                    echo '<div class="table-scroll"><table><thead><tr>';
                    foreach($headers as $header)echo '<th>'.$e($header).'</th>';
                    echo '</tr></thead><tbody>';
                    foreach($rows as $record){echo '<tr>';foreach($cells($record) as $cell)echo '<td>'.$e($cell).'</td>';echo '</tr>';}
                    echo '</tbody></table></div>';
                }
            }
            echo '</table></div><p><strong>Not a profit &amp; loss statement.</strong> COGS cost-layer reconciliation, COD exceptions, independent provider payout confirmation and full expense coverage remain incomplete. Never use this screen as a final profit, VAT or tax report.</p>';
            return;
        }
        if($page==='support') {
            if(($_GET['mode']??'')==='chat'){
                CustomerChat::authorizedStaff($db,$actor,'view');
                require dirname(__DIR__).'/public/admin-chat-view.php';return;
            }
            if($can('support','view')&&$can('channel.website','view'))echo '<p><a class="action" href="?page=support&amp;mode=chat">Website live conversations</a></p>';
            echo '<h1>Customer support inbox</h1><p>Internal notes stay private. Explicit customer-visible updates appear only in the original signed-in account that submitted the ticket. Guest tickets cannot receive account updates. No SMS or email is sent by this screen.</p>';
            $service=new Support($db,$actor);
            $filter=is_string($_GET['status']??null)?$_GET['status']:'';
            $currentPage=filter_var($_GET['p']??1,FILTER_VALIDATE_INT)?:1;
            $result=$service->list($filter,$currentPage);
            echo '<nav class="admin-menu"><a href="?page=support">All</a>';
            foreach(Support::STATUSES as $status)echo '<a href="?page=support&amp;status='.rawurlencode($status).'">'.$e($status).'</a>';
            echo '</nav><p>Total matches: '.(int)$result['total'].'</p><div class="table-scroll"><table><thead><tr><th>Ticket</th><th>Topic</th><th>Customer</th><th>Subject</th><th>Status</th><th>Received (UTC)</th></tr></thead><tbody>';
            foreach($result['rows'] as $row)echo '<tr><td><a href="?page=support&amp;id='.(int)$row['id'].'">#'.(int)$row['id'].'</a></td><td>'.$e($row['topic']).'</td><td>'.$e($row['customer_name']).'</td><td>'.$e($row['subject']).'</td><td>'.$e($row['status']).'</td><td>'.$e($row['created_at']).'</td></tr>';
            echo '</tbody></table></div>';
            if($result['page']>1)echo '<a class="action" href="?page=support&amp;p='.($result['page']-1).'&amp;status='.rawurlencode($filter).'">Previous</a> ';
            if($result['page']*25<$result['total'])echo '<a class="action" href="?page=support&amp;p='.($result['page']+1).'&amp;status='.rawurlencode($filter).'">Next</a>';
            if(isset($_GET['id'])){
                $ticket=$service->detail(Input::integer($_GET['id']));
                echo '<section><h2>Ticket #'.(int)$ticket['id'].'</h2><p>Topic: '.$e($ticket['topic']).' · Status: '.$e($ticket['status']).'</p>';
                echo '<p><strong>Name:</strong> '.$e($ticket['customer_name']).' · <strong>Email:</strong> '.$e($ticket['email']).' · <strong>Phone:</strong> '.$e($ticket['phone']).'</p>';
                echo '<p><strong>Unverified reference:</strong> '.$e($ticket['order_reference']).'</p><h3>'.$e($ticket['subject']).'</h3><p class="preserve-lines">'.nl2br($e($ticket['message'])).'</p>';
                foreach($ticket['attachments'] as $attachment)echo '<p><a href="support-attachment.php?id='.$e($attachment['id']).'">Private screenshot: '.$e($attachment['original_name']).'</a></p>';
                echo '<h3>Internal history</h3><ul>';
                foreach($ticket['events'] as $event)echo '<li>'.$e($event['created_at']).' · '.$e($event['status']).' · '.$e($event['actor_name']??'Customer').': '.$e($event['note']).'</li>';
                echo '</ul><h3>Customer-visible updates</h3>';
                if(empty($ticket['public_updates']))echo '<p>No customer-visible updates yet.</p>';
                foreach($ticket['public_updates'] as $update)echo '<p class="preserve-lines"><strong>'.$e($update['created_at']).' · '.$e($update['status']).'</strong> — '.nl2br($e($update['body'])).'</p>';
                if($can('support','edit')){
                    $start('support-update',['id'=>$ticket['id'],'version'=>$ticket['version']]);
                    echo '<label>Status<select name="status">';
                    foreach(Support::STATUSES as $status)echo '<option value="'.$e($status).'"'.($status===$ticket['status']?' selected':'').'>'.$e($status).'</option>';
                    echo '</select></label><label>Assign to active support staff (optional)<select name="assigned_to"><option value="">Unassigned</option>';
                    foreach($db->query("SELECT id,name FROM aelvo_users WHERE status='Active' ORDER BY name") as $staff)if((int)$staff['id']===(int)$actor['id']||$staff['id']===$ticket['assigned_to']||$actor['is_owner'])echo '<option value="'.(int)$staff['id'].'"'.((int)($ticket['assigned_to']??0)===(int)$staff['id']?' selected':'').'>'.$e($staff['name']).'</option>';
                    echo '</select></label>';
                    $area('Internal action note (required; never customer-visible)','note');
                    if($ticket['customer_id']!==null){
                        $area('Customer-visible update (optional, max 2,000 bytes; saved to their signed-in account)','public_message');
                        echo '<p>Only the original authenticated customer can see this update in Help &amp; contact. No email, SMS, or external message is sent.</p>';
                    }else echo '<p>Guest ticket: no authenticated account is linked. Public updates are disabled; staff can use private notes only.</p>';
                    $submit('Save ticket update');
                }
                echo '</section>';
            }
            return;
        }
        if($page==='owner-ai') { OwnerAiConsole::render($db,$actor,$encryptionKey);return; }
        if($page==='dashboard') {
            echo '<div class="admin-page-heading"><div><span class="admin-eyebrow">Live operations · your authorized modules</span><h1>Welcome, '.$e($actor['name']).'</h1><p>Review what needs attention. These are current database counts, not sales, cash receipts or profit.</p></div></div>';
            $metrics=(new AdminWorkspace($db,$actor))->overview();
            if(!$metrics)echo '<p>No operational modules have been assigned to your account. Ask the Owner for the access you need.</p>';
            else {
                echo '<div class="metric-grid admin-overview-metrics">';
                foreach($metrics as $m){
                    $href='?page='.rawurlencode($m['page']).($m['query']!==''?'&amp;'.$e($m['query']):'');
                    echo '<a class="admin-metric-link" href="'.$href.'"><span class="admin-metric-value">'.number_format($m['count']).'</span><span class="admin-metric-label">'.$e($m['label']).'</span><span class="admin-metric-cta">Open module &rarr;</span></a>';
                }
                echo '</div>';
            }
            echo '<section class="admin-guidance"><h2>Operational rules</h2><p>Unverified payment claims, gateway validation, courier collection and account credits are distinct records. Check each module before changing order or financial status. Owner AI and code releases stay locked until their full safety requirements have been verified.</p></section>';
            return;
        }
        if($page==='products') {
            $result=(new AdminWorkspace($db,$actor))->products($_GET);
            $f=$result['filters'];
            echo '<div class="admin-page-heading"><div><span class="admin-eyebrow">Catalogue</span><h1>Products</h1><p>Browse every active or draft product without a 200-record cutoff. Search names, slugs or variant SKUs.</p></div>';
            if($can('products','create'))echo '<a class="action" href="?page=products#product-editor">Add product</a>';
            echo '</div><form class="admin-register-filters" method="get"><input type="hidden" name="page" value="products">';
            echo '<label>Search products<input name="q" type="search" maxlength="100" value="'.$e($f['q']).'" placeholder="Name, URL or SKU"></label>';
            echo '<label>Status<select name="status"><option value="">Active &amp; draft</option>';
            foreach(['Active','Draft'] as $status)echo '<option value="'.$status.'"'.($f['status']===$status?' selected':'').'>'.$status.'</option>';
            echo '</select></label><button type="submit">Apply filters</button><a class="action" href="?page=products">Clear</a></form>';
            echo '<p class="admin-results" role="status">'.number_format($result['total']).' matching products · page '.(int)$result['page'].' of '.(int)$result['pages'].'</p>';
            echo '<div class="table-scroll"><table><thead><tr><th>Product</th><th>Family</th><th>Price</th><th>Variants</th><th>Sellable units</th><th>Status</th><th>Action</th></tr></thead><tbody>';
            foreach($result['rows'] as $row){
                echo '<tr><td><strong>'.$e($row['name']).'</strong><small class="admin-subtext">/'.$e($row['slug']).(!empty($row['is_featured'])?' · Featured':'').(!empty($row['is_new_arrival'])?' · New arrival':'').'</small></td><td>'.$e($row['category']).'</td><td>'.($row['sale_price_minor']!==null?'<s>৳'.number_format((int)$row['price_minor']/100,2).'</s><br>':'').'৳'.number_format((int)($row['sale_price_minor']??$row['price_minor'])/100,2).'</td><td>'.(int)$row['variant_count'].'</td><td>'.(int)$row['available_units'].'</td><td><span class="admin-status">'.$e($row['status']).'</span></td><td><a class="action" href="?page=products&amp;id='.(int)$row['id'].'#product-editor">'.($can('products','edit')?'Edit':'View').'</a>';
                // Keep existing RBAC-checked archival action; never turn it into a GET link.
                if($can('products','delete')){$start('product-delete',['id'=>$row['id']]);$submit('Archive');}
                echo '</td></tr>';
            }
            if(!$result['rows'])echo '<tr><td colspan="7">No products match. Clear your filters or add a product.</td></tr>';
            echo '</tbody></table></div>';
            $pagerBase='?page=products&amp;q='.rawurlencode($f['q']).'&amp;status='.rawurlencode($f['status']).'&amp;p=';
            echo '<nav class="admin-register-pager" aria-label="Product pages">';
            if($result['page']>1)echo '<a class="action" href="'.$pagerBase.($result['page']-1).'">&larr; Previous</a>';
            if($result['page']<$result['pages'])echo '<a class="action" href="'.$pagerBase.($result['page']+1).'">Next &rarr;</a>';
            echo '</nav><div id="product-editor"></div>';
            $p=[];if(isset($_GET['id'])) { $q=$db->prepare('SELECT * FROM aelvo_products WHERE id=?');$q->execute([Input::integer($_GET['id'])]);$p=$q->fetch()?:[]; }
            if($p && !$can('products','edit')) { echo '<section><h2>'.$e($p['name']).'</h2><p>'.$e($p['description']).'</p><p>'.$e($p['details']).'</p></section>';return; }
            if(!$can('products',$p?'edit':'create')) return;
            echo '<h2>'.($p?'Edit product':'Add product').'</h2>';
            if ($p) echo '<p>Reserved public URL: <code>/'.$e(PublicRoutes::productPath($p['slug'])).'</code>. Active products use this canonical address. Changing it reserves the old address for a permanent 301 redirect.</p>';
            $currentDetails=$p?(ProductDetails::forProducts($db,[(int)$p['id']])[(int)$p['id']]??[]):[];
            $start('product-save',['id'=>$p['id']??'','version'=>$p['version']??'']);
            echo '<div class="field-grid">';foreach(['name'=>'Name — English','name_bn'=>'Name — বাংলা','slug'=>'URL slug'] as $key=>$label) $field($label,$key,$p[$key]??'','text',$key!=='name_bn');
            echo '<label>Category<select name="category">';foreach(['Shoes','Bags','Wallets','Socks','Belts'] as $category) echo '<option '.(($p['category']??'')===$category?'selected':'').'>'.$category.'</option>';echo '</select></label>';
            $currentTaxonomy=null;
            if($p){$taxQ=$db->prepare('SELECT category_id,brand_id FROM aelvo_product_taxonomy WHERE product_id=?');$taxQ->execute([$p['id']]);$currentTaxonomy=$taxQ->fetch()?:null;}
            echo '<label>Specific category<select name="category_id"><option value="0">Default category for selected family</option>';
            foreach(Taxonomy::listings($db,'category') as $cat)if($cat['status']==='Active') echo '<option value="'.(int)$cat['id'].'"'.((int)($currentTaxonomy['category_id']??0)===(int)$cat['id']?' selected':'').'>'.$e($cat['root_code'].' / '.$cat['name']).'</option>';
            echo '</select></label><label>Brand<select name="brand_id"><option value="0">Unbranded</option>';
            foreach(Taxonomy::listings($db,'brand') as $brand)if($brand['status']==='Active')echo '<option value="'.(int)$brand['id'].'"'.((int)($currentTaxonomy['brand_id']??0)===(int)$brand['id']?' selected':'').'>'.$e($brand['name']).'</option>';
            echo '</select></label>';
            $field('Regular price (BDT)','price',isset($p['price_minor'])?number_format($p['price_minor']/100,2,'.',''):'0.00');
            $field('Sale price (BDT, optional; inheriting variants only)','sale_price',isset($p['sale_price_minor']) && $p['sale_price_minor']!==null?number_format((int)$p['sale_price_minor']/100,2,'.',''):'','text',false);
            echo '<label><input type="hidden" name="is_featured" value="0"><input type="checkbox" name="is_featured" value="1"'.(!empty($p['is_featured'])?' checked':'').'> Featured product (manual)</label>';
            echo '<label><input type="hidden" name="is_new_arrival" value="0"><input type="checkbox" name="is_new_arrival" value="1"'.(!empty($p['is_new_arrival'])?' checked':'').'> New arrival (manual, not automatically time-based)</label>';
            echo '<label>Status<select name="status"><option>Draft</option><option '.(($p['status']??'')==='Active'?'selected':'').'>Active</option></select></label></div>';
            $area('Description — English','description',$p['description']??'');$area('Description — বাংলা','description_bn',$p['description_bn']??'');$area('Product information','details',$p['details']??'');
            echo '<h3>Factual product details</h3><p>Optional fields are merchant-supplied. No assumed weight, materials, barcode or shipping cost. Measurements are grams and millimetres; product SKU is independent of each variant SKU.</p><div class="field-grid">';
            foreach(['parent_sku'=>'Product SKU','barcode'=>'Barcode','short_en'=>'Short description — English','short_bn'=>'Short description — বাংলা','material_en'=>'Material — English','material_bn'=>'Material — বাংলা','care_en'=>'Care instructions — English','care_bn'=>'Care instructions — বাংলা','weight_grams'=>'Package weight (g)','length_mm'=>'Length (mm)','width_mm'=>'Width (mm)','height_mm'=>'Height (mm)','tags_csv'=>'Tags (comma-separated)','low_stock_alert'=>'Low stock threshold (units)'] as $key=>$label){
                $source=$key==='tags_csv'?'tags':$key;
                $value=$currentDetails[$key]??($key==='low_stock_alert'?'5':'');
                $field($label,$source,$value,'text',false);
            }
            echo '</div>';$submit();
            if(!$p) return;
            echo '<h2>Product SEO</h2><p>Empty fields inherit the product and global defaults. The global staging noindex setting cannot be overridden. Canonical targets must be current active AELVO product routes, never arbitrary URLs.</p>';
            $effective=Seo::metadata($db,'product',$p,'en');
            echo '<section><p><strong>Current search preview:</strong> '.$e($effective['title']).'</p><p>'.$e($effective['description']).'</p><p>'.$e($effective['canonical']).' · '.$e($effective['robots']).'</p></section>';
            $seo=Seo::product($db,(int)$p['id']);
            if($can('seo','edit')){
                $start('seo-product-save',['product_id'=>$p['id'],'version'=>$seo['version']]);
                $field('SEO title (blank = inherit)','title',$seo['title'],'text',false);
                $area('Meta description (blank = inherit)','description',$seo['description']);
                $field('Canonical product path (blank = self)','canonical_path',$seo['canonical_path'],'text',false);
                $field('Open Graph title (blank = inherit)','og_title',$seo['og_title'],'text',false);
                $area('Open Graph description (blank = inherit)','og_description',$seo['og_description']);
                $field('Open Graph photo ID (blank = first public photo)','og_image_id',$seo['og_image_id']??'','text',false);
                foreach(['index_mode'=>['inherit'=>'Inherit global','index'=>'Allow indexing if site enabled','noindex'=>'No indexing'],'follow_mode'=>['inherit'=>'Inherit follow','follow'=>'Follow links','nofollow'=>'Do not follow']] as $setting=>$choices){
                    echo '<label>'.$e($setting).'<select name="'.$e($setting).'">';
                    foreach($choices as $value=>$label)echo '<option value="'.$e($value).'"'.($seo[$setting]===$value?' selected':'').'>'.$e($label).'</option>';
                    echo '</select></label>';
                }
                $submit('Save product SEO');
            }
            echo '<h2>Colour & size variants</h2><p>Blank price override inherits the product base or optional sale price. A variant override is independent of product-level sale. Material/style are optional descriptive attributes. Stock remains ledger-managed.</p><table><tr><th>Colour</th><th>Size</th><th>SKU</th><th>Material / style</th><th>Available</th><th>Effective price</th><th>Edit</th></tr>';
            $q=$db->prepare('SELECT v.*,COALESCE(q.quantity,0) AS quarantined FROM aelvo_variants v LEFT JOIN aelvo_variant_quarantine q ON q.variant_id=v.id WHERE v.product_id=?');$q->execute([$p['id']]);foreach($q as $v) {
                $effective=VariantPricing::effective((int)$p['price_minor'],$v['price_override_minor'],$p['sale_price_minor']===null?null:(int)$p['sale_price_minor']);
                echo '<tr><td>'.$e($v['colour']).'</td><td>'.$e($v['size']).'</td><td>'.$e($v['sku']).'</td><td>'.$e(trim($v['material'].' / '.$v['style'],' /')).'</td><td>'.((int)$v['stock']-(int)$v['reserved']-(int)$v['quarantined']).'</td><td>৳'.number_format($effective/100,2).'</td><td>';
                $start('variant-price',['product_id'=>$p['id'],'variant_id'=>$v['id'],'pricing_version'=>$v['pricing_version']]);
                echo '<label>Override BDT <input type="text" inputmode="decimal" name="price_override" maxlength="12" value="'.($v['price_override_minor']===null?'':$e(number_format((int)$v['price_override_minor']/100,2,'.',''))).'" placeholder="Inherit base"></label>';
                $submit('Update price');
                $start('variant-attributes',['product_id'=>$p['id'],'variant_id'=>$v['id'],'attributes_version'=>$v['attributes_version']]);
                $field('Material','material',$v['material'],'text',false);$field('Style','style',$v['style'],'text',false);
                $submit('Update attributes');echo '</td></tr>';
            }echo '</table>';
            $start('variant-create',['product_id'=>$p['id']]);echo '<div class="field-grid">';$field('Colour name','colour');$field('Colour swatch','colour_hex','#466b5c','color');$field('Size','size');$field('SKU','sku');$field('Override price BDT (optional)','price_override','','text',false);$field('Material (optional)','material','','text',false);$field('Style (optional)','style','','text',false);echo '</div>';$submit('Add variant');
            echo '<h2>Product photos</h2><p>Upload distinct colour photos, then choose the primary storefront image. The remaining gallery order is preserved; only existing photos may be selected.</p><div class="photos">';
            $q=$db->prepare('SELECT pm.media_id,pm.colour,pm.position FROM aelvo_product_media pm WHERE pm.product_id=? ORDER BY pm.position,pm.media_id');$q->execute([$p['id']]);
            $photoIndex=0;foreach($q as $m){
                echo '<figure><img src="media.php?id='.$e($m['media_id']).'" alt="'.$e($m['colour']?:$p['name']).'"><figcaption>'.$e($m['colour']?:'All colours').($photoIndex===0?' · Primary':'').'</figcaption>';
                if($photoIndex>0){$start('product-primary-image',['product_id'=>$p['id'],'media_id'=>$m['media_id'],'version'=>$p['version']]);$submit('Make primary');}
                echo '</figure>';$photoIndex++;
            }
            echo '</div>';
            if($can('seo','edit')){
                echo '<h3>Per-photo ALT text</h3><p>Describe what is actually shown. Blank values inherit the product name.</p>';
                $q=$db->prepare('SELECT pm.media_id,pm.colour,a.alt_en,a.alt_bn,a.version FROM aelvo_product_media pm LEFT JOIN aelvo_product_image_alt a ON a.product_id=pm.product_id AND a.media_id=pm.media_id WHERE pm.product_id=? ORDER BY pm.position,pm.media_id');
                $q->execute([$p['id']]);
                foreach($q as $photo){
                    $start('seo-image-alt-save',['product_id'=>$p['id'],'media_id'=>$photo['media_id'],'version'=>$photo['version']??0]);
                    echo '<p>Photo '.$e($photo['media_id']).' ('.$e($photo['colour']).')</p>';
                    $field('ALT English','alt_en',$photo['alt_en']??'','text',false);
                    $field('ALT বাংলা','alt_bn',$photo['alt_bn']??'','text',false);
                    $submit('Save photo ALT');
                }
            }
            $start('product-image',['product_id'=>$p['id']]);$field('Colour name (blank for general photo)','colour','','text',false);echo '<label>Image<input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label>';$submit('Upload photo');return;
        }
        if($page==='inventory') {
            $result=(new AdminWorkspace($db,$actor))->inventory($_GET);$f=$result['filters'];
            echo '<div class="admin-page-heading"><div><span class="admin-eyebrow">Inventory operations</span><h1>Inventory</h1><p>On-hand is physical stock. Available = on-hand − reserved − quarantined (never below zero). Archived or inactive products are not silently made sellable.</p></div></div>';
            echo '<form class="admin-register-filters" method="get"><input type="hidden" name="page" value="inventory"><label>Search variant<input type="search" name="q" maxlength="100" value="'.$e($f['q']).'" placeholder="SKU, product or colour"></label>';
            echo '<label>Stock view<select name="status">';
            foreach([''=>'All variants','low'=>'Low stock (product threshold)','out'=>'Out of stock','quarantined'=>'Quarantined','inactive'=>'Inactive variants'] as $key=>$label)echo '<option value="'.$e($key).'"'.($f['status']===$key?' selected':'').'>'.$e($label).'</option>';
            echo '</select></label><button type="submit">Apply filters</button><a class="action" href="?page=inventory">Clear</a></form>';
            echo '<p class="admin-results" role="status">'.number_format($result['total']).' matching variants · page '.(int)$result['page'].' of '.(int)$result['pages'].'</p>';
            echo '<div class="table-scroll"><table><thead><tr><th>Product / variant</th><th>SKU</th><th>On-hand</th><th>Reserved</th><th>Quarantined</th><th>Available</th><th>Status</th></tr></thead><tbody>';
            foreach($result['rows'] as $v){
                echo '<tr><td><strong>'.$e($v['name']).'</strong><small class="admin-subtext">'.$e($v['colour'].' / '.$v['size']).' · #'.(int)$v['id'].'</small></td><td>'.$e($v['sku']).'</td><td>'.(int)$v['stock'].'</td><td>'.(int)$v['reserved'].'</td><td>'.(int)$v['quarantined'].'</td><td>'.(int)$v['available'].'</td><td>'.((int)$v['active']===1?'Active':'Inactive').'</td></tr>';
            }
            if(!$result['rows'])echo '<tr><td colspan="7">No variants match the selected filter.</td></tr>';
            echo '</tbody></table></div>';
            $pagerBase='?page=inventory&amp;q='.rawurlencode($f['q']).'&amp;status='.rawurlencode($f['status']).'&amp;p=';
            echo '<nav class="admin-register-pager" aria-label="Inventory pages">';
            if($result['page']>1)echo '<a class="action" href="'.$pagerBase.($result['page']-1).'">&larr; Previous</a>';
            if($result['page']<$result['pages'])echo '<a class="action" href="'.$pagerBase.($result['page']+1).'">Next &rarr;</a>';
            echo '</nav>';
            if($can('inventory','edit')) {echo '<h2>Adjust stock</h2>';$start('inventory-adjust');$field('Variant ID','variant_id','','number');$field('Change (+ receive, − remove)','delta','','number');$area('Required explanation','explanation');$submit('Record adjustment');}
            if($can('inventory','edit')) {echo '<h2>Quarantine / damage control</h2><p>Hold: move available units into quarantine without changing on-hand. Release: make inspected quarantined units available again. Write off: permanently remove quarantined units from physical on-hand and post a stock movement. A reason is mandatory for every action.</p>';$start('quarantine-record',['request_key'=>bin2hex(random_bytes(32))]);echo '<div class="field-grid">';$field('Variant ID','variant_id','','number');$field('Quantity','qty','','number');echo '<label>Action<select name="mode"><option value="hold">Quarantine</option><option value="release">Release after inspection</option><option value="writeoff">Write off / dispose</option></select></label></div>';$area('Mandatory inspection / disposition notes','explanation');$submit('Post audited stock disposition');}
            echo '<h2>Movement history</h2><div class="table-scroll"><table><tr><th>Variant</th><th>Stock Δ</th><th>Reserved Δ</th><th>Source</th><th>Reason</th></tr>';
            foreach($db->query('SELECT * FROM aelvo_movements ORDER BY id DESC LIMIT 100') as $m) echo '<tr><td>#'.(int)$m['variant_id'].'</td><td>'.(int)$m['stock_delta'].'</td><td>'.(int)$m['reserved_delta'].'</td><td>'.$e($m['source_type'].' #'.$m['source_id']).'</td><td>'.$e($m['explanation']).'</td></tr>';echo '</table></div>';
            echo '<h2>Quarantine history</h2><div class="table-scroll"><table><tr><th>Variant</th><th>Action</th><th>Quantity</th><th>Reason</th><th>Recorded UTC</th></tr>';
            foreach($db->query('SELECT variant_id,action,qty,explanation,created_at FROM aelvo_quarantine_events ORDER BY id DESC LIMIT 100') as $m)echo '<tr><td>#'.(int)$m['variant_id'].'</td><td>'.$e($m['action']).'</td><td>'.(int)$m['qty'].'</td><td>'.$e($m['explanation']).'</td><td>'.$e($m['created_at']).'</td></tr>';
            echo '</table></div>';return;
        }
        if($page==='courier') {
            echo '<h1>Courier — Steadfast</h1><p>Bookings use the verified outstanding amount as COD. A timeout or uncertain booking is <strong>never automatically retried</strong>; investigate that invoice in the Steadfast merchant portal. Delivery, cash collection, remittance and stock receiving remain independent actions.</p>';
            echo '<h2>Courier booking attempts</h2><div class="table-scroll"><table><tr><th>Invoice</th><th>COD (BDT)</th><th>Booking</th><th>Consignment / tracking</th><th>Remote status</th><th>Action</th></tr>';
            $ships=$db->query('SELECT * FROM aelvo_courier_shipments ORDER BY id DESC LIMIT 100')->fetchAll();
            foreach($ships as $ship){
                echo '<tr><td>'.$e($ship['invoice']).'</td><td>৳'.number_format($ship['cod_minor']/100,2).'</td><td>'.$e($ship['booking_state']).'</td><td>'.$e($ship['consignment_id']??'').' / '.$e($ship['tracking_code']??'').'</td><td>'.$e($ship['remote_status']).'</td><td>';
                if($ship['booking_state']==='Booked'&&$can('courier','edit')){$start('courier-sync',['shipment_id'=>$ship['id']]);$submit('Sync status');}
                elseif($ship['booking_state']!=='Booked')echo 'Investigate merchant portal; no automatic retry';
                echo '</td></tr>';
            }
            echo '</table></div>';
            echo '<h2>Courier cancellation / failed-delivery exceptions</h2><p>These are <strong>evidence-only</strong> reports. Filing or approving a case NEVER calls a cancellation API, marks an order cancelled/delivered, restores inventory or records a refund/payment. Contact Steadfast through its merchant portal and verify the outcome separately. Open and approved cases hold conflicting dispatch/delivery until a documented resolution workflow is completed.</p>';
            $exceptions=$db->query("SELECT c.*,s.invoice,s.remote_status,u.name AS filed_by FROM aelvo_courier_exception_cases c JOIN aelvo_courier_shipments s ON s.id=c.shipment_id JOIN aelvo_users u ON u.id=c.proposal_actor_id ORDER BY (c.state='Pending') DESC,c.id DESC LIMIT 70")->fetchAll();
            echo '<div class="table-scroll"><table><tr><th>Case / order</th><th>Reported exception</th><th>Evidence / status</th><th>Independent action</th></tr>';
            foreach($exceptions as $c){
                $label=CourierExceptions::TYPES[$c['exception_type']]??$c['exception_type'];
                echo '<tr><td>#'.(int)$c['id'].' · '.$e($c['invoice']).'<br>Shipment #'.(int)$c['shipment_id'].'</td>';
                echo '<td>'.$e($label).'<br>Portal/support ref: '.$e($c['portal_reference']).'<br>'.$e($c['notes']).'</td>';
                echo '<td>'.$e($c['state']).' · last provider status: '.$e($c['remote_status']).'<br>Filed by '.$e($c['filed_by']).'<br><a href="document.php?id='.$e($c['evidence_id']).'">Private evidence</a>';
                if($c['review_notes']!==null)echo '<br>Reviewer notes: '.$e($c['review_notes']);
                echo '</td><td>';
                if($c['state']==='Pending'&&$can('courier','approve')&&(int)$c['proposal_actor_id']!==(int)$actor['id']){
                    $start('courier-exception-review',['case_id'=>$c['id']]);
                    echo '<label>Independent decision<select name="decision"><option value="approve">Approve evidence report</option><option value="reject">Reject evidence report</option></select></label>';
                    $area('Review rationale and portal verification','review_notes');
                    echo '<label><input type="checkbox" name="evidence_reviewed" value="1" required> I independently verified evidence, invoice and provider status</label>';
                    $submit('Save evidence review');
                }elseif($c['state']==='Pending')echo 'Waiting for another authorized reviewer';
                elseif($c['state']==='Approved'&&in_array($c['exception_type'],['delivery_failed','return_to_sender_reported'],true)&&$c['remote_status']==='delivered'&&$can('courier','edit')){
                    $q=$db->prepare("SELECT id FROM aelvo_courier_exception_clearances WHERE case_id=? AND state='Pending' LIMIT 1");$q->execute([$c['id']]);
                    if($q->fetchColumn()!==false)echo 'Recovery clearance pending second review';
                    else {
                        $start('courier-clearance-propose',['case_id'=>$c['id'],'request_key'=>bin2hex(random_bytes(32))]);
                        echo '<p>Provider now reports delivered. Submit new documentary evidence to clear this earlier failure hold.</p>';
                        $field('New Steadfast delivery / support reference','portal_reference');
                        $area('Recovery / delivery evidence and explanation','clearance_notes');
                        echo '<label>New private proof<input type="file" name="evidence" accept="application/pdf,image/jpeg,image/png,image/webp" required></label>';
                        echo '<label><input type="checkbox" name="delivery_verified" value="1" required> I independently checked a newer Steadfast delivered status</label>';
                        $submit('Request second review of recovery');
                    }
                }else echo 'Evidence review recorded; separate operational follow-up required';
                echo '</td></tr>';
            }
            echo '</table></div>';
            echo '<h3>Recovery clearance history / approval queue</h3><p>A previously approved failed delivery may be cleared only after Steadfast reports <strong>delivered</strong> and a second authorized staff member reviews new evidence. This does not automatically mark an order delivered or receive funds.</p>';
            $clearances=$db->query("SELECT x.*,c.shipment_id,s.invoice,u.name AS submitted_by FROM aelvo_courier_exception_clearances x JOIN aelvo_courier_exception_cases c ON c.id=x.case_id JOIN aelvo_courier_shipments s ON s.id=c.shipment_id JOIN aelvo_users u ON u.id=x.proposal_actor_id ORDER BY (x.state='Pending') DESC,x.id DESC LIMIT 50")->fetchAll();
            echo '<div class="table-scroll"><table><tr><th>Clearance / case</th><th>New evidence</th><th>Status / review</th></tr>';
            foreach($clearances as $x){
                echo '<tr><td>#'.(int)$x['id'].' · case #'.(int)$x['case_id'].' · '.$e($x['invoice']).'</td>';
                echo '<td>'.$e($x['portal_reference']).' · '.$e($x['notes']).'<br><a href="document.php?id='.$e($x['evidence_id']).'">Private recovery evidence</a><br>Submitted by '.$e($x['submitted_by']).'</td><td>'.$e($x['state']);
                if($x['review_notes']!==null)echo '<br>'.$e($x['review_notes']);
                if($x['state']==='Pending'&&$can('courier','approve')&&(int)$x['proposal_actor_id']!==(int)$actor['id']){
                    $start('courier-clearance-review',['clearance_id'=>$x['id']]);
                    echo '<label>Decision<select name="decision"><option value="approve">Clear recovered-delivery hold</option><option value="reject">Reject recovery evidence</option></select></label>';
                    $area('Independent rationale','review_notes');
                    echo '<label><input type="checkbox" name="evidence_reviewed" value="1" required> I verified the new provider delivered status and documentary evidence</label>';
                    $submit('Review recovery evidence');
                }
                echo '</td></tr>';
            }
            echo '</table></div>';
            if($can('courier','edit')){
                echo '<h3>File documented courier exception</h3><p>Use the existing shipment ID from the table above. A request to cancel is not proof that the courier has cancelled; a final cancellation report requires an already-synced Steadfast <code>cancelled</code> status.</p>';
                $start('courier-exception-propose',['request_key'=>bin2hex(random_bytes(32))]);
                $field('Booked shipment ID','shipment_id','','number');
                echo '<label>Verified portal situation<select name="exception_type">';
                foreach(CourierExceptions::TYPES as $value=>$label)echo '<option value="'.$e($value).'">'.$e($label).'</option>';
                echo '</select></label>';
                $field('Merchant portal / support reference','portal_reference');
                $area('Evidence details and next manual action','exception_notes');
                echo '<label>Private portal/support evidence (PDF or image)<input type="file" name="evidence" accept="application/pdf,image/jpeg,image/png,image/webp" required></label>';
                echo '<label><input type="checkbox" name="portal_checked" value="1" required> I checked the merchant portal or confirmed support document for this exact shipment</label>';
                echo '<label><input type="checkbox" name="final_status_checked" value="1"> For final cancellation ONLY: I verified the Steadfast status is cancelled, not pending</label>';
                echo '<label><input type="checkbox" name="no_financial_action" value="1" required> I understand this report performs no remote cancellation, order update, refund or stock return</label>';
                $submit('Submit exception for independent review');
            }
            echo '<h2>Uncertain booking reconciliation</h2><p>Never book the same invoice again after a timeout. Check the exact invoice, booked COD and recipient in the Steadfast merchant portal. A second authorized reviewer must approve a documentary match. A negative search does not authorize retry, cancellation, payment or stock changes.</p>';
            $cq=$db->query("SELECT c.*,s.invoice,s.booking_state,s.cod_minor,s.consignment_id AS linked_consignment,u.name AS proposed_by FROM aelvo_courier_resolution_cases c JOIN aelvo_courier_shipments s ON s.id=c.shipment_id JOIN aelvo_users u ON u.id=c.proposal_actor_id ORDER BY (c.state='Pending') DESC,c.id DESC LIMIT 100");
            echo '<div class="table-scroll"><table><tr><th>Case / invoice</th><th>Finding / evidence</th><th>State / reviewer</th><th>Action</th></tr>';
            foreach($cq as $c){
                echo '<tr><td>#'.(int)$c['id'].' / '.$e($c['invoice']).'<br>Shipment #'.(int)$c['shipment_id'].' · '.$e($c['booking_state']).' · COD ৳'.number_format((int)$c['cod_minor']/100,2).'</td>';
                echo '<td>'.$e($c['outcome']).($c['consignment_id']!==null?' · CID '.$e($c['consignment_id']):'').($c['tracking_code']!==null?' / '.$e($c['tracking_code']):'').'<br>'.$e($c['proposal_notes']).'<br><a href="document.php?id='.$e($c['evidence_id']).'">Private evidence</a> · proposed by '.$e($c['proposed_by']).'</td>';
                echo '<td>'.$e($c['state']);if($c['review_notes']!==null)echo '<br>'.$e($c['review_notes']);echo '</td><td>';
                if($c['state']==='Pending'&&$can('courier','approve')&&(int)$c['proposal_actor_id']!==(int)$actor['id']){
                    $start('courier-resolution-review',['case_id'=>$c['id']]);
                    echo '<label>Decision<select name="decision"><option value="approve">Approve finding</option><option value="reject">Reject evidence</option></select></label>';
                    $area('Required reviewer rationale','review_notes');
                    echo '<label><input type="checkbox" name="evidence_reviewed" value="1" required> I independently checked the exact portal invoice/COD/recipient and private evidence</label>';
                    $submit('Record independent review');
                }elseif($c['state']==='Pending')echo 'Second reviewer with courier approval permission required';
                echo '</td></tr>';
            }
            echo '</table></div>';
            $pageNum=filter_var($_GET['courier_page']??'1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]])?:1;
            $cq=$db->query("SELECT COUNT(*) FROM aelvo_courier_shipments WHERE booking_state='Unknown' OR (booking_state='Prepared' AND updated_at<=UTC_TIMESTAMP()-INTERVAL 30 MINUTE)");
            $count=(int)$cq->fetchColumn();$pages=max(1,(int)ceil($count/25));$pageNum=min($pageNum,$pages);
            $q=$db->prepare("SELECT s.id,s.invoice,s.cod_minor,s.booking_state,s.updated_at FROM aelvo_courier_shipments s WHERE s.booking_state='Unknown' OR (s.booking_state='Prepared' AND s.updated_at<=UTC_TIMESTAMP()-INTERVAL 30 MINUTE) ORDER BY s.id DESC LIMIT 25 OFFSET ?");
            $q->bindValue(1,($pageNum-1)*25,\PDO::PARAM_INT);$q->execute();
            echo '<h3>Uncertain attempts · '.number_format($count).' · page '.$pageNum.' of '.$pages.'</h3>';
            foreach($q as $ship){
                $pending=$db->prepare("SELECT id FROM aelvo_courier_resolution_cases WHERE shipment_id=? AND state='Pending' LIMIT 1");
                $pending->execute([$ship['id']]);
                echo '<section><h3>'.$e($ship['invoice']).' · attempt #'.(int)$ship['id'].'</h3><p>COD ৳'.number_format((int)$ship['cod_minor']/100,2).' · '.$e($ship['booking_state']).' · last updated '.$e($ship['updated_at']).' UTC. This invoice is locked against repeat booking.</p>';
                if($pending->fetchColumn()!==false)echo '<p>Evidence has been submitted and awaits independent review.</p>';
                elseif($can('courier','edit')){
                    $start('courier-resolution-propose',['shipment_id'=>$ship['id']]);
                    echo '<label>Merchant portal finding<select name="outcome"><option value="found">Existing matching parcel found</option><option value="not_found">No matching parcel found (remains locked)</option></select></label>';
                    $field('Existing numeric Steadfast consignment ID (required when found)','consignment_id','', 'text',false);
                    $field('Portal tracking code (optional)','tracking_code','','text',false);
                    $area('How invoice, COD, recipient and portal evidence were checked','proposal_notes');
                    echo '<label>Private portal evidence (PDF or image)<input type="file" name="evidence" accept="application/pdf,image/jpeg,image/png,image/webp" required></label>';
                    echo '<label><input type="checkbox" name="portal_checked" value="1" required> I checked the Steadfast merchant portal directly</label>';
                    echo '<label><input type="checkbox" name="match_confirmed" value="1"> When matching a parcel, I confirmed this exact invoice, booked COD, recipient and contact</label>';
                    $submit('Submit evidence for separate approval');
                }
                echo '</section>';
            }
            echo '<nav aria-label="Uncertain courier pages">';
            if($pageNum>1)echo '<a class="action" href="?page=courier&amp;courier_page='.($pageNum-1).'">Previous</a> ';
            if($pageNum<$pages)echo '<a class="action" href="?page=courier&amp;courier_page='.($pageNum+1).'">Next</a>';
            echo '</nav>';
            if($can('courier','create')){
                echo '<h2>Book confirmed order</h2><p>Verify contact/address and paid advances first. This sends an actual parcel booking request when submitted; use only with authorized Steadfast credentials. No preview or test booking is triggered merely by opening this page.</p>';
                echo '<div class="table-scroll"><table><tr><th>Order</th><th>Contact</th><th>Balance / advance pending</th><th>Action</th></tr>';
                $q=$db->query("SELECT o.id,o.reference,o.customer_name,o.phone,o.total_minor,COALESCE(f.advance_minor,0) AS advance_minor,COALESCE(r.paid_minor,0) AS paid_minor FROM aelvo_orders o LEFT JOIN aelvo_order_finance f ON f.order_id=o.id LEFT JOIN (SELECT order_id,SUM(amount_minor) AS paid_minor FROM aelvo_order_receipts GROUP BY order_id) r ON r.order_id=o.id LEFT JOIN aelvo_courier_shipments s ON s.order_id=o.id WHERE o.status='Confirmed' AND s.id IS NULL ORDER BY o.id DESC LIMIT 40");
                foreach($q as $o){
                    $due=max(0,(int)$o['total_minor']-(int)$o['paid_minor']);$advance=max(0,(int)$o['advance_minor']-(int)$o['paid_minor']);
                    echo '<tr><td>'.$e($o['reference']).'</td><td>'.$e($o['customer_name']).' / '.$e($o['phone']).'</td><td>৳'.number_format($due/100,2).' / ৳'.number_format($advance/100,2).'</td><td>';
                    if($advance===0){$start('courier-book',['order_id'=>$o['id']]);echo '<label><input type="checkbox" name="confirmed" value="1" required> I verified COD and authorize one real booking</label>';$submit('Book once');}else echo 'Advance outstanding';
                    echo '</td></tr>';
                }
                echo '</table></div>';
            }
            echo '<h2>COD collection and remittance</h2><p>Independently verified courier collection remains a receivable, never account cash. Only an actual verified remittance credits an account. Withheld fees are shown separately and never credited as cash.</p>';
            $q=$db->query("SELECT s.id,s.invoice,s.cod_minor,s.remote_status,o.status AS order_status,c.collected_minor,c.reference AS collection_ref,COALESCE(t.remitted,0) AS remitted_minor,COALESCE(t.fees,0) AS fees_minor FROM aelvo_courier_shipments s JOIN aelvo_orders o ON o.id=s.order_id LEFT JOIN aelvo_courier_collections c ON c.shipment_id=s.id LEFT JOIN (SELECT shipment_id,SUM(remitted_minor) AS remitted,SUM(fee_minor) AS fees FROM aelvo_courier_remittances GROUP BY shipment_id) t ON t.shipment_id=s.id ORDER BY s.id DESC LIMIT 100");
            $accounts=$db->query('SELECT id,name FROM aelvo_accounts WHERE active=1 ORDER BY name')->fetchAll();
            foreach($q as $item){
                $collected=(int)($item['collected_minor']??0);$remitted=(int)$item['remitted_minor'];$fees=(int)$item['fees_minor'];$remaining=max(0,$collected-$remitted-$fees);
                echo '<section><h3>'.$e($item['invoice']).'</h3><p>Booked COD: ৳'.number_format($item['cod_minor']/100,2).' · Courier collected: ৳'.number_format($collected/100,2).' · Actual remitted: ৳'.number_format($remitted/100,2).' · Fees withheld: ৳'.number_format($fees/100,2).' · Courier-held receivable: ৳'.number_format($remaining/100,2).'</p>';
                if($can('courier','edit')&&$can('finance','edit')&&$item['collected_minor']===null&&$item['remote_status']==='delivered'&&$item['order_status']==='Delivered'){
                    $start('courier-collect',['shipment_id'=>$item['id'],'request_key'=>bin2hex(random_bytes(32))]);
                    $field('Courier-reported collected COD (BDT)','collected',number_format($item['cod_minor']/100,2,'.',''));
                    $field('Verified courier collection reference','reference');
                    echo '<label>Private evidence<input type="file" name="document" accept="application/pdf,image/jpeg,image/png,image/webp" required></label>';
                    echo '<label><input type="checkbox" name="confirmed" value="1" required> I verified courier collection independently; this does not record bank cash</label>';
                    $submit('Record courier collection');
                }
                if($can('courier','edit')&&$can('finance','edit')&&$item['collected_minor']!==null&&$remaining>0&&$accounts){
                    $start('courier-remit',['shipment_id'=>$item['id'],'request_key'=>bin2hex(random_bytes(32))]);
                    echo '<label>Bank/wallet credited account<select name="account_id">';foreach($accounts as $account)echo '<option value="'.(int)$account['id'].'">'.$e($account['name']).'</option>';echo '</select></label>';
                    $field('Actual credit received (BDT)','remitted');$field('Documented fee withheld (BDT)','fee','0.00');$field('Provider payment reference','reference');
                    echo '<label>Bank/courier payout proof<input type="file" name="document" accept="application/pdf,image/jpeg,image/png,image/webp" required></label>';
                    echo '<label><input type="checkbox" name="confirmed" value="1" required> I independently verified this exact bank/wallet credit; only this amount is added to the account</label>';
                    $submit('Record actual remittance');
                }
                echo '</section>';
            }
            echo '<h2>Latest remittance evidence</h2><div class="table-scroll"><table><tr><th>Invoice</th><th>Paid to</th><th>Cash credited</th><th>Fees withheld</th><th>Reference</th><th>UTC date</th><th>Proof</th></tr>';
            $q=$db->query('SELECT r.*,s.invoice,a.name FROM aelvo_courier_remittances r JOIN aelvo_courier_shipments s ON s.id=r.shipment_id JOIN aelvo_accounts a ON a.id=r.account_id ORDER BY r.id DESC LIMIT 100');
            foreach($q as $item)echo '<tr><td>'.$e($item['invoice']).'</td><td>'.$e($item['name']).'</td><td>৳'.number_format($item['remitted_minor']/100,2).'</td><td>৳'.number_format($item['fee_minor']/100,2).'</td><td>'.$e($item['reference']).'</td><td>'.$e($item['recorded_at']).'</td><td><a href="document.php?id='.$e($item['document_id']).'">View private proof</a></td></tr>';
            echo '</table></div>';
            echo '<p><strong>Accounting:</strong> Courier COD is not bank/cash until independently remitted and reconciled. Status sync does not fulfill customer orders or restock returns.</p>';
            return;
        }
        if($page==='orders') {
            $orderAdmin=new OrderAdmin($db,$actor);
            $money=fn(int $minor)=>'৳'.number_format($minor/100,2);
            echo '<h1>Orders management</h1><p>Order status, verified money received, courier collection and refunds are separate events. Delivery alone never confirms payment.</p>';
            echo '<p><a class="action" href="?page=orders&amp;mode=cancellations">Customer cancellation requests</a> · <a class="action" href="?page=orders">All orders</a></p>';
            if(($_GET['mode']??'')==='cancellations'){
                try{$cancelFilter=OrderCancellationRequests::filters($_GET);}catch(\InvalidArgumentException $ex){http_response_code(400);echo '<p role="alert">Invalid cancellation queue filter.</p>';return;}
                $requests=(new OrderCancellationRequests($db,$actor))->queue($cancelFilter);
                echo '<h2>Customer cancellation requests</h2><p>A submitted request never cancels an order. Staff must use the authorized order action independently, with the same payment/courier/stock guards. A cancelled order is shown as resolved; declining a request requires the cancel permission.</p>';
                echo '<form method="get"><input type="hidden" name="page" value="orders"><input type="hidden" name="mode" value="cancellations"><label>Request state<select name="cancel_state">';
                foreach(['Pending','Declined','Cancelled','All'] as $state)echo '<option value="'.$state.'"'.($cancelFilter['state']===$state?' selected':'').'>'.$state.'</option>';
                echo '</select></label><button type="submit">Filter requests</button></form>';
                echo '<p>'.number_format($requests['count']).' matching requests · page '.(int)$requests['page'].' of '.(int)$requests['pages'].'</p>';
                echo '<div class="table-scroll"><table><thead><tr><th>Order</th><th>Request &amp; UTC date</th><th>Decision</th><th>Action</th></tr></thead><tbody>';
                foreach($requests['rows'] as $request){
                    echo '<tr><td><a href="?page=orders&amp;id='.(int)$request['order_id'].'">'.$e($request['reference']).'</a><br>'.$e($request['customer_name']).'</td><td><span class="preserve-lines">'.$e($request['reason']).'</span><br>'.$e($request['created_at']).'</td><td>'.$e($request['effective_state']);
                    if($request['staff_note']!=='')echo '<p class="preserve-lines">'.$e($request['staff_note']).'</p>';
                    echo '</td><td><a class="action" href="?page=orders&amp;id='.(int)$request['order_id'].'#cancellation-request">Review on order</a></td></tr>';
                }
                if(!$requests['rows'])echo '<tr><td colspan="4">No cancellation requests match this filter.</td></tr>';
                echo '</tbody></table></div>';
                echo '<nav class="order-pager" aria-label="Cancellation requests pages">';
                $base='?page=orders&amp;mode=cancellations&amp;cancel_state='.rawurlencode($requests['state']).'&amp;cancel_page=';
                if($requests['page']>1)echo '<a class="action" href="'.$base.($requests['page']-1).'">Previous</a>';
                if($requests['page']<$requests['pages'])echo '<a class="action" href="'.$base.($requests['page']+1).'">Next</a>';
                echo '</nav>';return;
            }
            if(isset($_GET['id'])){
                $id=filter_var($_GET['id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if($id===false||$id===null){http_response_code(404);echo '<p role="alert">Order not found.</p>';return;}
                $o=$orderAdmin->detail((int)$id);
                if(!$o){http_response_code(404);echo '<p role="alert">Order not found.</p>';return;}
                echo '<p><a class="action" href="?page=orders">← Back to orders</a> <a class="action" href="order-summary.php?id='.(int)$o['id'].'">Print order summary</a></p>';
                echo '<section class="order-detail"><h2>Order '.$e($o['reference']).'</h2>';
                echo '<div class="order-data-grid"><div><strong>Customer</strong><p>'.$e($o['customer_name']).'<br>'.$e($o['phone']).'</p></div>';
                echo '<div><strong>Order status</strong><p>'.$e($o['status']).'</p></div>';
                echo '<div><strong>Payment status</strong><p>'.$e($o['payment_status']).'</p></div>';
                echo '<div><strong>Placed (UTC)</strong><p>'.$e($o['created_at']).'</p></div></div>';
                echo '<h3>Delivery address</h3><p class="preserve-lines">'.nl2br($e($o['address'])).'</p></section>';
                if($o['gateway_hold_state']!==null){
                    echo '<p role="alert" class="notice-warning"><strong>Payment reconciliation hold:</strong> online gateway state '.$e($o['gateway_hold_state']).'. Do not collect another payment, cancel, dispatch, mark delivered or book courier until a verified provider outcome is recorded. This is not proof of payment.';
                    if($can('payments','view'))echo ' <a href="?page=payments">Review payment attempts</a>';
                    echo '</p>';
                }
                if($o['courier_exception_hold'])echo '<p role="alert" class="notice-warning"><strong>Courier exception hold:</strong> Documentary courier cancellation/failure/return issue requires separate investigation and approval. This order cannot be dispatched or fulfilled during an active hold. No stock, refund or COD payout is implied. '.($can('courier','view')?'<a href="?page=courier">Open courier queue</a>':'Contact courier operations staff').'</p>';
                echo '<h2>Order items</h2><div class="table-scroll"><table><thead><tr><th>Product</th><th>Variant</th><th>Qty</th><th>Price</th><th>Line total</th></tr></thead><tbody>';
                foreach($o['items'] as $item)echo '<tr><td>'.$e($item['product_name']).'</td><td>'.$e($item['variant_label']).'</td><td>'.(int)$item['qty'].'</td><td>'.$money((int)$item['unit_price_minor']).'</td><td>'.$money((int)$item['unit_price_minor']*(int)$item['qty']).'</td></tr>';
                echo '</tbody></table></div>';
                echo '<div class="metric-grid"><section><h2>'.$money((int)$o['subtotal_minor']).'</h2><p>Original items subtotal</p></section><section><h2>'.$money((int)$o['coupon_minor']).'</h2><p>Recorded coupon discount</p></section><section><h2>'.$money((int)$o['delivery_minor']).'</h2><p>Delivery charge</p></section><section><h2>'.$money((int)$o['tax_minor']).'</h2><p>Configured tax</p></section><section><h2>'.$money((int)$o['total_minor']).'</h2><p>Order total (not received cash)</p></section></div>';
                $cancelRequest=(new OrderCancellationRequests($db,$actor))->forOrder((int)$o['id']);
                if($cancelRequest){
                    echo '<section class="order-detail" id="cancellation-request"><h2>Customer cancellation request</h2><p><strong>Current request state:</strong> '.$e($o['status']==='Cancelled'?'Order cancelled':$cancelRequest['state']).'</p>';
                    echo '<p class="preserve-lines"><strong>Customer reason:</strong> '.$e($cancelRequest['reason']).'</p>';
                    if($cancelRequest['staff_note']!=='')echo '<p class="preserve-lines"><strong>Customer-visible staff response:</strong> '.$e($cancelRequest['staff_note']).'</p>';
                    echo '<ol class="order-timeline">';foreach($cancelRequest['events'] as $history)echo '<li>'.$e($history['action']).' · '.$e($history['created_at']).' UTC</li>';echo '</ol>';
                    if($cancelRequest['state']==='Pending'&&$o['status']!=='Cancelled'&&$can('orders','edit')){
                        echo '<p>Messages are visible to the signed-in requesting customer. To actually cancel, use the authorized order status action below. No refund or courier cancellation occurs from this form.</p>';
                        $start('order-cancellation-review',['request_id'=>$cancelRequest['id'],'version'=>$cancelRequest['version']]);
                        echo '<label>Reply to customer<textarea name="staff_note" maxlength="1500" minlength="10" required></textarea></label><label>Review action<select name="decision"><option value="Message">Send update (keep request pending)</option>';
                        if($can('orders','cancel'))echo '<option value="Decline">Decline cancellation request</option>';
                        echo '</select></label><button type="submit">Save customer-visible review</button></form>';
                    }
                    echo '</section>';
                }
                echo '<h2>Order status history</h2><ol class="order-timeline">';
                foreach($o['events'] as $event)echo '<li><strong>'.$e($event['status']).'</strong> · '.$e($event['created_at']).' UTC</li>';
                echo '</ol>';
                if($can('payments','view')){
                    echo '<h2>Verified direct / gateway receipts</h2><p>Gateway provider validation is not bank settlement. Courier collection is separate and does not appear as direct receipts.</p>';
                    echo '<p><strong>Original required advance:</strong> '.$money((int)$o['advance_minor']).'</p>';
                    echo '<div class="table-scroll"><table><thead><tr><th>Source</th><th>Reference</th><th>Recorded amount</th><th>UTC</th></tr></thead><tbody>';
                    foreach($o['receipts'] as $r)echo '<tr><td>'.$e($r['method']).' (direct)</td><td>'.$e($r['reference']).'</td><td>'.$money((int)$r['amount_minor']).'</td><td>'.$e($r['created_at']).'</td></tr>';
                    foreach($o['gateway_receipts'] as $r)echo '<tr><td>SSLCommerz validated (not settled)</td><td>'.$e($r['tran_id']).'</td><td>'.$money((int)$r['amount_minor']).'</td><td>'.$e($r['confirmed_at']).'</td></tr>';
                    if(!$o['receipts']&&!$o['gateway_receipts'])echo '<tr><td colspan="4">No verified direct or gateway receipts recorded.</td></tr>';
                    echo '</tbody></table></div>';
                }
                if($can('returns','view')){
                    echo '<h2>Recorded paid refunds</h2><p>Approved proposals are not actual payouts; only posted payout events appear here.</p>';
                    echo '<div class="table-scroll"><table><thead><tr><th>Method</th><th>Reference</th><th>Paid</th><th>UTC</th></tr></thead><tbody>';
                    foreach($o['refunds'] as $r)echo '<tr><td>'.$e($r['method']).'</td><td>'.$e($r['reference']).'</td><td>'.$money((int)$r['amount_minor']).'</td><td>'.$e($r['created_at']).'</td></tr>';
                    if(!$o['refunds'])echo '<tr><td colspan="4">No recorded paid refunds.</td></tr>';
                    echo '</tbody></table></div>';
                }
                if($can('courier','view')){
                    echo '<h2>Courier booking state</h2>';
                    if($o['courier'])echo '<p><strong>'.$e($o['courier']['booking_state']).'</strong> · '.$e($o['courier']['remote_status']).' · tracking '.$e($o['courier']['tracking_code']).' · booked COD '.$money((int)$o['courier']['cod_minor']).'. This is not confirmed delivery, remittance or payment.</p><p><a class="action" href="?page=courier">Open courier reconciliation</a></p>';
                    else echo '<p>No courier booking attempt has been recorded.</p>';
                }
                if($can('orders','edit')){
                    $options=Orders::nextStatuses($o['status']);
                    echo '<section class="order-actions"><h2>Allowed next actions</h2>';
                    if(!array_filter($options,fn($target)=>$target!=='Cancelled'||$can('orders','cancel')))echo '<p>There are no available status transitions for your role from this state. Returns, refunds and courier exceptions use their dedicated workflows.</p>';
                    else{
                        echo '<p>These actions lock and recheck the order on the server, including stock, payment and courier restrictions. Reload if another staff member changed this order.</p><div class="order-action-row">';
                        foreach($options as $target){
                            if($target==='Cancelled'&&!$can('orders','cancel'))continue;
                            if($target==='Dispatched'&&$cancelRequest&&$cancelRequest['state']==='Pending'){
                                echo '<span class="action-disabled">Dispatch unavailable: customer cancellation request is pending review</span>';continue;
                            }
                            if($o['gateway_hold_state']!==null&&in_array($target,['Cancelled','Dispatched','Delivered'],true)){
                                echo '<span class="action-disabled">'.$e($target).' unavailable: online payment under review</span>';
                                continue;
                            }
                            $start('order-status',['id'=>$o['id'],'expected_status'=>$o['status'],'status'=>$target]);
                            echo '<button type="submit">'.$e($target==='Cancelled'?'Cancel order':'Mark '.$target).'</button></form>';
                        }
                        echo '</div>';
                    }
                    echo '</section>';
                }
                return;
            }
            try{$filter=OrderAdmin::filters($_GET);}catch(\InvalidArgumentException $ex){http_response_code(400);echo '<p role="alert">Invalid order search or page filter.</p>';return;}
            $results=$orderAdmin->listing($filter);
            echo '<form method="get" class="order-filter"><input type="hidden" name="page" value="orders"><label>Search reference, customer or phone<input type="search" name="q" maxlength="100" value="'.$e($filter['q']).'" placeholder="Reference or customer"></label>';
            echo '<label>Order status<select name="status"><option value="">All statuses</option>';
            foreach(['Pending','Confirmed','Dispatched','Delivered','Cancelled'] as $status)echo '<option value="'.$status.'"'.($filter['status']===$status?' selected':'').'>'.$status.'</option>';
            echo '</select></label><button type="submit">Search orders</button><a class="action" href="?page=orders">Reset</a></form>';
            echo '<p><strong>'.number_format($results['total']).'</strong> matching orders. Page '.(int)$results['page'].' of '.(int)$results['pages'].'.</p>';
            echo '<div class="table-scroll"><table><thead><tr><th>Reference / date</th><th>Customer</th><th>Order total</th><th>Order status</th><th>Payment status</th><th>Details</th></tr></thead><tbody>';
            foreach($results['rows'] as $o){
                echo '<tr><td><strong>'.$e($o['reference']).'</strong><br>'.$e($o['created_at']).' UTC</td><td>'.$e($o['customer_name']).'<br>'.$e($o['phone']).'</td><td>'.$money((int)$o['total_minor']).'</td><td>'.$e($o['status']).'</td><td>'.$e($o['payment_status']).'</td><td><a class="action" href="?page=orders&amp;id='.(int)$o['id'].'">View order</a></td></tr>';
            }
            if(!$results['rows'])echo '<tr><td colspan="6">No orders match this filter.</td></tr>';
            echo '</tbody></table></div><nav aria-label="Order register pages" class="order-pager">';
            $base='?page=orders&amp;status='.rawurlencode($filter['status']).'&amp;q='.rawurlencode($filter['q']).'&amp;p=';
            if($results['page']>1)echo '<a class="action" href="'.$base.($results['page']-1).'">← Previous</a>';
            if($results['page']<$results['pages'])echo '<a class="action" href="'.$base.($results['page']+1).'">Next →</a>';
            echo '</nav>';
            if(!$can('orders','create'))return;
            echo '<h2>Create a staff-assisted COD order</h2><p>Stock is reserved on creation. Delivery does not mark COD money as collected.</p>';
            $start('order-create',['request_key'=>bin2hex(random_bytes(32))]);$field('Customer name','customer_name');$field('Phone','phone','','tel');$area('Delivery address','address');$area('Order lines — one Variant ID,Quantity per line (e.g. 42,2)','lines');$field('Delivery fee (BDT)','delivery','0.00');$submit('Create order');return;
        }
        if($page==='settings') {
            echo '<h1>Settings</h1>';$theme=Settings::get($db,'theme');
            if($can('settings','edit')) {$start('theme-save',['version'=>$theme['version']]);$field('Primary accent','accent',$theme['value']['accent']??'#466b5c','color');$field('Surface colour','surface',$theme['value']['surface']??'#f2f5ef','color');$submit('Save theme');}
            if($can('settings','edit')) {
                $delivery=Settings::get($db,'delivery');echo '<h2>Delivery & COD checkout</h2><p>Phone verification is required. Enable only after configuring and testing SMS delivery.</p>';
                $start('delivery-save',['version'=>$delivery['version']]);
                echo '<label>Online COD orders<select name="cod_enabled"><option value="0">Closed</option><option value="1" '.(($delivery['value']['cod_enabled']??false)?'selected':'').'>Open</option></select></label>';
                $field('Dhaka city delivery (BDT)','dhaka',number_format(($delivery['value']['dhaka_minor']??0)/100,2,'.',''));
                $field('Outside Dhaka delivery (BDT)','outside',number_format(($delivery['value']['outside_minor']??0)/100,2,'.',''));
                echo '<p>Optional shipping rules use merchandise amount after coupon discounts. Tiers replace the base rate once their threshold is reached. Free-delivery threshold takes priority. Leave blank to disable either rule.</p>';
                $field('Free delivery from net merchandise (BDT; blank disables)','free_threshold',($delivery['value']['free_threshold_minor']??0)>0?number_format($delivery['value']['free_threshold_minor']/100,2,'.',''):'');
                $area('Order value tiers: minimum BDT, Dhaka fee BDT, Outside fee BDT — one ascending row per line','value_tiers',DeliveryPricing::formatTiers($delivery['value']['value_tiers']??[]));
                echo '<label>Required advance<select name="advance_type">';foreach(['none'=>'No advance','fixed'=>'Fixed BDT','percent'=>'Percentage of order total'] as $type=>$label)echo '<option value="'.$type.'" '.(($delivery['value']['advance_type']??'none')===$type?'selected':'').'>'.$label.'</option>';echo '</select></label>';
                $field('Advance value (BDT or whole percent)','advance_value',($delivery['value']['advance_type']??'none')==='fixed'?number_format(($delivery['value']['advance_value']??0)/100,2,'.',''):(string)($delivery['value']['advance_value']??0));
                echo '<p>Optional higher-order advance override. Blank minimum disables it. This is a merchant policy, not an automatic fraud decision.</p>';
                $field('Order total from BDT for alternate advance (blank disables)','high_value_advance_min',($delivery['value']['high_value_advance_min_minor']??0)>0?number_format($delivery['value']['high_value_advance_min_minor']/100,2,'.',''):'');
                echo '<label>Higher-order required advance<select name="high_value_advance_type">';
                foreach(['none'=>'No advance','fixed'=>'Fixed BDT','percent'=>'Percentage of order total'] as $type=>$label)echo '<option value="'.$type.'" '.(($delivery['value']['high_value_advance_type']??'none')===$type?'selected':'').'>'.$label.'</option>';
                echo '</select></label>';
                $field('Higher-order advance value (BDT or whole percent)','high_value_advance_value',($delivery['value']['high_value_advance_type']??'none')==='fixed'?number_format(($delivery['value']['high_value_advance_value']??0)/100,2,'.',''):(string)($delivery['value']['high_value_advance_value']??0));
                $submit('Save delivery settings');
            }
            if($actor['is_owner']){
                $tax=Settings::get($db,'tax');$policy=TaxPricing::settings($tax['value']);
                echo '<h2>Order tax policy (Owner only)</h2><p>Disabled unless explicitly enabled. Set a legally reviewed merchant rate yourself; this is not automatic statutory VAT. Exclusive tax is added after coupon discount. Optional shipping taxation must be explicitly selected. Inclusive pricing, tax exemption, and tax-refund allocation are not implemented yet.</p>';
                $start('tax-save',['version'=>$tax['version']]);
                echo '<label>Tax treatment<select name="mode">';
                foreach(['off'=>'No tax calculation','exclusive'=>'Tax-exclusive pricing'] as $type=>$label)echo '<option value="'.$type.'" '.($policy['mode']===$type?'selected':'').'>'.$label.'</option>';
                echo '</select></label>';
                $field('Merchant-approved tax rate (%)','rate_percent',number_format($policy['rate_basis_points']/100,2,'.',''));
                echo '<label>Tax delivery charges?<select name="shipping_taxable"><option value="0">No</option><option value="1" '.($policy['shipping_taxable']?'selected':'').'>Yes</option></select></label>';
                $submit('Save tax policy');
            }
            if(!$actor['is_owner'])return;
            $instructions=Settings::get($db,'payment-instructions');echo '<h2>Manual payment instructions</h2>';$start('payment-instructions',['version'=>$instructions['version']]);$area('Bank/wallet payment instructions','text',$instructions['value']['text']??'');$area('Instructions বাংলা','text_bn',$instructions['value']['text_bn']??'');$submit('Save instructions');
            $sms=Sms::status($db, $encryptionKey);
            echo '<h2>OTP SMS — BulkSMSBD</h2><p>Selected provider: BulkSMSBD. '.($sms['configured']?'Encrypted credentials saved':'Not configured').' — <strong>not live-delivery tested</strong>. Saving does not confirm a working connection, sender approval or message delivery.</p>';
            echo '<p>Use the approved Sender ID. Bangla OTP is recommended for masking routes; confirm any English route with your provider. Never share API keys in chat.</p>';
            $start('bulksmsbd-save');
            $field('Approved Sender ID','senderid',$sms['senderid']);
            echo '<label>API key<input type="password" name="api_key" maxlength="300" autocomplete="new-password" '.($sms['configured']?'':'required').' placeholder="'.($sms['configured']?'Leave blank to keep existing key':'Enter private API key').'"></label>';
            echo '<label>OTP SMS language<select name="otp_language"><option value="bn" '.($sms['otp_language']==='bn'?'selected':'').'>বাংলা (masking-compatible default)</option><option value="en" '.($sms['otp_language']==='en'?'selected':'').'>English (confirm permitted route)</option></select></label>';
            $submit('Save BulkSMSBD configuration');
            echo '<p>OTP registration/checkout/tracking now use this adapter exclusively. Missing credentials or uncertain provider replies block verification, without a demo code or fallback to the old SMS provider.</p>';
            echo '<h2>Steadfast courier credentials</h2><p>Owner-only encrypted credentials. Saving keys does not test them or make bookings. Confirm API access using your merchant contract before staff start booking.</p>';
            $saved=$db->prepare('SELECT 1 FROM aelvo_provider_secrets WHERE provider=?');$saved->execute(['steadfast']);$hasSteadfast=(bool)$saved->fetchColumn();
            echo '<p>'.($hasSteadfast?'Credentials saved (not verified)':'Credentials not configured').'</p>';
            $start('steadfast-save');
            echo '<label>Steadfast API key<input type="password" name="api_key" maxlength="300" autocomplete="new-password" '.($hasSteadfast?'':'required').' placeholder="'.($hasSteadfast?'Blank = retain saved key':'Enter API key').'"></label>';
            echo '<label>Steadfast Secret key<input type="password" name="secret_key" maxlength="300" autocomplete="new-password" '.($hasSteadfast?'':'required').' placeholder="'.($hasSteadfast?'Blank = retain saved secret':'Enter secret key').'"></label>';
            $submit('Save courier credentials');
            echo '<h2>SSLCOMMERZ hosted checkout</h2><p>Owner-only encrypted provider settings. Saving credentials does not connect, validate, or charge anything. Real payments remain blocked by the server-level <code>payment_gateways_enabled</code> flag until production acceptance.</p>';
            $q=$db->prepare('SELECT 1 FROM aelvo_provider_secrets WHERE provider=?');$q->execute(['sslcommerz']);$gatewaySaved=(bool)$q->fetchColumn();
            $gatewayConfig=[];
            if($gatewaySaved){try{$gatewayConfig=SslCommerz::credentials($db,$encryptionKey);}catch(\Throwable $ignored){echo '<p>Gateway settings cannot be decrypted; check server encryption configuration.</p>';}}
            echo '<p>'.($gatewaySaved?'Encrypted credentials saved; not independently validated':'No gateway credentials saved').'. Runtime gateway access: disabled until explicitly enabled in server configuration and here.</p>';
            $start('sslcommerz-save');
            echo '<label>Environment<select name="mode"><option value="sandbox"'.(($gatewayConfig['mode']??'sandbox')==='sandbox'?' selected':'').'>Sandbox (test only)</option><option value="live"'.(($gatewayConfig['mode']??'')==='live'?' selected':'').'>Live (real payments; final approval required)</option></select></label>';
            echo '<label>Payment initiation<select name="enabled"><option value="0">Disabled</option><option value="1"'.(($gatewayConfig['enabled']??false)?' selected':'').'>Enabled (requires separate server flag)</option></select></label>';
            echo '<label>Store ID<input name="store_id" maxlength="30" autocomplete="off"'.($gatewaySaved?' placeholder="Leave blank to retain saved ID"':' required').'></label>';
            echo '<label>Store password<input type="password" name="store_password" maxlength="120" autocomplete="new-password"'.($gatewaySaved?' placeholder="Leave blank to retain saved secret"':' required').'></label>';
            $submit('Save gateway credentials (no transaction)');
            $aiSaved=CustomerAi::settings($db,$encryptionKey,false);
            echo '<h2>Customer AI — website support (optional)</h2><p>This is the real provider adapter, not a simulated demo. No model call occurs until the customer explicitly opts in, and no customer/order database data is sent. Human takeover permanently pauses AI for that conversation. A saved key does not prove provider connectivity.</p>';
            echo '<p>'.($aiSaved?'Encrypted API key saved; not live tested.':'No API key configured.').' Website chat still works in human-only mode.</p>';
            $start('customer-ai-save');
            echo '<label>Customer AI availability<select name="enabled"><option value="0">Disabled — human support only</option><option value="1"'.(!empty($aiSaved['enabled'])?' selected':'').'>Enabled — only for consenting customers</option></select></label>';
            echo '<label>OpenAI model<select name="model">';foreach(['gpt-4.1-mini','gpt-4.1','gpt-5-mini'] as $m)echo '<option value="'.$m.'"'.(($aiSaved['model']??'gpt-4.1-mini')===$m?' selected':'').'>'.$m.'</option>';echo '</select></label>';
            echo '<label>OpenAI API key<input type="password" name="api_key" maxlength="250" autocomplete="new-password"'.($aiSaved?' placeholder="Blank retains existing key"':' required').'></label>';
            $submit('Save customer AI configuration (no API call)');
            $smtp=OwnerMail::credentials($db,$encryptionKey);
            echo '<h2>SMTP for Owner email verification</h2><p>TLS certificate verification is mandatory. Saved settings are not a connection or deliverability test. An Owner email remains unverified until a real emailed code is entered. Never paste SMTP passwords in chat.</p>';
            echo '<p>'.($smtp?'Encrypted SMTP credentials saved (not tested).':'SMTP not configured; Owner AI verification is blocked.').'</p>';
            $start('smtp-save');
            echo '<div class="field-grid">';
            $field('SMTP host (DNS name)','host',$smtp['host']??'');
            echo '<label>Security<select name="security"><option value="tls"'.(($smtp['security']??'tls')==='tls'?' selected':'').'>Implicit TLS · 465</option><option value="starttls"'.(($smtp['security']??'')==='starttls'?' selected':'').'>STARTTLS · 587</option></select></label>';
            $field('Port','port',(string)($smtp['port']??465),'number');
            $field('SMTP username','username',$smtp['username']??'');
            $field('Verified sender email','from',$smtp['from']??'','email');
            echo '<label>SMTP password<input type="password" name="password" maxlength="500" autocomplete="new-password"'.($smtp?' placeholder="Leave empty to retain password"':' required').'></label></div>';
            $submit('Save encrypted SMTP settings (no message sent)');
            echo '<h2>Other provider credentials</h2><p>These encrypted configuration placeholders are not connected or live-tested. Legacy smsnetbd credentials are inactive.</p><ul>';
            foreach($db->query('SELECT provider,updated_at FROM aelvo_provider_secrets ORDER BY provider') as $p) echo '<li>'.$e($p['provider']).' — credentials saved; no live connection verified</li>';echo '</ul>';
            $start('provider-save');echo '<label>Provider<select name="provider">';foreach(['google','facebook','whatsapp','messenger'] as $p) echo '<option>'.$p.'</option>';echo '</select></label>';echo '<p>Integration adapters and provider-specific validation are still pending. Do not enter real credentials for an unimplemented integration.</p>';$area('Credentials JSON','credentials');$submit('Save encrypted configuration');return;

        }
        if($page==='finance') {
            echo '<h1>Cash & bank accounts</h1><table><tr><th>Account</th><th>Type</th><th>Balance</th></tr>';
            $accounts=$db->query('SELECT * FROM aelvo_accounts ORDER BY id')->fetchAll();foreach($accounts as $a)echo '<tr><td>#'.(int)$a['id'].' '.$e($a['name']).'</td><td>'.$e($a['kind']).'</td><td>৳'.number_format($a['balance_minor']/100,2).'</td></tr>';echo '</table>';
            if($can('finance','create')){$start('account-create');$field('New account name','name');echo '<label>Type<select name="kind"><option>Cash</option><option>Bank</option><option>Mobile wallet</option></select></label>';$submit('Add account');}
            if($can('finance','edit')){echo '<h2>Record a balance change</h2>';$start('finance-post',['request_key'=>bin2hex(random_bytes(32))]);echo '<div class="field-grid"><label>Operation<select name="kind"><option value="deposit">Add balance</option><option value="transfer">Transfer</option><option value="expense">Expense</option><option value="adjustment">Correction</option></select></label>';foreach(['account_id'=>'Account / transfer source','target_id'=>'Transfer destination'] as $key=>$label){echo '<label>'.$label.'<select name="'.$key.'"><option value="">Choose</option>';foreach($accounts as $a)echo '<option value="'.(int)$a['id'].'">'.$e($a['name']).'</option>';echo '</select></label>';}$field('Amount (BDT)','amount');echo '<label>Correction direction<select name="direction"><option value="add">Add</option><option value="remove">Remove</option></select></label></div>';$area('Explanation (required for corrections)','explanation');echo '<label>Supporting document (optional)<input type="file" name="document" accept="application/pdf,image/jpeg,image/png,image/webp"></label>';$submit('Record transaction');}
            echo '<h2>Ledger</h2><div class="table-scroll"><table><tr><th>Account</th><th>Change</th><th>Balance after</th><th>Type / reason</th><th>Document</th></tr>';
            foreach($db->query('SELECT l.*,e.kind,e.explanation,e.document_id,a.name FROM aelvo_ledger l JOIN aelvo_finance_events e ON e.id=l.event_id JOIN aelvo_accounts a ON a.id=l.account_id ORDER BY l.id DESC LIMIT 100') as $r)echo '<tr><td>'.$e($r['name']).'</td><td>৳'.number_format($r['delta_minor']/100,2).'</td><td>৳'.number_format($r['balance_after_minor']/100,2).'</td><td>'.$e($r['kind'].' · '.$r['explanation']).'</td><td>'.($r['document_id']?'<a href="document.php?id='.$e($r['document_id']).'">Download</a>':'—').'</td></tr>';echo '</table></div>';return;
        }
        if($page==='purchases') {
            echo '<h1>Suppliers & purchases</h1><p>Drafting a purchase does not add stock or move money. Receiving stock and recording a verified supplier payment are separate, audited steps. BDT amounts include purchase discounts and actual overheads; payment receipts are private.</p>';
            $supplierDirectory=new SupplierRegister($db,$actor);
            $supplierResult=$supplierDirectory->list($_GET);
            $sf=$supplierResult['filters'];
            echo '<h2>Supplier directory</h2><p>Search every supplier, including inactive records. Outstanding balances cover directory-linked purchases only; historical text-only purchases remain separate and are not guessed.</p>';
            echo '<form method="get" class="admin-register-filters"><input type="hidden" name="page" value="purchases">';
            echo '<label>Supplier name, company or contact<input type="search" name="supplier_q" maxlength="100" value="'.$e($sf['q']).'" placeholder="Search supplier directory"></label>';
            echo '<label>Status<select name="supplier_status">';
            foreach([''=>'All suppliers','active'=>'Active','inactive'=>'Inactive'] as $key=>$label)echo '<option value="'.$e($key).'"'.($key===$sf['status']?' selected':'').'>'.$e($label).'</option>';
            echo '</select></label><label>Balance<select name="supplier_due">';
            foreach([''=>'All balances','owing'=>'Outstanding','clear'=>'No outstanding'] as $key=>$label)echo '<option value="'.$e($key).'"'.($key===$sf['due']?' selected':'').'>'.$e($label).'</option>';
            echo '</select></label><button type="submit">Find suppliers</button> <a href="?page=purchases" class="action">Reset</a></form>';
            echo '<p class="admin-results">'.number_format($supplierResult['total']).' suppliers · page '.(int)$supplierResult['page'].' of '.(int)$supplierResult['pages'].'</p>';
            echo '<div class="table-scroll"><table><tr><th>Supplier</th><th>Contact</th><th>Linked purchases</th><th>Outstanding</th><th>Actions</th></tr>';
            foreach($supplierResult['rows'] as $supplier){
                $due=max(0,(int)$supplier['booked_minor']-(int)$supplier['paid_minor']);
                echo '<tr><td>#'.(int)$supplier['id'].' '.$e($supplier['name']).((int)$supplier['active']?'':' (inactive)').'<br>'.$e($supplier['company']).'</td><td>'.$e($supplier['phone']).'<br>'.$e($supplier['email']).'</td><td>'.(int)$supplier['purchase_count'].'</td><td>৳'.number_format($due/100,2).'</td><td>';
                if($can('purchases','edit'))echo '<a class="action" href="?page=purchases&amp;supplier_id='.(int)$supplier['id'].'">Edit</a> ';
                echo '<a class="action" href="?page=purchases&amp;purchase_supplier='.(int)$supplier['id'].'#purchase-register">Purchase history</a> ';
                if((int)$supplier['active']&&$can('purchases','create'))echo '<a class="action" href="?page=purchases&amp;purchase_supplier_id='.(int)$supplier['id'].'#new-purchase">Use for purchase</a>';
                echo '</td></tr>';
            }
            if(!$supplierResult['rows'])echo '<tr><td colspan="5">No matching suppliers.</td></tr>';
            echo '</table></div>';
            $supplierQuery=['supplier_q'=>$sf['q'],'supplier_status'=>$sf['status'],'supplier_due'=>$sf['due']];
            $supplierBase='?page=purchases&amp;'.$e(http_build_query($supplierQuery,'','&',PHP_QUERY_RFC3986)).'&amp;supplier_page=';
            echo '<nav class="admin-register-pager" aria-label="Supplier directory pages">';
            if($supplierResult['page']>1)echo '<a class="action" href="'.$supplierBase.($supplierResult['page']-1).'">← Previous suppliers</a>';
            if($supplierResult['page']<$supplierResult['pages'])echo '<a class="action" href="'.$supplierBase.($supplierResult['page']+1).'">Next suppliers →</a>';
            echo '</nav>';
            if($can('purchases','create')||$can('purchases','edit')){
                $selected=[];
                if(isset($_GET['supplier_id'])){
                    $q=$db->prepare('SELECT * FROM aelvo_suppliers WHERE id=?');$q->execute([Input::integer($_GET['supplier_id'])]);$selected=$q->fetch()?:[];
                }
                if($can('purchases',$selected?'edit':'create')){
                    echo '<h2>'.($selected?'Edit supplier':'Add supplier').'</h2>';
                    $start('supplier-save',['id'=>$selected['id']??'','version'=>$selected['version']??'']);
                    echo '<div class="field-grid">';
                    foreach(['name'=>'Supplier name','company'=>'Company','phone'=>'Phone','email'=>'Email'] as $key=>$label)
                        $field($label,$key,$selected[$key]??'','text',$key==='name');
                    echo '</div>';
                    $area('Address','address',$selected['address']??'');$area('Notes','notes',$selected['notes']??'');
                    echo '<label>Status<select name="active"><option value="1"'.((int)($selected['active']??1)===1?' selected':'').'>Active</option><option value="0"'.((int)($selected['active']??1)===0?' selected':'').'>Inactive</option></select></label>';
                    $submit('Save supplier');
                }
            }
            $purchases=(new PurchaseRegister($db,$actor))->list($_GET);
            $pf=$purchases['filters'];
            echo '<h2 id="purchase-register">Purchase documents</h2><p>Payment and receipt are separate; search any historical purchase rather than only the most recent records.</p>';
            if($pf['supplier_id']!==null)echo '<p>Linked supplier #'.(int)$pf['supplier_id'].' purchase history. <a class="action" href="?page=purchases#purchase-register">Clear supplier filter</a></p>';
            echo '<form method="get" class="admin-register-filters"><input type="hidden" name="page" value="purchases">';
            if($pf['supplier_id']!==null)echo '<input type="hidden" name="purchase_supplier" value="'.(int)$pf['supplier_id'].'">';
            echo '<label>Reference or supplier<input type="search" name="purchase_q" maxlength="100" value="'.$e($pf['q']).'" placeholder="Purchase ID or supplier"></label>';
            echo '<label>Receiving<select name="receipt_status">';
            foreach([''=>'All receiving states','Draft'=>'Not received','Received'=>'Received'] as $key=>$label)echo '<option value="'.$e($key).'"'.($pf['receipt']===$key?' selected':'').'>'.$e($label).'</option>';
            echo '</select></label><label>Payment<select name="payment_status">';
            foreach([''=>'All payments','unpaid'=>'Not paid','partial'=>'Partially paid','paid'=>'Paid in full'] as $key=>$label)echo '<option value="'.$e($key).'"'.($pf['payment']===$key?' selected':'').'>'.$e($label).'</option>';
            echo '</select></label><button type="submit">Filter purchases</button> <a href="?page=purchases" class="action">Reset</a></form>';
            echo '<p class="admin-results">'.number_format($purchases['total']).' matching purchases · page '.(int)$purchases['page'].' of '.(int)$purchases['pages'].'</p>';
            echo '<div class="table-scroll"><table><tr><th>Reference</th><th>Supplier</th><th>Purchase total</th><th>Paid / due</th><th>Receiving</th><th>Actions</th></tr>';
            $purchaseRows=$purchases['rows'];
            foreach($purchaseRows as $p){
                $due=(int)$p['total_minor']-(int)$p['paid_minor'];
                echo '<tr><td><a href="purchase-summary.php?id='.(int)$p['id'].'">'.$e($p['reference']).'</a> (#'.(int)$p['id'].')</td><td>'.$e($p['supplier']).'</td><td>৳'.number_format($p['total_minor']/100,2).'</td><td>৳'.number_format($p['paid_minor']/100,2).' / ৳'.number_format($due/100,2).'</td><td>'.$e($p['status']).'</td><td><a href="document.php?id='.$e($p['receipt_id']).'">Slip</a> ';
                if($p['status']==='Draft'&&$can('purchases','edit')){$start('purchase-receive',['id'=>$p['id']]);$submit('Receive stock');}
                if($due>0 && $can('purchases','edit')&&$can('finance','edit'))echo ' <a class="action" href="?page=purchases&amp;payment_purchase_id='.(int)$p['id'].'#supplier-payment-form">Record payment</a>';
                echo '</td></tr>';
            }
            if(!$purchaseRows)echo '<tr><td colspan="6">No purchases match the selected filters.</td></tr>';
            echo '</table></div>';
            $pQuery=['purchase_q'=>$pf['q'],'receipt_status'=>$pf['receipt'],'payment_status'=>$pf['payment']];
            if($pf['supplier_id']!==null)$pQuery['purchase_supplier']=$pf['supplier_id'];
            $pBase='?page=purchases&amp;'.htmlspecialchars(http_build_query($pQuery,'','&',PHP_QUERY_RFC3986),ENT_QUOTES,'UTF-8').'&amp;purchase_page=';
            echo '<nav class="admin-register-pager" aria-label="Purchase register pages">';
            if($purchases['page']>1)echo '<a class="action" href="'.$pBase.($purchases['page']-1).'">← Previous</a>';
            if($purchases['page']<$purchases['pages'])echo '<a class="action" href="'.$pBase.($purchases['page']+1).'">Next →</a>';
            echo '</nav>';
            if($can('purchases','create')){
                echo '<h2 id="new-purchase">New multi-line purchase draft</h2>';
                $chosenSupplier=null;
                if(isset($_GET['purchase_supplier_id'])){
                    try {$chosenSupplier=$supplierDirectory->activeSupplier(Input::integer($_GET['purchase_supplier_id']));}
                    catch(InvalidArgumentException $ex){echo '<p role="alert">Selected supplier is unavailable. Choose an active supplier from the directory.</p>';}
                }
                $start('purchase-create');echo '<div class="field-grid">';
                $field('Unique purchase number','reference');
                echo '<label>Active supplier ID<input name="supplier_id" type="number" min="1" step="1" required value="'.($chosenSupplier?(int)$chosenSupplier['id']:'').'" aria-describedby="supplier-selection-help"></label></div>';
                echo '<p id="supplier-selection-help">Choose <strong>Use for purchase</strong> from the searchable directory above, or enter an active supplier ID shown there. The server verifies the supplier again before creating a draft.';
                if($chosenSupplier)echo ' Selected: <strong>'.$e($chosenSupplier['name']).'</strong> (#'.(int)$chosenSupplier['id'].').';
                echo '</p>';
                    $area('Lines: variant ID, quantity, unit cost BDT; one per line (e.g. 42,5,1200.00)','lines');
                    echo '<div class="field-grid">';
                    $field('Shipping / import BDT','shipping','0.00');$field('Other landed costs BDT','other_cost','0.00');
                    $field('Purchase discount BDT','discount','0.00');echo '</div>';
                    $area('Explanation','explanation');
                    echo '<label>Private purchase slip (required)<input type="file" name="document" accept="application/pdf,image/jpeg,image/png,image/webp" required></label>';
                    $submit('Save draft (no stock added)');
            }
            if($can('purchases','edit')&&$can('finance','edit')){
                $accounts=$db->query('SELECT id,name,balance_minor FROM aelvo_accounts WHERE active=1 ORDER BY name')->fetchAll();
                $paymentPrefill=[];
                if(isset($_GET['payment_purchase_id'])){
                    $q=$db->prepare('SELECT id,reference FROM aelvo_purchases WHERE id=?');
                    $q->execute([Input::integer($_GET['payment_purchase_id'])]);
                    $paymentPrefill=$q->fetch()?:[];
                }
                echo '<h2 id="supplier-payment-form">Record an actual supplier payment</h2><p>This records funds already sent and debits your selected cash/bank/wallet ledger. It does not send funds. Payment must not exceed the purchase due, and requires independent receipt evidence.</p>';
                $start('supplier-payment',['request_key'=>bin2hex(random_bytes(32))]);
                echo '<p>Enter the purchase ID shown in the searchable register above. This works for any historical invoice, not only the currently visible page. The server rejects nonexistent purchases and payments exceeding the outstanding amount.</p>';
                echo '<div class="field-grid"><label>Purchase ID<input type="number" name="purchase_id" min="1" step="1" required value="'.($paymentPrefill?(int)$paymentPrefill['id']:'').'"></label>';
                $field('Confirm exact purchase reference','purchase_reference',$paymentPrefill['reference']??'');
                echo '<label>Paid from account<select name="account_id" required><option value="">Choose recorded account</option>';
                foreach($accounts as $account)echo '<option value="'.(int)$account['id'].'">'.$e($account['name']).' (৳'.number_format($account['balance_minor']/100,2).')</option>';
                echo '</select></label>';
                $field('Verified amount BDT','amount');$field('Unique transfer / payment reference','reference');
                echo '</div>';$area('Evidence / verification notes','note');
                echo '<label>Payment receipt (private, required)<input type="file" name="document" accept="application/pdf,image/jpeg,image/png,image/webp" required></label>';
                echo '<label><input type="checkbox" name="confirmed" value="1" required> I checked independent evidence that this payment was actually made. This entry does not transfer funds.</label>';
                $submit('Record verified supplier payment');
            }
            echo '<h2>Recorded supplier payments</h2><div class="table-scroll"><table><tr><th>Purchase</th><th>Reference</th><th>Account</th><th>Amount</th><th>UTC date</th><th>Evidence</th></tr>';
            foreach($db->query('SELECT sp.*,p.reference AS purchase_ref,a.name AS account_name FROM aelvo_supplier_payments sp JOIN aelvo_purchases p ON p.id=sp.purchase_id JOIN aelvo_accounts a ON a.id=sp.account_id ORDER BY sp.id DESC LIMIT 100') as $payment)
                echo '<tr><td>'.$e($payment['purchase_ref']).'</td><td>'.$e($payment['reference']).'</td><td>'.$e($payment['account_name']).'</td><td>৳'.number_format($payment['amount_minor']/100,2).'</td><td>'.$e($payment['recorded_at']).'</td><td><a href="document.php?id='.$e($payment['document_id']).'">Private receipt</a></td></tr>';
            echo '</table></div>';return;
        }
        if($page==='returns'){require __DIR__.'/ReturnsView.php';return;}
        if($page==='payments'){
            echo '<h1>Payment verification</h1><p>Record only money independently verified in your bank, wallet, cash or courier settlement records. This form records received funds; it does not initiate a transfer.</p>';
            echo '<h2>Submitted evidence</h2><div class="table-scroll"><table><tr><th>Order</th><th>Claim</th><th>Evidence</th><th>Status</th><th>Actions</th></tr>';
            foreach($db->query('SELECT p.*,o.reference AS order_reference FROM aelvo_payment_proofs p JOIN aelvo_orders o ON o.id=p.order_id ORDER BY p.created_at DESC LIMIT 100') as $proof){
                echo '<tr><td>'.$e($proof['order_reference']).' (#'.(int)$proof['order_id'].')</td><td>'.$e($proof['method'].' / '.$proof['reference']).'<br>৳'.number_format($proof['claimed_minor']/100,2).'</td><td><a href="proof.php?id='.$e($proof['id']).'">Download privately</a></td><td>'.$e($proof['status']).'</td><td>';
                if($proof['status']==='Pending'&&$can('payments','edit')){if($can('finance','edit'))echo '<a href="?page=payments&proof='.$e($proof['id']).'">Review receipt</a>';$start('payment-reject',['proof_id'=>$proof['id']]);$area('Rejection reason','note');$submit('Reject claim');}echo '</td></tr>';
            }echo '</table></div>';
            if($can('payments','edit')&&$can('finance','edit')){
                $proof=[];if(is_string($_GET['proof']??null)){$q=$db->prepare("SELECT * FROM aelvo_payment_proofs WHERE id=? AND status='Pending'");$q->execute([$_GET['proof']]);$proof=$q->fetch()?:[];}
                echo '<h2>Record verified receipt</h2>';$start('payment-receive',['request_key'=>bin2hex(random_bytes(32)),'proof_id'=>$proof['id']??'']);$field('Order ID','order_id',(string)($proof['order_id']??''),'number');
                echo '<label>Receiving account<select name="account_id" required><option value="">Choose account</option>';foreach($db->query('SELECT id,name FROM aelvo_accounts WHERE active=1') as $account)echo '<option value="'.(int)$account['id'].'">'.$e($account['name']).'</option>';echo '</select></label><label>Method<select name="method">';foreach(Payments::METHODS as $method)echo '<option '.(($proof['method']??'')===$method?'selected':'').'>'.$method.'</option>';echo '</select></label>';
                $field('Actual verified amount BDT','amount',isset($proof['claimed_minor'])?number_format($proof['claimed_minor']/100,2,'.',''):'');$field('Unique transaction / receipt reference','reference',$proof['reference']??'');$area('Verification notes (required)','note');echo '<label><input type="checkbox" name="confirmed" value="1" required> I independently verified that these funds were received in this account.</label>';$submit('Record received funds');
            }
            echo '<h2>Online gateway attempts</h2><p>These states are distinct from bank/wallet cash. Uncertain, Review and RiskHold entries need provider-side reconciliation; never retry a charge or release goods solely because the browser returned successfully.</p>';
            echo '<div class="table-scroll"><table><thead><tr><th>Order</th><th>Transaction</th><th>Expected</th><th>Mode</th><th>State</th><th>UTC created</th></tr></thead><tbody>';
            foreach($db->query('SELECT g.order_id,g.tran_id,g.expected_minor,g.mode,g.state,g.created_at FROM aelvo_gateway_attempts g ORDER BY g.created_at DESC,g.id DESC LIMIT 100') as $attemptRow){
                echo '<tr><td>#'.(int)$attemptRow['order_id'].'</td><td>'.$e($attemptRow['tran_id']).'</td><td>৳'.number_format((int)$attemptRow['expected_minor']/100,2).'</td><td>'.$e($attemptRow['mode']).'</td><td>'.$e($attemptRow['state']).'</td><td>'.$e($attemptRow['created_at']).'</td></tr>';
            }
            echo '</tbody></table></div>';
            echo '<h2>Gateway exception investigations</h2><p>Evidence-only, two-person review. Reviewing evidence does <strong>not</strong> validate or cancel a provider charge, unblock an order, approve a refund, release stock or credit bank cash. A verified provider outcome still needs its own controlled workflow.</p>';
            if($can('payments','view')&&$can('finance','view')){
                $investigations=(new GatewayExceptions($db,$actor))->listCases();
                echo '<div class="table-scroll"><table><thead><tr><th>Case / order</th><th>Issue &amp; transaction</th><th>Observed / current</th><th>Evidence &amp; status</th><th>Independent review</th></tr></thead><tbody>';
                foreach($investigations as $case){
                    echo '<tr><td>#'.(int)$case['id'].' / #'.(int)$case['order_id'].'<small> · '.$e($case['created_at']).'</small></td>';
                    echo '<td>'.$e(GatewayExceptions::TYPES[$case['issue_type']]??$case['issue_type']).'<p>'.$e($case['tran_id']).' · '.$e($case['provider_reference']).'</p></td>';
                    echo '<td>'.$e($case['observed_state']).' / '.$e($case['current_gateway_state']);
                    if($case['observed_unsettled_minor']!==null)echo '<p>Unsettled at filing: ৳'.number_format((int)$case['observed_unsettled_minor']/100,2).' (not cash)</p>';
                    echo '</td><td><strong>'.$e($case['state']).'</strong><p><a href="document.php?id='.$e($case['evidence_id']).'">Private evidence</a></p><details><summary>Staff notes</summary><p>'.$e($case['details']).'</p></details></td>';
                    echo '<td>Filed by '.$e($case['proposer_name']);
                    if($case['state']==='Pending'&&$can('payments','approve')&&$can('finance','view')&&(int)$case['proposed_by']!==(int)$actor['id']){
                        $start('gateway-exception-review',['case_id'=>$case['id']]);
                        echo '<label>Decision<select name="decision"><option value="review">Evidence reviewed (hold remains)</option><option value="reject">Reject evidence</option></select></label>';
                        $area('Independent review notes','review_notes');
                        echo '<label><input type="checkbox" name="evidence_reviewed" value="1" required> I inspected the attached provider/statement evidence. No gateway/payment/order state will change.</label>';
                        $submit('Record independent review');
                    }elseif($case['state']!=='Pending')echo '<p>'.$e($case['reviewer_name']).' · '.$e($case['review_notes']).'</p>';
                    echo '</td></tr>';
                }
                if(!$investigations)echo '<tr><td colspan="5">No gateway investigation cases yet.</td></tr>';
                echo '</tbody></table></div>';
            }
            if($can('payments','edit')&&$can('finance','edit')){
                echo '<h3>File a gateway investigation (no financial posting)</h3>';
                $start('gateway-exception-propose',['request_key'=>bin2hex(random_bytes(32))]);
                echo '<label>Gateway attempt<select name="attempt_id" required><option value="">Select transaction</option>';
                foreach($db->query("SELECT id,tran_id,state FROM aelvo_gateway_attempts WHERE state IN ('Initiating','Ready','Uncertain','Review','RiskHold','Verified','FailedVerified','CancelledVerified') ORDER BY created_at DESC,id DESC LIMIT 100") as $a)
                    echo '<option value="'.$e($a['id']).'">'.$e($a['tran_id'].' · '.$a['state']).'</option>';
                echo '</select></label><label>Issue type<select name="issue_type" required>';
                foreach(GatewayExceptions::TYPES as $value=>$label)echo '<option value="'.$e($value).'">'.$e($label).'</option>';
                echo '</select></label>';
                $field('Provider portal / statement case reference','provider_reference');
                $area('Explain the discrepancy and checks performed (no secrets)','details');
                echo '<label>Private supporting evidence<input type="file" name="evidence" accept="application/pdf,image/jpeg,image/png,image/webp" required></label>';
                echo '<label><input type="checkbox" name="evidence_checked" value="1" required> I checked independent provider or bank information.</label>';
                echo '<label><input type="checkbox" name="no_financial_action" value="1" required> Filing/review does not release a payment hold or alter the ledger, order or stock.</label>';
                $submit('File exception investigation');
            }
            echo '<h2>Gateway settlement ledger</h2><p>Provider-validated gross charges are receivables, not cash. Record only actual settled bank/wallet credit supported by a private document, with withheld fees separately stated. No transfer is performed by this form.</p>';
            $q=$db->query('SELECT r.attempt_id,a.tran_id,a.order_id,r.amount_minor,COALESCE(s.received,0) AS credited_minor,COALESCE(s.fees,0) AS fee_minor FROM aelvo_gateway_receipts r JOIN aelvo_gateway_attempts a ON a.id=r.attempt_id LEFT JOIN (SELECT attempt_id,SUM(received_minor) AS received,SUM(fee_minor) AS fees FROM (SELECT attempt_id,received_minor,fee_minor FROM aelvo_gateway_settlements UNION ALL SELECT attempt_id,credited_minor,fee_minor FROM aelvo_gateway_payout_allocations) grouped GROUP BY attempt_id) s ON s.attempt_id=r.attempt_id ORDER BY r.confirmed_at DESC LIMIT 100');
            $eligible=$q->fetchAll(\PDO::FETCH_ASSOC);
            echo '<div class="table-scroll"><table><tr><th>Order/transaction</th><th>Validated gross</th><th>Cash received</th><th>Fee withheld</th><th>Unsettled</th></tr>';
            foreach($eligible as $r){$remaining=max(0,(int)$r['amount_minor']-(int)$r['credited_minor']-(int)$r['fee_minor']);echo '<tr><td>#'.(int)$r['order_id'].' · '.$e($r['tran_id']).'</td><td>৳'.number_format($r['amount_minor']/100,2).'</td><td>৳'.number_format($r['credited_minor']/100,2).'</td><td>৳'.number_format($r['fee_minor']/100,2).'</td><td>৳'.number_format($remaining/100,2).'</td></tr>';}
            echo '</table></div>';
            if($can('payments','edit')&&$can('finance','edit')) {
                echo '<h3>Match a real bank/wallet payout to verified charges</h3><p>One bank credit can cover multiple SSLCommerz charges. Enter each eligible transaction once and distribute its credited share and fee. Both column totals must match the actual bank credit and documented gateway fee exactly. This records one cash ledger credit, not one per order. Uncertain, sandbox, risk-held or disputed attempts cannot be matched.</p>';
                $start('gateway-payout-batch',['request_key'=>bin2hex(random_bytes(32))]);
                echo '<label>Actual credited account<select name="account_id" required><option value="">Choose bank/wallet</option>';
                foreach($db->query("SELECT id,name,kind FROM aelvo_accounts WHERE active=1 AND kind IN ('Bank','Mobile wallet') ORDER BY name") as $account)
                    echo '<option value="'.(int)$account['id'].'">'.$e($account['name'].' · '.$account['kind']).'</option>';
                echo '</select></label>';
                $field('Exact unique bank statement line/reference','bank_reference');
                $field('Actual bank/wallet credit (BDT)','received');$field('Withheld provider fees (BDT)','fee','0.00');
                echo '<p>Outstanding live charges (validated, not automatically settled):</p><div class="table-scroll"><table><tr><th>Gateway transaction</th><th>Unsettled gross</th></tr>';
                foreach($eligible as $r)if((int)$r['amount_minor']>(int)$r['credited_minor']+(int)$r['fee_minor'])
                    echo '<tr><td><code>'.$e($r['tran_id']).'</code></td><td>৳'.number_format(((int)$r['amount_minor']-(int)$r['credited_minor']-(int)$r['fee_minor'])/100,2).'</td></tr>';
                echo '</table></div>';
                $area('Matched lines: transaction ID | credited BDT | fee BDT (one per line)','allocations','');
                echo '<label>Private provider payout AND bank statement evidence<input type="file" name="document" accept="application/pdf,image/jpeg,image/png,image/webp" required></label>';
                echo '<label><input type="checkbox" name="confirmed" value="1" required> I matched every listed charge against independent provider details, verified one real bank credit and checked any withheld fees. No transfer is performed by this form.</label>';
                $submit('Record one matched bank payout');
            }
            if($can('payments','view')&&$can('finance','view')){
                echo '<h3>Recorded matched bank payouts</h3><div class="table-scroll"><table><tr><th>UTC</th><th>Bank line / account</th><th>Credit</th><th>Fees</th><th>Matched transactions</th><th>Evidence</th></tr>';
                $batches=$db->query('SELECT b.*,a.name AS account_name FROM aelvo_gateway_payout_batches b JOIN aelvo_accounts a ON a.id=b.account_id ORDER BY b.id DESC LIMIT 50')->fetchAll(\PDO::FETCH_ASSOC);
                $batchItems=[];
                if($batches){
                    $ids=array_map('intval',array_column($batches,'id'));
                    $placeholders=implode(',',array_fill(0,count($ids),'?'));
                    $q=$db->prepare('SELECT i.payout_id,i.credited_minor,i.fee_minor,g.tran_id FROM aelvo_gateway_payout_allocations i JOIN aelvo_gateway_attempts g ON g.id=i.attempt_id WHERE i.payout_id IN ('.$placeholders.') ORDER BY i.payout_id DESC,g.tran_id');
                    $q->execute($ids);
                    foreach($q as $item)$batchItems[(int)$item['payout_id']][]=$item;
                }
                foreach($batches as $batch){
                    echo '<tr><td>'.$e($batch['created_at']).'</td><td>'.$e($batch['account_name']).'<p><code>'.$e($batch['bank_reference']).'</code></p></td><td>৳'.number_format((int)$batch['credit_minor']/100,2).'</td><td>৳'.number_format((int)$batch['fee_minor']/100,2).'</td><td>';
                    $items=$batchItems[(int)$batch['id']]??[];
                    echo '<details><summary>'.count($items).' validated charge(s)</summary><ul>';
                    foreach($items as $item)echo '<li><code>'.$e($item['tran_id']).'</code> · credited ৳'.number_format((int)$item['credited_minor']/100,2).' + fee ৳'.number_format((int)$item['fee_minor']/100,2).'</li>';
                    echo '</ul></details></td><td><a href="document.php?id='.$e($batch['document_id']).'">Private statement</a></td></tr>';
                }
                echo '</table></div>';
            }
            echo '<h2>Posted receipts</h2><table><tr><th>Order</th><th>Method / reference</th><th>Amount</th></tr>';foreach($db->query('SELECT * FROM aelvo_order_receipts ORDER BY id DESC LIMIT 100') as $receipt)echo '<tr><td>#'.(int)$receipt['order_id'].'</td><td>'.$e($receipt['method'].' / '.$receipt['reference']).'</td><td>৳'.number_format($receipt['amount_minor']/100,2).'</td></tr>';echo '</table>';return;
        }
        if($page==='coupons'){
            echo '<h1>Coupons</h1><p>Discounts apply to product subtotal, excluding delivery. Usage is counted at order confirmation and released on unpaid cancellation. Schedules use Asia/Dhaka time (start inclusive, end exclusive). Account-specific limits require verified sign-in.</p>';
            // Server-side coupon register; old first-200 limit could hide scheduled and paused offers.
            $search=is_string($_GET['find']??null)?strtoupper(trim($_GET['find'])):'';
            if(strlen($search)>40||!preg_match('/^[A-Z0-9_-]*$/D',$search))throw new \InvalidArgumentException('Invalid coupon search.');
            $status=is_string($_GET['status']??null)?$_GET['status']:'all';
            if(!in_array($status,['all','active','scheduled','paused','expired','exhausted'],true))throw new \InvalidArgumentException('Invalid coupon status.');
            $pageNumber=Input::integer($_GET['p']??'1',1,100000);
            $now=time();$where=[];$args=[];
            if($search!==''){$where[]="code LIKE ? ESCAPE '!'";$args[]='%'.str_replace('_','!_',$search).'%';}
            switch($status){
                case 'active':$where[]='active=1 AND (starts_at IS NULL OR starts_at<=?) AND (ends_at IS NULL OR ends_at>?) AND (usage_limit IS NULL OR used_count<usage_limit)';array_push($args,$now,$now);break;
                case 'scheduled':$where[]='active=1 AND starts_at>?';$args[]=$now;break;
                case 'paused':$where[]='active=0';break;
                case 'expired':$where[]='ends_at IS NOT NULL AND ends_at<=?';$args[]=$now;break;
                case 'exhausted':$where[]='usage_limit IS NOT NULL AND used_count>=usage_limit';break;
            }
            $filter=$where?' WHERE '.implode(' AND ',$where):'';
            $countQuery=$db->prepare('SELECT COUNT(*) FROM aelvo_coupons'.$filter);$countQuery->execute($args);
            $total=(int)$countQuery->fetchColumn();$lastPage=max(1,(int)ceil($total/25));
            if($pageNumber>$lastPage)$pageNumber=$lastPage;
            $query=$db->prepare('SELECT * FROM aelvo_coupons'.$filter.' ORDER BY id DESC LIMIT ? OFFSET ?');
            foreach($args as $i=>$value)$query->bindValue($i+1,$value,is_int($value)?\PDO::PARAM_INT:\PDO::PARAM_STR);
            $query->bindValue(count($args)+1,25,\PDO::PARAM_INT);
            $query->bindValue(count($args)+2,($pageNumber-1)*25,\PDO::PARAM_INT);
            $query->execute();
            echo '<form method="get"><input type="hidden" name="page" value="coupons"><label>Find code<input name="find" maxlength="40" value="'.$e($search).'"></label>';
            echo '<label>Offer status<select name="status">';foreach(['all'=>'All','active'=>'Active now','scheduled'=>'Scheduled','paused'=>'Paused/draft','expired'=>'Expired','exhausted'=>'Limit reached'] as $key=>$label)echo '<option value="'.$key.'"'.($status===$key?' selected':'').'>'.$label.'</option>';echo '</select></label><button type="submit">Filter</button></form>';
            echo '<p>'.(int)$total.' matching offers · Page '.(int)$pageNumber.' / '.(int)$lastPage.'</p>';
            echo '<table><tr><th>Code</th><th>Status</th><th>Used / limit</th><th>Action</th></tr>';
            foreach($query as $c){
                $live=(int)$c['active']===1 && ($c['starts_at']===null||(int)$c['starts_at']<=$now)&&($c['ends_at']===null||(int)$c['ends_at']>$now)&&($c['usage_limit']===null||(int)$c['used_count']<(int)$c['usage_limit']);
                $label=$live?'Active':((int)$c['active']===0?'Paused':(($c['ends_at']!==null&&(int)$c['ends_at']<=$now)?'Expired':(($c['starts_at']!==null&&(int)$c['starts_at']>$now)?'Scheduled':'Limit reached')));
                echo '<tr><td>'.$e($c['code']).'</td><td>'.$e($label).' / '.$e($c['kind']).'</td><td>'.(int)$c['used_count'].' / '.$e($c['usage_limit']??'Unlimited').'</td><td><a href="?page=coupons&id='.(int)$c['id'].'">View / edit</a></td></tr>';
            }
            echo '</table>';
            $base='?page=coupons&amp;find='.rawurlencode($search).'&amp;status='.rawurlencode($status).'&amp;p=';
            if($pageNumber>1)echo '<a href="'.$base.($pageNumber-1).'">Previous</a> ';
            if($pageNumber<$lastPage)echo '<a href="'.$base.($pageNumber+1).'">Next</a>';
            $c=[];if(isset($_GET['id'])){$q=$db->prepare('SELECT * FROM aelvo_coupons WHERE id=?');$q->execute([Input::integer($_GET['id'])]);$c=$q->fetch()?:[];}
            if(!$can('coupons',$c?'edit':'create'))return;
            $start('coupon-save',['id'=>$c['id']??'','version'=>$c['version']??0]);$field('Coupon code','code',$c['code']??'');
            echo '<label>Discount type<select name="kind"><option value="fixed">Fixed BDT</option><option value="percent" '.(($c['kind']??'')==='percent'?'selected':'').'>Percentage</option><option value="free_shipping" '.(($c['kind']??'')==='free_shipping'?'selected':'').'>Free shipping</option></select></label>';
            $field('Amount (BDT or whole percentage)','amount',isset($c['amount'])?($c['kind']==='percent'?(string)$c['amount']:number_format($c['amount']/100,2,'.','')):'');
            $field('Minimum eligible merchandise subtotal (BDT)','minimum',number_format(($c['minimum_minor']??0)/100,2,'.',''));
            echo '<label>Offer eligibility<select name="scope_kind">';
            foreach(['all'=>'All merchandise','product'=>'One product','category'=>'Category plus direct subcategories','brand'=>'Brand'] as $scope=>$label)
                echo '<option value="'.$scope.'"'.(($c['scope_kind']??'all')===$scope?' selected':'').'>'.$label.'</option>';
            echo '</select></label>';
            $field('Eligible product/category/brand ID (when scoped)','scope_id',isset($c['scope_id'])?(string)$c['scope_id']:'','number',false);
            $field('Verified account customer ID (optional)','customer_id',isset($c['customer_id'])?(string)$c['customer_id']:'','number',false);
            $field('Per-account redemption limit (optional)','per_customer_limit',isset($c['per_customer_limit'])?(string)$c['per_customer_limit']:'','number',false);
            echo '<label>First-order offer?<select name="first_order_only"><option value="0">No</option><option value="1" '.(!empty($c['first_order_only'])?'selected':'').'>Yes (requires verified signed-in account)</option></select></label>';
            $field('Maximum discount BDT (optional)','cap',isset($c['cap_minor'])?number_format($c['cap_minor']/100,2,'.',''):'','text',false);
            $field('Total usage limit (optional)','usage_limit',isset($c['usage_limit'])?(string)$c['usage_limit']:'','number',false);
            foreach(['starts_at'=>'Starts (Asia/Dhaka)','ends_at'=>'Ends (Asia/Dhaka; exclusive)'] as $key=>$label)$field($label,$key,isset($c[$key])&&$c[$key]!==null?(new \DateTimeImmutable('@'.(int)$c[$key]))->setTimezone(new \DateTimeZone('Asia/Dhaka'))->format('Y-m-d\TH:i'):'','datetime-local',false);
            echo '<label>Combine with product-level sale prices?<select name="allow_sale_stack"><option value="0">No — reject sale + coupon together (default)</option><option value="1" '.(!empty($c['allow_sale_stack'])?'selected':'').'>Yes — merchant explicitly authorizes combining discounts</option></select></label>';
            echo '<label>Status<select name="active"><option value="0">Paused</option><option value="1" '.(!empty($c['active'])?'selected':'').'>Active</option></select></label>';$submit('Save coupon');return;
        }
        if($page==='seo'){
            echo '<h1>SEO settings</h1><p>Indexing stays disabled until explicitly enabled. Use the final HTTPS storefront directory URL.</p>';
            $r=Settings::get($db,'seo');$v=$r['value'];
            if($can('seo','edit')){
                $start('seo-save',['version'=>$r['version']]);$field('Canonical base URL','base_url',$v['base_url']??'','url');$field('Default title','title',$v['title']??'AELVO Fashion');$area('Default description','description',$v['description']??'');
                echo '<label>Search engines<select name="indexing"><option value="0">No indexing (staging)</option><option value="1" '.(($v['indexing']??false)?'selected':'').'>Allow indexing</option></select></label>';$submit('Save SEO settings');
            }
            echo '<p><a href="sitemap.xml">Sitemap index</a> · <a href="robots.txt">Robots rules</a></p>';
            echo '<h2>Per-product SEO</h2><p>Overrides are stored separately from product descriptions. Select a product below; you do not need catalogue edit permission to maintain SEO.</p>';
            echo '<div class="table-scroll"><table><tr><th>Product</th><th>URL</th><th>SEO</th></tr>';
            foreach($db->query("SELECT p.id,p.name,p.slug,p.status,seo.title,seo.index_mode FROM aelvo_products p LEFT JOIN aelvo_product_seo seo ON seo.product_id=p.id WHERE p.status<>'Archived' ORDER BY p.id DESC LIMIT 100") as $productRow){
                echo '<tr><td>'.$e($productRow['name']).'</td><td>'.$e('/'.PublicRoutes::productPath($productRow['slug'])).'</td><td><a href="?page=seo&amp;product_id='.(int)$productRow['id'].'">Review SEO</a> · '.$e($productRow['index_mode']??'inherit').'</td></tr>';
            }
            echo '</table></div>';
            if(isset($_GET['product_id'])){
                $productId=Input::integer($_GET['product_id']);
                $productQuery=$db->prepare("SELECT * FROM aelvo_products WHERE id=? AND status<>'Archived'");
                $productQuery->execute([$productId]);$selected=$productQuery->fetch();
                if($selected){
                    $record=Seo::product($db,$productId);
                    $meta=Seo::metadata($db,'product',$selected,'en');
                    echo '<h3>'.$e($selected['name']).' · SEO editor</h3><p>Effective title: '.$e($meta['title']).'</p><p>Effective description: '.$e($meta['description']).'</p><p>Effective canonical: '.$e($meta['canonical']).'</p><p>Robots: '.$e($meta['robots']).'</p>';
                    if($can('seo','edit')){
                        $start('seo-product-save',['product_id'=>$productId,'version'=>$record['version'],'return_to'=>'seo']);
                        $field('SEO title (blank = inherited)','title',$record['title'],'text',false);
                        $area('SEO description (blank = inherited)','description',$record['description']);
                        $field('Canonical product path (blank = self)','canonical_path',$record['canonical_path'],'text',false);
                        $field('OG title','og_title',$record['og_title'],'text',false);
                        $area('OG description','og_description',$record['og_description']);
                        $field('OG image photo ID','og_image_id',$record['og_image_id']??'','text',false);
                        foreach(['index_mode'=>['inherit','index','noindex'],'follow_mode'=>['inherit','follow','nofollow']] as $mode=>$values){
                            echo '<label>'.$e($mode).'<select name="'.$e($mode).'">';
                            foreach($values as $value)echo '<option value="'.$e($value).'"'.($record[$mode]===$value?' selected':'').'>'.$e($value).'</option>';
                            echo '</select></label>';
                        }
                        $submit('Save SEO overrides');
                    }
                }
            }
            echo '<h2>Category / subcategory and brand SEO</h2><p>Public links use /category/slug and /brand/slug. Changing a slug reserves the previous path as a 301 redirect. Hidden entities do not have public pages or sitemap entries. Category products retain their original fashion family.</p>';
            foreach(['category','brand'] as $kind){
                $rows=Taxonomy::listings($db,$kind);
                echo '<h3>'.($kind==='category'?'Categories & subcategories':'Brands').'</h3><div class="table-scroll"><table><tr><th>Name</th><th>Public URL</th><th>Status</th><th>Editor</th></tr>';
                foreach($rows as $record)echo '<tr><td>'.$e($record['name']).'</td><td>'.$e('/'.PublicRoutes::entityPath($kind,$record['slug'])).'</td><td>'.$e($record['status']).'</td><td><a href="?page=seo&amp;kind='.$kind.'&amp;entity_id='.(int)$record['id'].'">Review / edit</a></td></tr>';
                echo '</table></div>';
                $selected=null;
                if(($_GET['kind']??'')===$kind && isset($_GET['entity_id'])){
                    $selectedId=Input::integer($_GET['entity_id']);
                    foreach($rows as $record)if((int)$record['id']===$selectedId){$selected=$record;break;}
                }
                if($can('seo','edit')){
                    echo '<h3>'.($selected?'Edit':'Create').' '.$e($kind).'</h3>';
                    $start('taxonomy-save',['kind'=>$kind,'id'=>$selected['id']??0,'version'=>$selected['version']??0]);
                    $field('Name','name',$selected['name']??'');$field('Name — বাংলা','name_bn',$selected['name_bn']??'','text',false);
                    $field('Public URL slug','slug',$selected['slug']??'');
                    if($kind==='category'){
                        echo '<label>Fashion family<select name="root_code">';
                        foreach(Taxonomy::ROOTS as $root)echo '<option value="'.$e($root).'"'.(($selected['root_code']??'Shoes')===$root?' selected':'').'>'.$e($root).'</option>';
                        echo '</select></label><label>Parent (leave blank for existing top-level family)<select name="parent_id"><option value="0">No parent</option>';
                        foreach($rows as $parent)if($parent['parent_id']===null && (int)$parent['id']!==(int)($selected['id']??0))echo '<option value="'.(int)$parent['id'].'"'.((int)($selected['parent_id']??0)===(int)$parent['id']?' selected':'').'>'.$e($parent['name']).'</option>';
                        echo '</select></label>';
                    }
                    $area('Category/brand description — English','description',$selected['description']??'');
                    $area('Description — বাংলা','description_bn',$selected['description_bn']??'');
                    $field('SEO title (blank = generated)','seo_title',$selected['seo_title']??'','text',false);
                    $field('Canonical path (blank = self)','canonical_path',$selected['canonical_path']??'','text',false);
                    $field('Public social photo ID','og_image_id',$selected['og_image_id']??'','text',false);
                    $area('Meta description (blank = generated)','seo_description',$selected['seo_description']??'');
                    $field('Open Graph title','og_title',$selected['og_title']??'','text',false);
                    $area('Open Graph description','og_description',$selected['og_description']??'');
                    foreach(['index_mode'=>['inherit','index','noindex'],'follow_mode'=>['inherit','follow','nofollow'],'status'=>['Active','Hidden']] as $setting=>$options){
                        echo '<label>'.$e($setting).'<select name="'.$e($setting).'">';
                        foreach($options as $option)echo '<option value="'.$e($option).'"'.(($selected[$setting]??$options[0])===$option?' selected':'').'>'.$e($option).'</option>';
                        echo '</select></label>';
                    }
                    $submit($selected?'Save changes':'Create '.$kind);
                }
            }
            echo '<h2>Product URL ownership & redirects</h2><p>Published product URLs use /product/slug. Historical names are reserved and automatically point directly to the latest canonical product URL. Manual aliases may target only an existing published product; no external domains, wildcards or query strings.</p>';
            if($can('seo','edit')){
                $start('seo-redirect-add');$field('Old path (e.g. old-shoes)','source');$field('New canonical product path (e.g. product/leather-shoes)','destination');$submit('Create permanent 301 redirect');
            }
            echo '<div class="table-scroll"><table><tr><th>Source path</th><th>Destination</th><th>Kind</th><th>Status / action</th></tr>';
            foreach($db->query("SELECT path,destination_path,entity_type,enabled FROM aelvo_public_routes WHERE route_kind='redirect' ORDER BY updated_at DESC LIMIT 100") as $redirect){
                echo '<tr><td>'.$e('/'.$redirect['path']).'</td><td>'.$e('/'.$redirect['destination_path']).'</td><td>'.$e($redirect['entity_type']).'</td><td>'.($redirect['enabled']?'Enabled':'Disabled');
                if($redirect['entity_type']==='manual' && $can('seo','edit')){
                    $start('seo-redirect-toggle',['source'=>$redirect['path'],'enabled'=>$redirect['enabled']?'0':'1']);$submit($redirect['enabled']?'Disable':'Enable');
                }
                echo '</td></tr>';
            }
            echo '</table></div>';
            echo '<h2>404 monitor</h2><p>Only safe route identifiers are stored; no raw query strings or IP addresses. Repeated misses are counted. Reviewing never creates an automatic redirect.</p>';
            echo '<div class="table-scroll"><table><tr><th>Missing route</th><th>Hits</th><th>First / last UTC</th><th>Review</th></tr>';
            foreach($db->query('SELECT * FROM aelvo_seo_404 ORDER BY last_seen_at DESC,id DESC LIMIT 100') as $miss){
                echo '<tr><td>'.$e($miss['path']).'</td><td>'.(int)$miss['hits'].'</td><td>'.$e($miss['first_seen_at']).' / '.$e($miss['last_seen_at']).'</td><td>';
                if($miss['reviewed_at'])echo '<p>Reviewed '.$e($miss['reviewed_at']).'</p>';
                if($can('seo','edit')){$start('seo-404-review',['id'=>$miss['id'],'version'=>$miss['version']]);$submit('Mark reviewed');}
                echo '</td></tr>';
            }
            echo '</table></div><p>Product SEO is available in the product editor. Custom CMS page SEO is available under Banners & pages → Custom CMS pages. Analytics, verification, full previews and wider SEO warnings remain pending.</p>';return;
        }
        if($page==='reviews') {
            echo '<div class="admin-page-heading"><div><span class="admin-eyebrow">Customer engagement</span><h1>Verified customer reviews</h1><p>Review customer-submitted feedback without changing their original words. Only Approved reviews and photos are public. The store reply is separate.</p></div></div>';
            try { $queue=(new Reviews($db,$actor))->moderationQueue($_GET); }
            catch(\InvalidArgumentException $exception) { echo '<p class="error" role="alert">'.$e($exception->getMessage()).'</p>';return; }
            $f=$queue['filters'];
            echo '<form method="get" class="admin-register-filters"><input type="hidden" name="page" value="reviews"><label>Product or customer<input type="search" name="q" value="'.$e($f['q']).'" maxlength="100" placeholder="Product, slug or customer"></label>';
            echo '<label>Status<select name="status">';foreach(['Pending','Approved','Hidden','All'] as $status)echo '<option value="'.$status.'" '.($f['status']===$status?'selected':'').'>'.$status.'</option>';echo '</select></label>';
            echo '<label>Rating<select name="rating"><option value="">All ratings</option>';for($rating=5;$rating>=1;$rating--)echo '<option value="'.$rating.'" '.($f['rating']===(string)$rating?'selected':'').'>'.$rating.' stars</option>';echo '</select></label>';
            echo '<button type="submit">Filter reviews</button><a class="action" href="?page=reviews">Pending queue</a></form>';
            echo '<p class="admin-results" role="status">'.number_format($queue['total']).' matching reviews · page '.$queue['page'].' of '.$queue['pages'].'</p>';
            if (!$queue['rows']) echo '<section><p>No reviews match these filters.</p></section>';
            foreach($queue['rows'] as $r) {
                $productHref=PublicRoutes::localUrl(PublicRoutes::productPath((string)$r['product_slug']));
                echo '<article class="admin-review-card"><header><div><h2>'.$e($r['product_name']).'</h2><p>'.$e($r['customer_name']).' · Verified purchase · '.$e($r['created_at']).' UTC</p></div>';
                echo '<div><strong aria-label="'.(int)$r['rating'].' out of 5 stars">'.str_repeat('★',(int)$r['rating']).str_repeat('☆',5-(int)$r['rating']).'</strong> <span class="admin-status">'.$e($r['status']).'</span></div></header>';
                echo '<p class="admin-review-body">'.$e($r['body']).'</p>';
                if($r['photos']){
                    echo '<div class="admin-review-photos">';foreach($r['photos'] as $photo)echo '<a href="review-photo.php?id='.$e($photo).'" target="_blank" rel="noopener" aria-label="Open customer-submitted review photo"><img src="review-photo.php?id='.$e($photo).'" alt="Customer-submitted review image" width="88" height="88" loading="lazy"></a>';echo '</div>';
                }
                echo '<p><a class="action" href="'.$e($productHref).'" target="_blank" rel="noopener">View product</a></p>';
                if($can('reviews','edit')){
                    $start('review-moderate',['id'=>$r['id'],'version'=>$r['version']]);
                    echo '<div class="admin-review-actions"><label>Moderation status<select name="status">';
                    foreach(['Pending','Approved','Hidden'] as $status)echo '<option value="'.$status.'" '.($r['status']===$status?'selected':'').'>'.$status.'</option>';
                    echo '</select></label><label>Public store reply<textarea name="reply" rows="3" maxlength="6000">'.$e($r['reply']).'</textarea></label><button type="submit">Save moderation</button></div></form>';
                } elseif ($r['reply']!=='')echo '<blockquote><strong>Store reply</strong><p>'.$e($r['reply']).'</p></blockquote>';
                echo '</article>';
            }
            if($queue['pages']>1){
                $url=static fn(int $num):string=>'?'.http_build_query(['page'=>'reviews','q'=>$f['q'],'status'=>$f['status'],'rating'=>$f['rating'],'p'=>$num],'','&',PHP_QUERY_RFC3986);
                echo '<nav class="admin-register-pager" aria-label="Review moderation pages">';
                if($queue['page']>1)echo '<a href="'.$e($url($queue['page']-1)).'">← Newer</a>';
                echo '<span>Page '.$queue['page'].' / '.$queue['pages'].'</span>';
                if($queue['page']<$queue['pages'])echo '<a href="'.$e($url($queue['page']+1)).'">Older →</a>';
                echo '</nav>';
            }
            return;
        }
        if($page==='content' && ($_GET['mode']??'')==='pages') {
            echo '<h1>Custom CMS pages</h1><p><a href="?page=content">← Banners & fixed pages</a></p>';
            echo '<p>Drafts and saved revisions are private. Only the exact saved draft approved with Publish changes the live page and its SEO. Old URLs are permanently reserved and redirect after publication of a renamed page.</p>';
            echo '<div class="table-scroll"><table><tr><th>Page</th><th>Published URL</th><th>Actions</th></tr>';
            foreach($db->query('SELECT id,draft,published_slug,published_version FROM aelvo_cms_pages ORDER BY id DESC LIMIT 200') as $row){
                $draft=json_decode($row['draft'],true)?:[];
                $live=CmsPages::published($db,(int)$row['id']);
                echo '<tr><td>'.$e($draft['heading']??'Untitled').'</td><td>'.($live?'<a href="'. $e(PublicRoutes::localUrl(CmsPages::path($live['slug']))).'">/'.$e(CmsPages::path($live['slug'])).'</a>':($row['published_version']?'Hidden / unavailable':'Never published')).'</td><td><a href="?page=content&amp;mode=pages&amp;id='.(int)$row['id'].'">Review'.($can('content','edit')?' / edit':'').'</a></td></tr>';
            }
            echo '</table></div>';
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);
            $record=$id?CmsPages::draft($db,(int)$id):null;
            if($id && !$record){echo '<p>Page does not exist.</p>';return;}
            $v=$record?(json_decode($record['draft'],true)?:[]):[];
            echo '<h2>'.($record?'Edit page draft':'New custom page').'</h2>';
            if(!$can('content','edit')){
                if($record)echo '<section><h3>'.$e($v['heading']??'').'</h3><p class="preserve-lines">'.$e($v['body']??'').'</p>'.CmsBlocks::render($v['blocks']??[],'en').'</section>';
                return;
            }
            $start('cms-page-save',['id'=>$record['id']??0,'version'=>$record['version']??0]);
            $field('Page slug (lowercase words with hyphens)','slug',$v['slug']??'');
            $field('Heading — English','heading',$v['heading']??'');
            $field('Heading — বাংলা','heading_bn',$v['heading_bn']??'','text',false);
            $area('Body — English (plain text)','body',$v['body']??'');
            $area('Body — বাংলা (plain text)','body_bn',$v['body_bn']??'');
            echo '<h3>Rich content blocks</h3><p>Safe structured content: text, headings, lists, quotes, images and expandable FAQs. No raw HTML is accepted. Change order with the move buttons; removing a row affects only the saved draft until you publish.</p>';
            $savedBlocks=is_array($v['blocks']??null)?$v['blocks']:[];
            if(count($savedBlocks)<CmsBlocks::LIMIT)$savedBlocks[]=['kind'=>''];
            echo '<div id="cms-block-editor" data-limit="'.CmsBlocks::LIMIT.'">';
            foreach($savedBlocks as $i=>$block){
                echo '<fieldset class="cms-block" data-cms-block><legend>Block <span data-cms-order>'.($i+1).'</span></legend><div class="cms-block-tools"><button type="button" data-cms-move="up">↑ Move up</button><button type="button" data-cms-move="down">↓ Move down</button><button type="button" data-cms-remove>Remove</button></div>';
                echo '<label>Block type<select name="blocks['.$i.'][kind]" data-cms-kind><option value="">Unused slot</option>';
                foreach(CmsBlocks::TYPES as $type)echo '<option value="'.$type.'"'.(($block['kind']??'')===$type?' selected':'').'>'.ucfirst(str_replace('-',' ',$type)).'</option>';
                echo '</select></label>';
                foreach(['text'=>'Text / title / question / image caption — English','text_bn'=>'Text / title / question / image caption — বাংলা','detail'=>'FAQ answer — English','detail_bn'=>'FAQ answer — বাংলা','media_id'=>'Content image ID (image blocks only)','alt'=>'Image ALT — English','alt_bn'=>'Image ALT — বাংলা','href'=>'Button link (e.g. category/shoes, ./ or store.php?page=shop)'] as $key=>$label){
                    echo '<label>'.$e($label);$name='blocks['.$i.']['.$key.']';
                    if(in_array($key,['text','text_bn','detail','detail_bn'],true))echo '<textarea name="'.$name.'" rows="2">'.$e($block[$key]??'').'</textarea>';
                    else echo '<input name="'.$name.'" value="'.$e($block[$key]??'').'">';
                    echo '</label>';
                }
                echo '</fieldset>';
            }
            echo '</div><button type="button" class="action" id="cms-add-block">+ Add content block</button>';
            echo '<label>Publication visibility<select name="enabled"><option value="0"'.(empty($v['enabled'])?' selected':'').'>Hidden even if published</option><option value="1"'.(!empty($v['enabled'])?' selected':'').'>Visible after publish</option></select></label>';
            echo '<label><input type="checkbox" name="show_footer" value="1"'.(!empty($v['show_footer'])?' checked':'').'> Add this published page to the storefront footer</label>';
            if($can('seo','edit')){
            $field('Search title (blank = heading)','seo_title',$v['seo_title']??'','text',false);
            $area('Search description (blank = beginning of body)','seo_description',$v['seo_description']??'');
            $field('Social title','og_title',$v['og_title']??'','text',false);
            $area('Social description','og_description',$v['og_description']??'');
            foreach(['index_mode'=>['index','noindex'],'follow_mode'=>['follow','nofollow']] as $key=>$modes){
                echo '<label>'.$e($key).'<select name="'.$e($key).'">';
                foreach($modes as $mode)echo '<option value="'.$mode.'"'.(($v[$key]??$modes[0])===$mode?' selected':'').'>'.$mode.'</option>';
                echo '</select></label>';
            }
            }else echo '<p>SEO metadata can be changed only by authorized SEO editors. Existing values are preserved.</p>';
            $submit($record?'Save private draft':'Create private draft');
            // Media stays private until a reviewed, enabled page revision is published.
            echo '<h3>CMS image library</h3><p>Upload here, copy the resulting image ID into an image block, save and publish the page. Uploads alone never become public.</p>';
            $start('content-upload',['return_to'=>'cms','cms_id'=>$record['id']??0]);
            echo '<input type="file" name="media" accept="image/jpeg,image/png,image/webp" required>';
            $submit('Upload CMS image');
            echo '<div class="table-scroll"><table><tr><th>Image</th><th>Image ID</th><th>Preview (staff only)</th></tr>';
            $media=$db->query("SELECT m.id,m.original_name FROM aelvo_media m JOIN aelvo_content_media c ON c.media_id=m.id WHERE m.mime IN ('image/jpeg','image/png','image/webp') ORDER BY m.created_at DESC LIMIT 60");
            foreach($media as $img)echo '<tr><td>'.$e($img['original_name']).'</td><td><code>'.$e($img['id']).'</code></td><td><a href="media.php?id='.$e($img['id']).'" target="_blank" rel="noopener">Preview</a></td></tr>';
            echo '</table></div>';
            if($record){
                echo '<p><a class="action" href="cms-preview.php?id='.(int)$record['id'].'" target="_blank" rel="noopener">Preview saved draft (staff only)</a> — unsaved changes above are not included.</p>';
                echo '<section><h3>Review saved draft — revision '.(int)$record['version'].'</h3><p>'.$e($v['heading']??'').'</p><p class="preserve-lines">'.$e($v['body']??'').'</p>'.CmsBlocks::render($v['blocks']??[],'en').'<p>SEO: '.$e($v['seo_title']??'').' · '.$e($v['index_mode']??'index').'</p><p>Requested path: /'.$e(CmsPages::path((string)($v['slug']??'new-page'))).'</p></section>';
                $start('cms-page-publish',['id'=>$record['id'],'version'=>$record['version']]);
                echo '<label><input type="checkbox" name="confirm" value="1" required> I reviewed this saved draft and authorize publication. Any unsaved edits above are excluded.</label>';
                $submit('Publish this revision');
            }
            return;
        }
        if($page==='content') {
            echo '<h1>Banners & pages</h1><p><a href="?page=content&amp;mode=pages">Manage custom CMS pages</a></p><nav class="admin-menu">';foreach(Content::KEYS as $key)echo '<a href="?page=content&key='.$key.'">'.$key.'</a>';echo '</nav>';
            $key=is_string($_GET['key']??null)&&in_array($_GET['key'],Content::KEYS,true)?$_GET['key']:'hero';$record=Content::get($db,$key,false);$v=$record['value'];
            echo '<h2>'.$e($key).'</h2><p>Draft revision '.$record['version'].' · Published revision '.$e($record['published_version']??'None').'</p>';
            if(!$can('content','edit'))return;
            $start('content-save',['content_key'=>$key,'version'=>$record['version']]);
            echo '<label>Display<select name="enabled"><option value="0">Hidden</option><option value="1" '.(($v['enabled']??false)?'selected':'').'>Enabled</option></select></label>';
            foreach(['heading'=>'Heading','heading_bn'=>'Heading বাংলা','subtitle'=>'Subtitle','subtitle_bn'=>'Subtitle বাংলা','body'=>'Page body','body_bn'=>'Page body বাংলা','button'=>'Button label','button_bn'=>'Button বাংলা','destination'=>'Button destination','button2'=>'Second button label','button2_bn'=>'Second button বাংলা','destination2'=>'Second destination','tagline'=>'Tagline','tagline_bn'=>'Tagline বাংলা'] as $field=>$label)$area($label,$field,$v[$field]??'');
            echo '<label>Media type<select name="media_type"><option value="image">Image</option><option value="video" '.(($v['media_type']??'')==='video'?'selected':'').'>Video</option></select></label>';
            $field('Image / video poster media ID','image',$v['image']??'','text',false);$field('Video media ID','video',$v['video']??'','text',false);
            if(in_array($key,['about','delivery-returns','privacy','terms'],true) && $can('seo','edit')){
                echo '<h3>Published page SEO</h3><p>These changes also require saving and explicitly publishing the reviewed draft. Leave titles empty to inherit the page heading.</p>';
                $field('Search title','seo_title',$v['seo_title']??'','text',false);
                $area('Search description','seo_description',$v['seo_description']??'');
                $field('Social title','og_title',$v['og_title']??'','text',false);
                $area('Social description','og_description',$v['og_description']??'');
                foreach(['index_mode'=>['index','noindex'],'follow_mode'=>['follow','nofollow']] as $modeField=>$modes){
                    echo '<label>'.$e($modeField).'<select name="'.$e($modeField).'">';
                    foreach($modes as $mode)echo '<option value="'.$e($mode).'"'.(($v[$modeField]??$modes[0])===$mode?' selected':'').'>'.$e($mode).'</option>';
                    echo '</select></label>';
                }
            }
            $field('Popup delay in seconds','delay',(string)($v['delay']??3),'number');$field('Start UTC (optional)','start',$v['start']??'','datetime-local',false);$field('End UTC (optional)','end',$v['end']??'','datetime-local',false);$submit('Save draft');
            if($record['version']){
                echo '<h2>Review saved draft</h2><section><h3>'.$e($v['heading']??'').'</h3><p>'.$e($v['subtitle']??'').'</p><p>'.$e($v['body']??'').'</p><p>'.$e(($v['button']??'').' → '.($v['destination']??'')).'</p><p>'.$e(($v['button2']??'').' → '.($v['destination2']??'')).'</p></section>';
                $start('content-publish',['content_key'=>$key,'version'=>$record['version']]);echo '<label><input type="checkbox" name="confirm" value="1" required> Publish the saved draft above. Unsaved form changes are excluded.</label>';$submit('Publish reviewed draft');
            }
            echo '<h2>Upload content media</h2>';$start('content-upload');echo '<input type="file" name="media" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm" required>';$submit('Upload');
            echo '<div class="table-scroll"><table><tr><th>File</th><th>Media ID</th><th>Preview</th></tr>';
            foreach($db->query('SELECT m.* FROM aelvo_media m JOIN aelvo_content_media c ON c.media_id=m.id ORDER BY m.created_at DESC LIMIT 50') as $m)echo '<tr><td>'.$e($m['original_name']).'</td><td>'.$e($m['id']).'</td><td><a href="media.php?id='.$e($m['id']).'">Open</a></td></tr>';echo '</table></div>';return;
        }
        if($page==='team') {
            echo '<h1>Team & permissions</h1><p>Only your original Owner account can change staff access.</p>';
            $q=$db->query("SELECT u.id,u.name,u.email,u.status,p.phone,p.notes FROM aelvo_users u JOIN aelvo_staff_profiles p ON p.user_id=u.id ORDER BY u.id");
            foreach($q->fetchAll() as $u) {
                echo '<section><h2>'.$e($u['name']).'</h2><p>'.$e($u['email'].' · '.$u['phone'].' · '.$u['status']).'</p><p>'.$e($u['notes']).'</p>';
                $gr=$db->prepare('SELECT p.module,p.action FROM aelvo_role_permissions p JOIN aelvo_user_roles ur ON ur.role_id=p.role_id WHERE ur.user_id=?');$gr->execute([$u['id']]);$selected=[];foreach($gr as $r) $selected[]=$r['module'].':'.$r['action'];
                $start('staff-permissions',['user_id'=>$u['id']]);echo '<div class="permission-grid">';foreach(Team::MODULES as $module) {echo '<fieldset><legend>'.$e($module).'</legend>';foreach(($module==='orders'?['view','create','edit','cancel']:($module==='channel.website'?['view','edit']:(in_array($module,['courier','payments'],true)?['view','create','edit','approve']:['view','create','edit','delete']))) as $action) {$key=$module.':'.$action;echo '<label><input type="checkbox" name="grants[]" value="'.$e($key).'" '.(in_array($key,$selected,true)?'checked':'').'>'.$action.'</label>';}echo '</fieldset>';}echo '</div>';$submit('Save permissions');
                if($u['status']==='Active'){$start('staff-disable',['user_id'=>$u['id']]);echo '<label><input type="checkbox" name="confirm" value="1" required> Revoke this staff member access</label>';$submit('Disable access');}
                elseif($u['status']==='Disabled'){$start('staff-enable',['user_id'=>$u['id']]);echo '<label><input type="checkbox" name="confirm" value="1" required> Re-enable this account with its existing permissions</label>';$submit('Re-enable access');}
                echo '</section>';
            }
            echo '<h2>Add staff</h2>';$start('staff-create');$field('Name','name');$field('Email','email','','email');$field('Phone','phone','','tel');$field('Initial password (12+ characters)','password','','password');$area('Staff details','notes');$submit('Create staff');
        }
    }
}
