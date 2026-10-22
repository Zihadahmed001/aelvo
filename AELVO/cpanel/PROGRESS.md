## 22 October 2026 — promotion eligibility, shipping offers and account limits (development only)

- Added append-only schema #41 `coupon-eligibility.sql`. Existing coupon codes/redemptions remain; existing codes default to unrestricted merchandise eligibility. Historical item coupon allocations deliberately stay `NULL` because their real per-item shares are not evidenced.
- Coupon Admin form now supports fixed/percentage/free-shipping offers; product, active category (including direct subcategories), and active brand targeting; verified-account targeting, first-account-order and per-account redemption limits; and local Asia/Dhaka schedules stored as UTC Unix instants. No offer was automatically activated or seeded.
- Customer checkout now calculates **only eligible** merchandise discount, splits exact minor units deterministically across order items, stores that allocation in newly placed order-item snapshots, and independently reloads current product/taxonomy/prices/settings/coupon eligibility during its locked order placement. A shipping offer removes only the configured delivery charge and records the avoided shipping fee separately from product discount. Tax and required COD advance are recalculated on the resulting quote. Old orders are not rewritten.
- For account-only offers, anonymous checkout remains unsupported; staff cannot attach an order by matching a phone number. Account-row locking protects first-order/per-account usage checks across concurrent placements, in addition to coupon-row locking and the existing order/idempotency guards. `coupons:edit` plus `customers:view` is required to target one customer. Guest OTP rules remain unchanged.
- Admin coupon register now has server-side search, status filters and bounded 25-row pagination instead of an arbitrary first-200-row listing. Quote and redemption rejection leave the Bag intact; an unavailable promotion requires a new checkout review.
- Observed local checks: PHP syntax and pure/offline tests on the actual source, including eligibility, category ancestry, allocation exactness, cap, minimum spend, exclusive expiration and legacy migration-order regression; no DB, browser, concurrent MySQL, SMS or financial-provider transaction executed. The release SHA-256 manifest only checks local package integrity, not publisher authenticity.
- **Remaining promotion scope** includes automatic offers, combined offer precedence, merchant draft/preview/scheduling UX, fuller product-level campaigns, analytics, historical allocation reconciliation, partial refund allocation and dynamic banner-campaign linkage. Runtime migration, MySQL concurrency, UI and tax-policy acceptance are pending. NOT production-ready.

## 22 October 2026 — Explicit refund-tax allocation (source only)

Added `refund-tax-allocation.sql` as schema 37 and `RefundTaxes.php`. Refund proposals on orders with charged tax now require a merchant-entered tax share **included** in the refund amount. A locked order row and sum of Pending/Approved/Completed allocations cap cumulatively committed/paid refunded tax at the original order tax; rejected allocations release capacity. Tax-free orders remain compatible. Old taxable proposals without allocations cannot silently approve/pay as zero and require reviewed historical correction. Earlier unallocated active or completed refunds (including legacy payouts without a request) block new tax allocation until independently reconciled. No statutory or automatic proration policy is invented.

Refund queue, read-only financial reports and CSV expose allocated tax separately from total paid refunds, and warn on legacy taxed payouts lacking allocation. The recorded component bridge adds back the explicit tax share after subtracting gross paid refunds; still NOT verified net profit. These changes have no payout-execution capability. MySQL migration/browser/payment concurrency and tax/legal treatment remain UNTESTED; execute final acceptance on an isolated `_test` database.

## 22 October 2026 — Supplier directory and purchase-document usability (implementation; NOT runtime accepted)

- Added `app/SupplierRegister.php`, a permission-checked, server-side searchable/paginated supplier directory. Suppliers can be filtered by activity or verified purchase dues. Linked purchase payments are aggregated once per purchase before grouping by supplier, avoiding per-supplier query loops and payment multiplication; historical unlinked/text suppliers remain unassociated rather than guessed.
- Supplier cards now link to historical purchases and allow choosing any active supplier for a new purchase without the old first-200-record dropdown limitation. Purchase register supports an optional supplier-ID filter; purchase/payment selection no longer depends on the currently visible page.
- Supplier payment form now requires the exact purchase reference in addition to the ID and independent proof before ledger debit, with `FOR UPDATE` invoice locking, existing permission checks and idempotency retained. Each invoice row can prefill the purchase ID/reference through an authorized action link.
- No schema change, live funds transfer, provider action, or stock movement. Newly run: PHP CLI `-l` for 165 PHP files, all 30 `*-offline.php` scripts pass (including `supplier-register-offline.php`); runtime MySQL and browser checks were NOT performed. SQL schema count stays 36. This is source-level validation only.

## 22 October 2026 — Shipping/advance quote integrity (development, NOT production accepted)

- Added `DeliveryPricing.php` pure two-zone rate engine with optional ascending order-value shipping tiers and a configured free-delivery threshold applied to **post-coupon merchandise amount**; no policy is inferred if settings are blank. The old flat-rate behavior remains the fallback.
- Admin Delivery & COD form validates value bands and free-delivery settings; optional order-total-based advance override uses the existing `Payments::advance` contract. The customer form calls displayed Dhaka/outside prices base rates whenever tiered rules are configured.
- `Checkout::quote()` computes discount then final shipping; `Checkout::place()` locks settings and recomputes coupon, shipping and advance before recording the order, rejecting stale quotes. No new database migration; settings remain versioned JSON.
- Actually executed: PHP syntax lint across 153 PHP files (0 errors); all 26 `*offline.php` scripts passed, including new price-boundary/wiring tests. These are **not** MySQL, UI, concurrency, live SMS/payment/courier acceptance. Full V5 shipping/advance risk/product/weight/district/tax rules still need implementation, test, approval.

## 21 October 2026 — Admin returns & refunds operational registers (development, not production-accepted)

- Added `app/ReturnOperations.php`: read-only, permission-gated 25-row pages with strict status/search/page validation, prepared SQL, typed pagination and literal-wildcard-safe lookup by order reference or case/item ID. Queues cover customer return requests, delivered order items, inspected/received goods and refund approvals/payments; all status and paging are server-side.
- `app/ReturnRequestsView.php` and `app/ReturnsView.php`: search/status controls, result totals, previous/next navigation, direct staff links from accepted requests/delivered items to the physical receipt form. Receipt, sellable restock, refund approval and documented actual payout remain separate protected actions. Finance values remain hidden without finance view permission. No return policy or automatic payout was invented.
- `app/AdminWorkspace.php`: dashboard counts for pending customer returns and unposted sellable return stock, plus finance-gated approved-refund payout work. `public/index.php` rejects malformed queue filter input with HTTP 400.
- Offline evidence: all 140 PHP files passed `php -l`; 18 selected offline PHP scripts completed successfully, including `tests/returns-queues-offline.php` with PDO-shaped stubs testing bound parameters, pagination limits and finance guard. No PHP/MySQL DB, HTTP/browser, concurrency or real refund tests were performed. Existing schema remains 30 migrations; no live data was changed.
- Remaining: full returns/eligibility policies (merchant decisions needed), failed-delivery parcel reconciliation, real refund-provider paths, UI design parity, cross-module runtime integration and final production acceptance.

## Installer/migration guard implementation — 21 October 2026 (source-level, NOT accepted)

- `Schema::manifest/plan/migrate` now uses ordered SHA-256 history and MySQL advisory locking; new migrations are applied once, with `applying`/`failed` entry blocking blind retry after nontransactional DDL failure. Changed SQL, wrong order or legacy untracked populated DB stop safely. `public/schema-status.php` is owner-only **read-only**; no migration button or implied backup verification.
- Fresh `public/setup.php` imports into an empty database before creating private config. If import is interrupted, it does not write an installable config; recovery requires inspecting logs and using a new empty database. `bin/migrate.php` is a read-only preview by default; explicit apply on a tracked DB requires human attestation of independently tested backup restoration.
- `tests/migration-ledger-offline.php` covers manifest, gaps, bad hashes and interrupted migrations without MySQL. **MySQL DDL concurrency, legacy upgrade conversion, verified backup/restore, browser installer, Owner sessions and cPanel no-SSH upgrader remain unfinished and untested.** Never infer production readiness from offline tests.

## 21 October 2026 — Admin order management (source code; not accepted)

- Implemented the permission-checked Admin order register: literal reference/customer/phone search (SQL LIKE wildcards escaped), status filtering, bounded 25-row pagination and responsive order details.
- Order details show saved line-item prices, recorded discount, delivery charge, status event history, and only permission-appropriate direct/gateway receipts, paid refunds and courier state. Courier data never implies delivery or remitted cash. Printable internal order summary distinguishes validated gateway funds from actual receipt and courier COD.
- Status updates recheck the expected previous state while holding the order row lock; a stale form fails with conflict rather than silently overwriting a coworker. Cancellation requires the explicit `orders.cancel` grant in addition to `orders.edit`; role editor can assign it. Existing old edit-only staff no longer cancels until explicitly granted.
- The Admin order interface exposes only permitted next states and keeps server-side stock, advance and courier guards. No new migrations. `dist/` remains unchanged.
- Observed: 116 PHP syntax checks passed, local `tests/order-admin-offline.php`, 17 other offline regression suites, and bundled 26-schema SQL parser. An older SSLCommerz test assumption that `gateway` must be the last schema was corrected to assert presence, and the suite ran successfully. No MySQL runtime, browser, concurrent staff requests, real provider events or production validation were run.

## 21 October 2026 — Storefront bag and customer experience continuation (source-only)

- Replaced the scaffolded bag display with an approved sage-themed responsive bag/order-summary layout. A customer can now **replace** line-item quantities with CSRF-protected POST + 303 navigation; it does not overwrite the independent Buy It Now checkout items. A logged-in customer's quantity is updated atomically in `aelvo_customer_bag` under the active-customer lock, while guest bag updates remain in session. No actual stock reservation is made by editing the bag.
- The availability validator enforces 1–100 units against currently sellable (nonreserved/nonquarantined) stock. Missing, inactive and insufficient-stock variants cannot advance through the new bag UI. The existing server-side `Checkout::quote`/order placement still independently revalidate current price and stock. Partial direct-checkout provider/customer changes have NOT been tested.
- Product cards now distinguish **View product** from the genuine product-page **Buy It Now** action and display visible out-of-stock status. Customer account has localized quick links and server-side 10-order history pagination, preserving account ownership and keeping guest orders separate. Admin and approved `dist/` reference were not changed.
- Mobile bottom navigation's active/from indices now use CSS classes instead of CSP-blocked inline style attributes, retaining the liquid selector and reduced-motion defaults.
- Observed in this environment: PHP CLI syntax checks across source files, `node --check public/store.js`, `tests/bag-quantity-offline.php` (9 pure cases) and `tests/bag-view-offline.php` (four render cases). No PDO MySQL, Apache XAMPP/browser, stock concurrency, customer session, payment, courier or full final acceptance was executed. Schema count remains 26 and production readiness is **not** claimed.

## 21 October 2026 — financial-performance coverage (source-only, not final P&L)

- Added `FinancialPerformance.php` and restricted Reports UI with dated delivery-event revenue, refunds actually paid, FIFO delivery COGS, sellable return cost reversals, audited disposals, recorded operating expenses and withheld provider fees. Discounts already applied to sales are shown separately and not subtracted twice. Refunds use payout date rather than the old order's creation/delivery date. Supplier payments/deposits/transfers are not treated as cost of sales.
- A provisional recorded-components bridge is **explicitly not net profit**. Unknown FIFO units, missing historical delivery postings, unpriced/missing return layers and inconsistent order totals are displayed; tax, actual fulfilment costs and accrual coverage are not complete. No invented net-profit metric.
- Up to 100 delivered orders have lifetime paid refunds, known COGS and posted sellable-return reversal drilldown. Restricted CSV uses authenticated finance + reports read permissions and escapes spreadsheet formula inputs.
- No new SQL schema: 26 ordered schema files retained; read-only queries only. `dist/` remains unchanged. PHP syntax and static/offline regression checks do not prove database consistency or P&L correctness. MySQL/browser/fixtures, event-date edge cases, provider/settlement matching and full acceptance remain untested.

## 21 October 2026 — inventory costing continuation (source code, NOT accepted)

- Added additive schema 26 `inventory-costing.sql`: FIFO cost layers, order-item cost allocations and delivery cost postings. Receipts use exact recorded line landed totals. Older stock and purchase lines without verified landed allocation remain explicitly `NULL` cost, never guessed zero. New stock receipts and delivered-order stock changes are transactional with FIFO cost postings; repeated delivery cannot duplicate COGS. Inspected sellable returns restore an independently traceable layer; unknown-cost or old sales remain unknown. Manual stock increases remain unpriced, while authorized manual reductions and quarantine write-offs consume FIFO layers into a separate, auditable non-sale cost-disposal journal (not COGS). Existing unexplained cost/physical mismatches fail closed and require reconciliation rather than silent value deletion.
- Added finance-permission-gated operational valuation coverage in Reports: known/unknown delivered quantities, known COGS allocation for actual delivery time, separately labeled non-sale disposal costs, historic delivered lines without cost, current remaining priced layers, unknown stock and physical/layer mismatches. Not a complete P&L. Unrecognized revenue/returns/refunds/discounts/courier/gateway fees still prevent truthful net profit.
- Observed checks: 104 PHP lint checks (0 syntax failures), offline `inventory-costing-offline.php` arithmetic and SQL parsing; `sql-parser.php`, `staff-lines.php`, `finance-reconciliation-offline.php`, `support-reports.php` ran. `customer-content.php` did NOT pass: environment lacks PDO MySQL/config. No MySQL migration, transactional/concurrent run, XAMPP, browser or live provider verification occurred.
- Final XAMPP gate: fresh/migrated schema 26, legacy stock opening layers, FIFO receipt/delivery partial lots, duplicate retries/rollback/concurrent orders, sellable/damaged returns, manual stock adjustments, stock-layer mismatch remediation, SQL/transaction deadlock and performance, P&L return/fee/revenue reconciliation. Until then, production readiness is not established.

---

## 21 October 2026 — Steadfast and COD accounting implementation (source-only)

- Added ordered additive `courier.sql` (schema 24), containing booking attempts, provider-status events, courier collections and verified partial remittances.
- Added `Steadfast.php` fixed-host HTTPS create/status adapter, `Courier.php` remote booking idempotency and uncertainty freeze, and `CourierAccounting.php` for evidence-bound collection/partial remittance.
- Provider-specific Owner settings for encrypted Steadfast API/Secret, courier RBAC and Admin queue/forms; verified tracking displays only known shipment/status after OTP. Provider credentials were NOT supplied or used.
- Real-booking operation is only sent on an explicit authorized Admin submit. An absent/ambiguous response is `Unknown`, never an automatic replay; uncertain booking, cancellations/returns and provider-side disputes still require a reconciled human workflow.
- Booked COD is snapped from verified unpaid order amount; after booking, ordinary `Payments::receive` fails closed rather than crediting courier-held COD as merchant cash. A posted courier collection is a receivable ONLY. Each evidenced remittance credits exactly actual bank/wallet received and separately records fee withholding; committed refunds/advance rules gate courier booking.
- Existing operational reports expose booked COD, recorded external collections, actual remittances, withheld fees and current courier-held receivable separately. These figures are NOT a full P&L or externally verified financial statement.
- Source checks actually run: 93 PHP syntax lints; nine offline checks incl. courier API response contract, COD arithmetic, and all 24 bundled SQL parser checks. NO MySQL migrations, Apache/browser QA, concurrency, actual provider booking/cancellation or actual bank payouts were executed. Production readiness is explicitly NOT established.
- Remaining courier work includes uncertain-booking manual reconciliation/override, verified remote cancellation flows, webhook signature/auth and out-of-order resolution, partial deliveries/failed delivery/returns, settlements/provider payout matching and operational security acceptance. Verify the Steadfast API base URL and response contract with the merchant's actual official portal/documentation before enabling real booking.

---

## 21 October 2026 — Support inbox + read-only operational reports (development, not production accepted)

- Added additive `database/support-inbox.sql` as schema 23; `app/Schema.php` now imports it after the 22 prior migrations. This change requires backup and a properly migrated database before runtime usage.
- Public `/store.php?page=support`: bilingual contact/order-question/product-question/bug-report form with CSRF, IP/session limits, a replay-safe random request key, optional private JPG/PNG/WebP screenshot (4 MB), and explicit no implicit phone-to-account/order linking. Authenticated customers may view only their own ticket statuses.
- Admin `?page=support`: permissions (`support.view` / `support.edit`), bounded queue/status filter, internal timeline, optimistic-concurrency status+assignment updates, staff-only private attachment download. These updates do NOT send customer emails/SMS and do not verify payment or order claims.
- Admin `?page=reports` + RBAC-protected CSV export: date-scoped order totals/statuses and recorded verified receipts, paid refunds, operating expenses, purchase value, supplier cash payments; point-in-time stock/quarantine, low-stock and accounts. **No invented COGS/profit or COD remittance.** Rows shown are capped and labels distinguish booked value from cash.
- Offline `php tests/support-reports.php`, SQL parser and PHP lint are runnable without database; no MySQL migration, admin/browser QA, concurrency, upload transport, provider or integration acceptance has been run in this continuation. Preserve outstanding Master V5 backlog and approved `dist/` baseline.

---

## Continuation checkpoint — 21 October 2026

Implementation in actual `cpanel/` source:
- Replaced the active SMS transport with selected BulkSMSBD's documented fixed HTTPS single-SMS POST endpoint; correct 8801... normalization, accepted-submission `response_code=202` parsing and fail-closed behavior. The old sms.net.bd credential row is not an automatic fallback. Provider status cannot claim delivery or connection from a saved key.
- Added Owner-only, audited and encrypted provider-specific BulkSMSBD settings, approved sender ID and Bangla/English OTP language controls with blank-update key preservation. No credentials were entered and no network sends occurred.
- Added custom bilingual storefront 404 with actual HTTP status before HTML render, no canonical on missing pages, real catalogue suggestions and sanitized repeated-path aggregation. Admin SEO 404 monitor allows versioned review without automatic redirects. Added additive `database/seo-errors.sql` (15th schema).
- Added `tests/sms-adapter.php` offline response contract assertions and added it to final PHP test runner. Updated handoff/docs/acceptance plan and requirements coverage matrix.

Observed checks this continuation: PHP 8.4.24 syntax preflight of all **68** current `cpanel/*.php` source files passed, and `php cpanel/tests/sms-adapter.php` passed offline fixture assertions. See `../handoff/TEST-EVIDENCE-2026-10-21.md` for commands/results. Environment: PHP 8.4.24 CLI with Sodium and PDO but lacking cURL and a local MySQL CLI/server. No MySQL migration, full PHP suite, browser visual/HTTP QA, concurrency testing or real provider testing was executed. Provider acceptance is not delivery. Source is still incomplete and cannot be called production-ready.

Remaining work and exact next steps: `../handoff/STATUS-AND-BACKLOG.md` and `../handoff/REQUIREMENT-COVERAGE-2026-10-21.md`. Read older dated entries below as historical, especially old 13/14 schema counts and sms.net.bd integration instructions.

---

# Main project checkpoint — 11 October 2026

Do not hand off partial ZIPs. User asked for one complete project after verification.
No publication or live deployment authorized by this checkpoint.

## Present in source
- Private PHP/PDO configuration; one-time Owner setup, login, server sessions, CSRF, throttles and database permissions.
- Products and variants, per-colour image uploads, private documents, stock movement ledger and reservations.
- Staff-assisted COD orders, cancellation/delivery stock transitions; cash/bank ledger; purchase receipts and stock receipt.
- Staff access administration, theme settings and encrypted provider credentials.
- PHP public catalogue, product pages, bag and isolated Buy It Now.
- Durable server-side checkout quotes, delivery fees, explicit COD confirmation, phone verification, request idempotency and stock/price recheck.
- Alpha SMS / sms.net.bd adapter using fixed HTTPS POST, encrypted credentials and bounded responses. Official contract: https://sms.bd/api (checked 2026-10-11). No credentials used and no SMS sent. Other saved provider types still have no connected adapter.

## Verification evidence
- Existing frontend JS suite: 50 checks passed in this continuation. This suite tests the demo, not the PHP backend.
- PHP executable and MySQL server unavailable. PHP lint, policy, database and checkout tests have NOT run.
- Added `tests/checkout.php`: isolated `_test` database checks for amount calculation, session ownership, OTP expiry/attempts, duplicate submission, stock/price/delivery changes and COD reservation/cancellation. No real SMS is sent by tests.
- Browser testing has not run. Do not claim release readiness.

## Remaining scope
1. Run PHP lint and all database/integration/concurrency tests on a PHP/MySQL runtime; fix failures.
2. Bring approved storefront and Admin design to parity (current PHP pages are functional scaffolding with only part of the approved layout).
3. Customer accounts, phone sign-in/three OTP branches, order history, tracking, review submission/moderation and uploads.
4. Banners/video/popup publication, bilingual content, search/filter/pagination, categories/brands, coupons/promotions and full SEO modules.
5. Advance rules/manual payment proof and actual payment gateway adapters/webhooks; courier integration, returns/refunds, settlement reconciliation, full COGS/profit reports, offline POS and exports.
6. Provider-specific settings forms and connection checks; SMTP/email, recovery, social login and notifications.
7. Customer AI with actual provider and support handoff/channel adapters; Owner AI verified email sessions, exact approvals/business adapters, safe backup/test/rollback release system and financial prohibition.
8. Browser installer/preflight and reliable XAMPP/cPanel bundle; security review, responsive/browser QA and production acceptance tests.

## Immediate next work
- Test runtime first if available. Otherwise continue durable settings/content and customer identity, keeping provider-dependent operations fail-closed.
- Current COD flow always requires OTP. It does not implement the previously designed account/guest OTP branches or COD advance rules yet.
- `public/index.php` is Admin; `public/store.php` is storefront. `public/checkout-view.php` is an include guarded from direct access.
- Apply schema files via `bin/migrate.php`: core, commerce, finance, owner-ai, checkout. All must exist before opening Admin/store.


## Continuation checkpoint — customer/content implementation, 11 October 2026

User re-confirmed continuing the entire project. No partial deliverable was handed off and no deployment occurred.

### Newly implemented, pending PHP execution verification
- Additive `customers.sql` and `content.sql` migrations; seven schema files total. No existing data rewrite.
- Customer OTP signup, phone/password login, session expiry/rotation and OTP-verified primary-phone changes. Account creation happens only after correct code verification. Password bytes are preserved rather than trimmed.
- All three approved checkout OTP branches are wired server-side. Orders are linked by authenticated account ID captured in the quote, never by a phone match. Identity changes rotate the checkout binding.
- Account order history, delivered-owned-item text reviews, pending moderation, approve/hide and independent staff replies with optimistic version checks. Review photos and guest review/tracking remain pending.
- Durable bilingual hero/popup/CMS drafts, explicit revision-bound publication, UTC schedules, private content uploads, image/video poster, pause control, reduced-motion/mobile fallback. Popup once per published revision per session. Draft content media is permission-gated. Media endpoint supports byte ranges for video.
- SQL-backed search/category/price/stock filters, sorting and pagination. Bag and product detail load needed records independently of catalogue page limits.
- Global SEO controls, conservative noindex defaults, canonical/meta/social fields, sitemap index split into 1,000-product chunks, robots and Apache aliases. This is NOT the full approved SEO scope: entity-specific overrides, slug routes/301 ownership, taxonomy/brand SEO, analytics, structured data and 404 monitor remain pending.
- Token-gated browser installer/preflight for an empty database with private config, encryption key, install lock and seven-schema import. Existing config locks browser setup. Runtime/recovery testing pending.
- `tests/customer-content.php`: signup OTP ownership/attempts/replay, the three checkout branches, order association, review ownership/delivery/moderation, draft isolation/stale publication and search escaping. Uses fake test sender only.
- `bin/check.php`: lint and PHP test runner. Fixed CLI error/skip paths to return failure status instead of allowing false-green runs.

### Evidence in this continuation
- `node scripts/check.mjs`: 50 existing demo/frontend checks passed, 138 demo route/state templates rendered. These DO NOT execute the PHP storefront or backend.
- `node --check cpanel/public/store.js`: passed.
- Static relative-include inspection: no missing required include files found. This is NOT PHP syntax validation.
- PHP and MariaDB/MySQL executables are absent. `apt-get update` failed due to environment process/user permission restrictions; no elevated or policy-bypassing retry was attempted. PHP lint, migrations, new/old database suites and browser checks remain UNRUN.

### Remaining work (retains original full scope)
- Execute the new PHP suites before expanding payment/identity functionality; fix actual runtime/SQL/HTTP failures. A PHP 8.2+/PDO MySQL and MySQL/MariaDB development runtime is required.
- Approved design parity; customer recovery/address book/guest tracking, review photos and richer product content.
- Coupons/promotions, taxonomies/brands, full SEO and analytics/consent.
- COD advance rules, payment proof/verification/gateway and webhooks, courier, returns/refunds, settlement reconciliation, full COGS/profit, multi-item offline POS and exports.
- Structured provider settings/connection checks, chosen BulkSMSBD adapter (only sms.net.bd adapter exists), SMTP, OAuth, notifications.
- Actual customer AI and channel handoff; Owner verified email/AI/business adapters and tested safe backup/test/rollback runner. Owner AI remains locked and financial actions remain prohibited.
- Installer recovery/concurrency and production hardening, responsive/browser QA and all acceptance gates.

This checkpoint is a saved continuation, not a final release. The full project is still incomplete.


## Continuation checkpoint — 12 October 2026

User confirmed Apache and MySQL run on their XAMPP. We cannot access that localhost from this workspace. User explicitly instructed **all testing at the end**; defer test execution until implementation is finished. No new tests were run in this continuation and no new pass result is claimed.

Implemented in source:
- Durable coupon administration with RBAC, normalized unique codes, fixed/percentage amounts, minimum spend, caps, UTC dates, pause and optimistic editing. This is global-use coupon functionality, not all promotion variants.
- Server-side checkout discount snapshots and final version/availability revalidation. Coupon usage is recorded atomically with order placement and released once on unpaid cancellation. Replay returns the same order. Checkout form fields are retained when discount validation fails.
- Order discount shown in confirmation and account history; historical redemption records retain original code/amount even after coupon edits.
- Guest tracking with session-scoped, expiring OTP challenges, hashed codes, persistent wrong-attempt limits, resend delay and phone/IP throttles. Reveals only the exact verified order's minimal status information, not account history or private address data.
- Append-only order status timeline records at creation and transitions. No made-up historical/courier events.
- `app/Schema.php` centralizes nine migrations; browser and CLI setup use the same list. Added coupons.sql and tracking.sql without rewriting existing schema.
- Existing test fixture cleanup adjusted for newly referenced order-event/coupon tables, but suites were not executed.

Still pending: the remaining implementation and release gates listed above. Test design/extension for coupons, race conditions, tracking ownership/expiry and final HTTP acceptance belongs to the last testing stage. No final ZIP or live deployment was handed off.

## Current handoff checkpoint — 13 October 2026

The user now explicitly asks for current source ZIP and a detailed continuation prompt for another account. This supersedes the earlier no-partial-ZIP preference for this transfer. Current authoritative inventory: `../handoff/STATUS-AND-BACKLOG.md`; design overrides: `../handoff/DESIGN-LOCK.md`.

Current local source includes the prior manual payment proof/receipt/advance code plus newly completed return/refund review and customer tools. New schemas `refund-review` and `customer-tools` bring the centralized list to thirteen. Return inspection and sellable stock posting are separate; refund pending/approved commitments, exact human review, immutable approved source/destination/amount and private evidence-backed manual payout recording are separate from money transfer. Dispatch is held for recorded/committed refunds. Customer OTP recovery and saved address CRUD/prefill are wired into the storefront; password/phone changes invalidate older customer sessions.

All of these are implementation code, not claimed runtime passes. No new PHP/SQL/browser/JS tests were run. Existing fixture dependency lists were adjusted, but coverage/runtime review remains required. Full request/photo returns, gateways/courier/settlements, full SEO, actual AI/SMTP/OAuth, design parity, reports and many master modules remain incomplete.

Master V5, the original spec, design rules, current status, continuation prompt and acceptance checklist are bundled. No provider calls, public deployment, live-code release or real financial operation occurred. The delivered handoff snapshot is not production-ready.

## Continuation checkpoint — 14 October 2026

Implemented and wired, NOT executed or accepted:
- `customer-engagement.sql` is schema 14: wishlist, saved bag/merge markers, customer return requests/evidence/events/receipt links and review photos. No production migration executed.
- `Wishlist.php` and storefront page/buttons: account-owned durable save/remove; bounded list and inactive-product fallback without disclosing draft details.
- `SavedBag.php`: SQL-backed customer bag, once-per-authentication guest merge, serialized per-customer mutations and transactional decrement during regular order placement. Buy It Now remains separate. Logout/expiry clears the browser's account bag without deleting the saved database bag.
- `ReturnRequests.php`: authenticated delivered-item request with optional private evidence, idempotency and aggregate quantity commitment checks. Admin reviews pending requests with version checks and customer-visible instructions. Physical receipts link accepted requests, support partial receipt and update request history; no automatic restock/refund. Existing manual received-item lookup now locks before aggregate reads to avoid a pre-lock repeatable-read snapshot for quantity limits.
- Profile name editing and authenticated password change/current-password/rate checks; password changes revoke other sessions/challenges and rotate the current session.
- Review photos: up to three validated JPEG/PNG/WebP images per delivered-owner review; private until approval, staff moderation access, hidden/archived access revocation. Transaction failure cleans newly moved files.
- Header inline search suggestions from actual catalogue with cancellation/stale-response guards, full-search fallback and keyboard handling; approved mobile tab icons/order/indicator reused. Additional PHP UI still needs complete visual parity.
- Updated fixture cleanup dependencies, prepared wishlist/bag/return integration assertions in `tests/customer-content.php`, and expanded the final acceptance checklist. No tests, PHP lint, SQL migration or browser QA run, in accordance with testing-at-end instruction.

Remaining: full master backlog still applies (taxonomy/variant costing, purchasing/POS/accounting, payment/courier, full SEO, providers/AI, installer recovery and final testing). New customer functionality also needs all acceptance checks. No new completion percentage or readiness claim.

## Continuation checkpoint — 21 October 2026, SEO public routes (development, not release)

- Schema 16: `database/seo-routes.sql` stores unique case-sensitive canonical product paths and reserved old paths in `aelvo_public_routes`. An additive product backfill uses `INSERT IGNORE` plus post-migration ownership integrity check to flag conflicts without deleting existing records. This is NOT a tested, versioned migration runner.
- `PublicRoutes.php`, `Catalogue.php`: atomic product save + path ownership, slug history 301, no silent reuse of historical paths, direct rebasing of prior redirects; exact route lookups only; RBAC/CSRF-protected, audited manual same-site redirects on Admin → SEO with enable/disable actions. Only permanent 301 is currently supported. 302/edit/review and full URL namespace are unfinished.
- `store.php` and `.htaccess`: new `/product/<slug>` addresses, original numeric product links redirect only when a live route exists, trailing slash 301 without carrying arbitrary query parameters, unknown/draft/archived routes 404; relative asset base remains same-store. Internal product cards link to canonical paths.
- `Seo.php`, `sitemap.php`: homepage root canonical/index entry, clean product canonical/sitemap URLs, noindex/follow for normal filter/sort and page-specific unfiltered shop pagination, 404 for out-of-range pages. Sitemaps require a configured indexable site and published canonical route.
- Offline `php tests/seo-routes.php` added to final suite alongside prior tests; local path/slug rules passed without a DB. Local PHP 8.4.24 syntax checks observed 70 files with no syntax errors. MySQL migration/runtime, Apache routing/301s, browser, full security and SEO acceptance NOT executed.
- Remaining V5 SEO: per-product/category/brand/CMS overrides and taxonomy, homepage controls, fuller redirects, canonical overrides/validation, JSON-LD, robots/staging, analytics consent/verification, audits/warnings and production proof. Remaining entire ERP and approved UI scope retained.

## 21 October continuation — product SEO / staff lines / supplier operations / quarantined inventory

Implemented in code (not database/browser accepted): product SEO overrides, social tags and per-photo ALT with `seo-entities.sql` (17); staff manual multi-line COD orders and a private printable order summary; multi-line purchase draft entry and supplier directory, shipping/other/discount cost allocations, private printable purchase records and verified supplier payout accounting (`supplier-operations.sql`, 18); separate on-hand/reserved/quarantined/available stock and reasoned idempotent hold/release/writeoff (`inventory-quarantine.sql`, 19). Quarantine checks were wired into online quotes, checkout transactions, staff orders, catalogue and saved bag. Stock and payment events stay separate. The approved `dist/` reference has not changed.

Observed local check: PHP 8.4.24 lint checked 76 source PHP files with no syntax errors. There is no local PDO MySQL/MariaDB or user XAMPP access; no SQL migration, security/concurrency HTTP test or real provider operation was executed. Feature completeness, UI parity, cost-layer COGS and production acceptance remain unfinished. Final testing is deferred at the user's request. No standalone checkpoint artifact is required for each implementation increment; retain cumulative source and factual verification notes inside the project.

Offline SQL command parser check executed: `php cpanel/tests/sql-parser.php` passed bundled command splitting and quoted/comment semicolon cases, without using a database. PHP syntax preflight covers 76 files. These are not acceptance, business logic or concurrency tests.

## 21 October cumulative implementation — database-backed taxonomy and public collection SEO

Source implementation added (not runtime/production accepted): additive schema 20 (`taxonomy-seo.sql`) with five existing fashion root families, subcategory/brand records, backfill into a product-taxonomy association without overwriting legacy catalogue fields; Admin SEO editor with CSRF/RBAC/optimistic update/audit and product editor associations; category and brand public pages, breadcrumb/product discovery, pagination, language/metadata links and canonical-aware sitemap; historical slug reservations and 301 routing with the existing transactional route registry. All indexability remains gated by global indexing. Existing `dist/` reference remains untouched.

The only currently executable local checks for this increment are PHP syntax, bundled SQL parser and offline path-validation assertions. No MySQL table/migration, Apache redirect, user browser, payment/notification, concurrency, permission behavior or UI parity test has been executed. The full master scope and final cPanel release gates remain pending. User prefers no separate checkpoint file and the complete implementation before final XAMPP acceptance.

## 21 October — CMS page module and sitemap (source-only increment)

Code added: `app/CmsPages.php`, `database/cms-pages.sql` (schema 21), Admin custom-page authoring with separate draft/published revisions, enabled/footer flag, Bangla/English body/headings and page metadata, explicit reviewed publish, `/pages/slug` route ownership and 301 history; fixed About/Privacy/Terms/Delivery page SEO fields with permission enforcement; updated sitemap with bounded static/product chunks and eligible CMS pages. Internal source/checklists updated; no separate user checkpoint artifact requested. Existing `dist/` design unchanged.

Checks executed on this source: PHP CLI syntax preflight and offline `tests/cms-pages.php`, `tests/seo-routes.php` and `tests/sql-parser.php`. No PDO MySQL, Apache URL routing, simulated concurrency, HTTP response, browser, live provider or real payment tests run. Full rich CMS editor, forms/help/FAQ, all V5 SEO, analytics consent, accounting/payment/courier/AI, release safety and approved design parity remain pending. The new table and code have **not** been accepted for production.

## 21 October — rich CMS source increment (not integration accepted)

Source-only: `CmsBlocks.php` with bounded typed bilingual content, XSS-safe templates and FAQ structured data; editable rich blocks/CTA/image ALT UI; authenticated saved-draft preview; published-only CMS media mapping and checked same-store CTA routes; SEO CMS resolver corrected to consume the published page snapshot. New schema 22, `cms-rich-content.sql`. Existing `dist/` remains unchanged and protected drafts never act as public page templates. Validated with CLI syntax and offline regression scripts; MySQL publish/rollback/permissions/real browser/media ACL tests remain for the final XAMPP stage. This is a structured editor, not a full design parity/WYSIWYG release. The full Master V5 backlog remains open.

## 21 October — finance reconciliation (source-only, not integration accepted)

Added read-only `app/FinanceReconciliation.php`, wired Owner/finance-authorized `Reports` view and protected `public/reconciliation-export.php` (formula-safe CSV, each exception category capped at 100 rows with truncation notice). Diagnostics contrast account snapshots with signed ledger sums and latest entry balances, demand zero-sum two-account transfers, match independently recorded manual receipt/refund/supplier/courier/gateway payouts to their unique finance-event ledger entry and direction, show received-supplier debt/overpayments, payment-overcollection/refund anomalies, courier and gateway over-settlements, pending external reconciliation and received purchase lines lacking landed-cost allocation. These queries NEVER post cash, infer paid provider balances or advertise a P&L where stock costing is incomplete. Reconciliation requires BOTH `reports:view` and `finance:view`; unprivileged report viewers cannot open the export.

This is explicitly an offline PHP source implementation. Verified PHP syntax and the pure regression script `php cpanel/tests/finance-reconciliation-offline.php`; existing offline SMS/CMS/SEO/SQL/courier/gateway checks were also executed. No MySQL data, historical ledger migration, financial reconciliation browser view, concurrency, XAMPP, provider or production check has been run. Read-only diagnostic queries and 100-row export limits require XAMPP/production-schema acceptance later. Full COGS/profit/loss implementation, cost-layer attribution and returns accounting are still unresolved; do not invent margin figures. No new separate checkpoint artifact requested by user.

## 21 October — PHP storefront/Admin design work (source implementation only)

- Public product page now renders all **published** gallery media as selectable keyboard-accessible thumbnails; selecting a colour chooses that colour's photos when present. Fallback imagery stays local; source stock checks remain on server. Existing `dist/` reference is not modified.
- Confirmed Add-to-Bag now redirects back to the canonical product page with a one-time session confirmation and an explicit View bag action. Buy It Now remains a distinct checkout bag; wishlist, OTP and payment policy are not changed.
- Search autocomplete links use canonical slug URLs from server-side search results instead of legacy numeric links. Desktop store nav includes the already-supported fashion categories.
- Admin PHP page now uses responsive sage workspace/sidebar patterned on the approved `dist/assets/admin.js` screen, real user name, RBAC-filtered module navigation, mobile module disclosure, topbar and existing CSRF-protected sign-out. The underlying AdminView modules/forms and permissions stay unchanged; feature-complete screen-by-screen visual parity is **not** claimed.
- Observed: all 108 PHP files passed CLI `php -l`, and `node --check cpanel/public/store.js` passed on this snapshot. No MySQL, Apache browser appearance, accessibility QA, payment/provider call, or production acceptance performed.

### Same-session continuation — product variant controls and customer navigation

- Product detail now exposes server-derived colour swatches and size buttons, with in-stock option selection, available quantity and published colour gallery sync. No-JavaScript users retain the required native variant selector. Colour HEX is validated for rendered SVG, and variant/size/colour strings are HTML-escaped; final order quote still validates variants and pricing on the server. This is a visual/interaction implementation, not complete UI parity.
- New offline `tests/variant-picker-offline.php` verified fallback selector, escaped user text and current stock option markup; `node --check public/store.js` and PHP syntax lints passed. Browser keyboard, translation, Cypress/Playwright, real stock/checkout, database and user XAMPP tests remain UNRUN.

## 21 October — staff access hardening and authenticated support updates (source-only)

- Added `staff-sessions.sql`: staff login sessions capture a server-side revision; changing staff role grants, disabling, or re-enabling a staff member increments the revision and rejects prior sessions on the next request. Owner identity remains separately protected; staff status transitions require Owner, explicit confirmation, active staff profile ownership and audit entry. Disabled staff may be re-enabled with their existing grants, requiring a new login.
- Added `support-public-updates.sql`: staff can explicitly publish a customer-facing update only for a support ticket originally submitted from a signed-in customer account. Private staff notes/events are not exposed in the customer query. Guest tickets remain unlinked, even if a phone, email or order reference matches. The customer sees only their own ticket and explicitly public updates; no SMS or email is automatically sent.
- Customer support account view, Admin support editor/detail and Team status controls now use these server-enforced rules. Existing published storefront design reference remains unchanged. Both schemas are appended to the ordered additive migration list (now 28).
- Source verification: 117 PHP files passed CLI syntax. Targeted `staff-support-offline.php`, `support-reports.php`, and bundled SQL statement parsing passed. A wider 20-script attempted run had 19 passing; `customer-content.php` could not run because the container lacks PDO MySQL (same limitation affects isolated MySQL suites). No MySQL migrations, Apache HTTP redirects, staff concurrent sessions, CSRF browser forms or customer ticket-ownership browser tests were executed. These remain final XAMPP acceptance gates.

## 21 October — real website customer chat foundations (source only, NOT integrated)

Added `app/CustomerChat.php`, `app/CustomerAi.php`, `database/customer-chat.sql`, `public/chat-view.php`, `public/admin-chat-view.php`, and `tests/customer-chat-offline.php`. Storefront Help links to an authenticated/session-bound website chat. Chat history is durable; guest identity is session-bound, not inferred via phone/email. Customer messages are request-token idempotent. Only explicit customer opt-in with configured Owner-encrypted OpenAI settings can call the Responses endpoint. Provider is absent by default, so chat is human queue only. AI cannot invoke tools/checkout/order/payment/stock operations. Staff need both support and website-channel permissions; human takeover atomically invalidates in-flight AI responses and prevents AI resumption. No WhatsApp/Messenger integration or Owner AI unlock was added. The installed migration order now has 29 additive schema files. Only PHP syntax, source wiring and offline validation were run; MySQL migrations/transactions, real AI provider calls, channel provider handling, browser QA, privacy tests and XAMPP acceptance are still NOT performed. No production readiness claim.


## 21 October — Owner email identity and exact-change review foundation (source-only)

Added TLS-only SMTP adapter and encrypted owner-specific Admin SMTP settings; original-owner email challenge table and real emailed code verification with rate/attempt/expiry limits; short-lived second email-code + account-password Owner AI reauthorization; isolated owner-only review page supporting draft product-text proposals, expiring exact SHA-256 approval and history. No model is authorized to approve/execute changes. Staff, inventory, finance and code-release operations were removed from the Owner AI operation allowlist; actual execution remains disconnected. No test mail, owner verification, live product modification or provider action was carried out. SQL migration 30 is additive. Must still test SMTP, MySQL, browser, expiry/race/CSRF, full design and release backup/rollback on isolated XAMPP before production. Final Owner AI customer/channel functionality remains pending.

## 21 October 2026 — Admin operations workspace continuation (development code only)

- Added `app/AdminWorkspace.php`, a read-only, permission-checked PDO service. Products and variants now have 25-row server-side pagination, validated query/status parameters, literal LIKE wildcard escaping and bound integer LIMIT/OFFSET; no fixed 200/300 record cutoff. Product search includes names/slugs and variant SKU, with batch-loaded available stock totals. Inventory filters expose low/out-of-stock, quarantine and inactive variants separately without modifying stock. All figures use actual database rows, not the `dist/` demo fixtures.
- Replaced the empty Admin welcome screen with per-module, permission-scoped work queue counts. No account, payment, profit or courier receivable is represented as cash. Added approved warm off-white/sage workspace styling, mobile/reduced-motion support, filter/pagination controls and live links. Existing POST-only, CSRF/RBAC-guarded product archive remains available.
- Added malformed-filter HTTP 400 preflight on the actual Admin route; no schema change, financial movement or provider call in this phase.
- Observed evidence: 138 PHP files passed `php -l`. Seven targeted offline scripts passed, including new `tests/admin-workspace-offline.php` with a PDO-shaped stub for query bindings, pagination boundaries, and denied-role data isolation. No PDO MySQL, browser, XAMPP, shared cPanel, concurrency or production acceptance tests were run. Application remains incomplete and not production-ready.

## 2026-10-21 — uncertain Steadfast booking review (code only)

Added `CourierResolution.php`, schema `courier-resolution` (#31), Owner-delegable `courier:approve` permission, private evidence access, and Admin uncertain-booking case queue with two-person approval of **existing** verified parcel references. A stale `Prepared` attempt older than 30 minutes remains blocked and can be reviewed; rejected and portal-not-found findings do not unlock retry or fabricate a parcel. Recorded reviews never post cash, issue payments, deliver orders or change stock. Both CSRF-guarded routes and offline input/policy tests are included. No MySQL, cPanel, live Steadfast, courier cancellation, parcel ownership API, concurrency or browser integration acceptance yet. This is still a development snapshot.

### 2026-10-21 — Courier exception workflow (development; unverified against MySQL)

Added schema #32, `CourierExceptions.php` and Admin evidence/independent-review queues for cancellation requests, Steadfast-confirmed cancellation, failed delivery and return-to-sender reports. Added separately reviewed delivery-recovery clearance, private evidence ownership/access checks, idempotent request keys, order transition and new COD collection holds, and permission-scoped workspace indicators. No provider cancellation request is issued; no order, stock, refund, courier collection or bank ledger effect is inferred. Only code-level PHP lint/offline checks ran; fresh/upgrade database, failure/concurrency, browser and actual provider tests remain unverified. Final production readiness is NOT established.

## 21 October 2026 — Gateway payment exception investigation (development source only)

- Added additive migration #33 `gateway-exceptions.sql`, `GatewayExceptions.php`, Owner-delegable `payments:approve` grant and Admin Payments investigation queue. Existing provider charge validation and financial ledger code remain separate.
- Staff with `payments:edit` **and** `finance:edit` can file an evidence-backed case for an applicable uncertain session, provider validation discrepancy, risk hold, settlement discrepancy, or provider dispute. Original attempt state, and unsettled gateway receivable when present, are recorded as immutable review snapshots. Duplicate request keys and concurrent pending cases are rejected or idempotently resolved.
- A **different** staff member with `payments:approve` and `finance:view` can review or reject the evidence after rechecking current gateway state and settlement snapshot. All review operations are audit logged and do **not** validate a payment, finalize a failed payment, retry a charge, cancel an order, release stock, record refunds, write ledger entries or release a gateway hold. This remains a document investigation, not automated provider reconciliation.
- Private evidence download requires both payment and finance view permissions. Admin overview shows pending cases only with both permissions. The staff role editor can explicitly grant payments approval.
- Source-level PHP syntax and targeted offline safety/schema tests were executed during this phase; they do **not** validate live SSLCommerz, PDO MySQL DDL/transactions, two-staff browser sessions, document upload, provider dispute status, or release packaging on cPanel. Full approved Master V5 gateway reconciliation remains incomplete.

## 2026-10-21 — Grouped gateway payout matching (development source, NOT live tested)

- Added schema #34 `gateway-payout-batches.sql`, `GatewayPayoutBatches.php`, Admin Payments batch form, private statement access and cumulative reporting/reconciliation. A single actual bank credit may match multiple validated SSLCommerz receipts with separate documented fee allocations. Unique bank-line hash per destination account and strict money conservation prevent repeated same-reference payouts; distinct charges cannot be allocated beyond their remaining receivables. Transactional ledger posts the **bank credit only once**. Legacy single-transaction postings are still visible, but the old single-charge Admin POST route has been retired.
- Payment order state, gateway receipt validity, chargeback/refund outcome and stock are **not changed** by manual payout matching. Pending gateway investigations block settlement. Evidence remains private and access-controlled. No real provider endpoint, refund, transfer or bank statement was accessed.
- Source-level PHP lint and targeted offline parser/wiring tests were executed. No PDO MySQL, Apache/browser, concurrent payout writes, provider batch statements, real bank reconciliation or backup/restore acceptance yet. Failure-safe behavior and correctness at runtime remain unverified.


### 2026-10-21 — Customer review workspace & storefront presentation (development only)

- Replaced 100-item Admin Reviews display with permission-controlled, searchable, status/rating filtered 20-item pages; distinct view/edit permissions, versioned moderation, private submitted-photo access and original customer text are preserved.
- Product pages show only approved verified-purchase reviews with an actual approved-count/average summary, 10 per page, safe next/previous links, bilingual labels and responsive photo cards.
- Added `cpanel/tests/reviews-workspace-offline.php` for filter bounds, SQL wildcard escaping, review visibility, authorization and presentation wiring.
- Checked PHP syntax on 149 PHP source/test files and ran eight selected offline scripts successfully. No PDO MySQL, browser, provider, concurrency or production acceptance testing was performed.
- No new database migration and no live state was changed. Approved `dist/` visual source is unchanged.


### 2026-10-22 — Account cancellation requests and order history (development only)

- Added `order-cancellations.sql` as schema #35 (additive, no existing order rows overwritten) and an account-bound, idempotent cancellation request journal. Only signed-in customers with an existing account-order association can request cancellation on Pending/Confirmed orders; phone/reference matches and guest tracking cannot establish ownership.
- Added bilingual customer request/status/visible-staff-update and an order timeline with confirmed courier status. Request submission never marks an order Cancelled, reserves/releases stock, issues a refund or sends an outbound notification.
- Added a staff Orders cancellation queue, permission-checked review/messages/declines, optimistic review versions and an event history. Staff acceptance must use the pre-existing `Orders::transition` authorization and payment/stock/courier checks; it is not inferred from a message.
- Pending customer cancellations now block new courier booking and `Dispatched` transitions under the existing order-row lock, until staff declines or the order is actually cancelled.
- Repaired brittle *offline test assumptions* that a particular migration was always last or that two classes were always adjacent in the bootstrap. These repairs do not demonstrate payment or database behavior.
- PHP syntax and offline policy checks run; **no PDO MySQL/XAMPP, browser, provider, concurrency or production acceptance** is claimed. Original `dist/` remains unchanged.

## 2026-10-22 — Configurable tax and immutable order tax snapshot (source-only)
- Added `TaxPricing.php` with explicit Owner-only, tax-exclusive, integer-basis-point configuration. Defaults off for old installations; no statutory VAT assumptions. Separate optional shipping taxation.
- Added `order-tax.sql` after `order-cancellations` (migration #36) with additive `orders.tax_minor` and immutable per-order tax snapshot. Merchant must not run migrations without verified backup and acceptance.
- Checkout quote/placement and staff-assisted orders calculate tax server-side, compare current tax policy under lock, and persist taxed totals before advance/COD computation.
- Customer review, printable order summary, accounting coverage, and CSV report fields show separately charged tax. Financial recorded bridge excludes charged tax but remains explicitly NOT profit/statutory accounting; refunds and tax remittance allocation outstanding.
- This is implementation pending MySQL/Apache/concurrency/browser acceptance; no real tax policy/rate was selected or charged.

## 22 October 2026 — Admin activity review & purchase register

- Added Original-Owner-only **Activity & audit** workspace over the existing append-only `aelvo_audit` table. Strict UTC date range, actor/system filter, event search, stable pagination, CSV download capped at the newest 1,000 matches, and CSV formula-injection guarding. Logout now records `auth.logout` before session invalidation. No audit records are edited or deleted through this screen. This is a **legacy event viewer**, not full Master V5 before/after, IP/user-agent audit evidence.
- Replaced the latest-100 purchase document register with SQL-bound reference/supplier search, Draft/Received receipt filter, unpaid/partial/paid classification computed from actual recorded supplier payouts, and 25-record pagination. Existing separate purchasing, receiving and payment authorization/actions remain unchanged. Supplier directory is still limited to its prior 200 suppliers; this should be addressed during complete supplier-page parity.
- Offline: `php -l` on 163 PHP files, 29 offline test scripts (including a new Owner-audit/purchasing PDO-stub regression suite) passed. **No MySQL execution, Apache/browser QA or transaction concurrency test has been performed.**


## 22 October 2026 — Approved Admin mobile navigation and module finder (source only)

- Ported the approved mobile five-position Admin navigation (Overview / Orders / Stock / Accounts / More) to the PHP Admin layout, with fixed soft-sage capsule, liquid indicator, safe-area spacing and reduced-motion rules. Direct tabs are rendered only for authorized modules; remaining authorized modules are accessible via the native, keyboard-focusable More panel. No staff permission grant or bypass is added.
- Reused the same module group definitions for desktop sidebar, mobile More menu and an in-place, permission-filtered Admin module finder. Finder only filters pre-rendered authorized module links, not private operational data. It works as a normal links panel without JavaScript; JavaScript adds filtering, escape and outside-click dismiss.
- `tests/admin-navigation-offline.php` checks mobile tab order, dynamic count, More activation, inactive staff, hidden unauthorized links, Finder filtering scope and source wiring. No database schema changes.
- These are source-level interaction improvements; mobile browser visual acceptance, MySQL RBAC integration and final production checks are deferred to the user's requested final XAMPP phase.

**22 October verification evidence:** `php -l` completed on all **168** bundled PHP files (no syntax errors). `node --check cpanel/public/admin-mobile.js` passed. Running all **43** bundled PHP test scripts yielded **40 passing** offline scripts; `checkout.php`, `customer-content.php`, and `database.php` stopped at their prerequisite check because PDO MySQL is not installed here, NOT because their database assertions were run. The new navigation test passed including the strict-CSP no-inline-style requirement. `UpgradeAudit::verifyPackage()` is required again after regenerating the manifest. No mobile visual, MySQL RBAC, or release acceptance was performed.
