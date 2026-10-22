## Payment/fulfilment safety continuation — 21 October 2026

Added a conservative gateway-attempt hold to order cancellation, dispatch/delivery, Steadfast booking, manual receipt recording and manual payment-proof submission. Operations first lock the order, then check unresolved gateway attempts. Terminal independently reconciled failure/cancellation and verified receipts do not hold actions; unknown statuses fail closed. Gateway initiation now rejects a pending manual proof or refund commitment, while validation of funds during an outstanding refund commitment remains in Review rather than posting an order receipt. Order detail and customer checkout/payment-proof views display the hold without private provider references; second online payment forms are hidden until reconciliation. Previously finalized failures and mismatched validation IDs cannot silently become a new success. **No provider failure-reconciliation workflow was invented**; those exceptions still require the dedicated implementation and final acceptance.

Static PHP preflight checked 118 PHP files with zero syntax errors; 12 selected offline scripts (including `tests/gateway-order-guard-offline.php`) returned success. This is **not** MySQL/callback/concurrent browser testing. No new schema was introduced.

# AELVO handoff status — updated 21 October 2026

## Latest implementation checkpoint — 21 October (NOT production-accepted)

The prior 45–55% figure remains an unmeasured historical estimate; it has not been re-scored. Existing `dist/` is untouched; PHP/MySQL/cPanel remains the stack and cPanel application is still incomplete.

- Selected **BulkSMSBD** `Sms.php` adapter uses the vendor-published fixed HTTPS single-SMS endpoint, POST form fields `api_key`, `senderid`, `number`, `message`; local 01... numbers are converted to 8801...; only response code `202` is treated as accepted submission, not delivery or OTP verification. Unknown/failed replies and absent encrypted configuration fail closed. Legacy `smsnetbd` credentials do not silently override the chosen provider. Real sending and provider responses have NOT been verified.
- Owner-only, CSRF-guarded **BulkSMSBD Admin configuration** saves encrypted API key / approved sender ID / OTP language via `Settings::bulkSmsBd()`. Blank key on an update retains the existing valid key. Status says *saved, not live tested*. English SMS on masking routes is an explicit choice needing merchant/provider confirmation. No real API key was provided, stored or sent in this development snapshot.
- Partial **SEO 404 implementation**: unknown storefront `page`, missing product, and absent/unpublished info pages return HTTP 404, noindex metadata, and a bilingual custom screen with live catalogue links. An additive `seo-errors.sql` schema aggregates sanitized route tokens without raw query strings or IPs; records are bounded, reviewed by authorized staff with version check and audit. This does **not** install Apache ErrorDocument handling for arbitrary unknown static URL paths, nor implement the remaining SEO redirect/slug/analytics system.
- **Schema 15** appended to `app/Schema.php` after `customer-engagement`; existing data is not rewritten. This is a new migration to run only on a backed-up development database in the final integration phase. No migration was executed here.
- Offline checks actually run: PHP syntax lint of all 68 PHP files and standalone `php cpanel/tests/sms-adapter.php` (documented response parser cases). PHP extension `curl` and a MySQL service are not available in this container. No database suites, browser QA, remote SMS, installer acceptance, concurrency, or live deployment were run.

### Exact next priorities

1. Complete SEO V5 per-entity metadata and routing: category/brand/CMS registries, unique slugs, atomic 301s, redirects, canonical-aware sitemap/indexability, truthful schema, consent analytics, 404 Apache route integration and restricted role permissions. See `handoff/REQUIREMENT-COVERAGE-2026-10-21.md`.
2. Connect the remaining approved PHP visual screens from `dist/` without altering the reference, and complete product taxonomies/variants/costs/media, inventory/purchasing/supplier/POS and financial reconciliation modules.
3. Real payment/Steadfast/settlement/notifications and authorized AI adapters, secure browser installer/updater and full scope acceptance. Keep Owner AI release locked.
4. At the final acceptance stage: on isolated `_test` database apply all 15 schemas, run PHP/MySQL/browser/concurrency suites, compare UI to `dist/`, verify production-host/provider failure modes. Obtain vendor credentials through Owner Admin (never chat) and test actual BulkSMSBD sender acceptance + delivery using explicitly authorized staging setup before opening live OTP checkout.

---

# Previous status — updated 14 October 2026

## Latest continuation — 14 October (all new paths UNRUN)

Read the 14 October section of `cpanel/PROGRESS.md` before historical coverage rows below. The earlier 45–55% estimate has NOT been remeasured. New code is not acceptance evidence.

New source: durable account wishlist and saved bag, guest-bag merge/replay guard, transactional regular-bag checkout decrement, customer profile/password controls, customer delivered-item return requests/private photo/staff review/partial receipt link, moderated review photos, inline catalogue search, and existing approved mobile navigation port. Views and services are wired. Schema 14 is `customer-engagement`, appended after `customer-tools`. Account/checkout/private responses are no-store. All tests remain deferred.

Remaining customer work includes complete approved screen parity, return cancellation/eligibility policy, failed delivery and wrong-item reconciliation, richer profile/security UX and full final acceptance. No automatic return-policy window was invented; staff reviews eligibility and supplies instructions. Existing broader backlog below remains authoritative for other modules.

## Read this before older progress notes

This is a portable, incomplete development snapshot. The user's latest instruction explicitly requests current code plus a prompt for another account, superseding the earlier prohibition on partial ZIP delivery for this handoff only. Do not call this a final/production-ready package.

Implementation is roughly estimated at 45–55% of the intended scope. This is not a measured acceptance percentage. No reliable total completion percentage exists until every master requirement is mapped to implemented and verified behavior. Provider adapters, approved UI parity and acceptance testing are substantial remaining work.

The existing `dist/` design is the approved baseline. The `cpanel/` application is the implementation target. Do not confuse feature-rich demo screens with completed backend modules.

## Source map and actual current coverage

| Area | Existing code | State / gap |
|---|---|---|
| Approved design | `dist/`, all assets | Rich demo; browser-memory fixtures; preserve current soft sage/mint, hover and hero fixes. Full PHP parity incomplete. |
| Owner/staff auth | `Security.php`, `Team.php`, `public/index.php`, core schema | Owner install/login/logout/session/CSRF/throttle and module/action RBAC present; fine-grained role editor, individual overrides, full profiles, staff recovery and verified owner email incomplete. |
| Catalogue | `Catalogue.php`, `Uploads.php`, commerce schema | Products, variants, per-colour private-storage images, archive, adjustments, search/category/price/stock filter, pagination. Full categories/subcategories/brands, attributes, variant prices/costs, richer media management and indexable routes incomplete. |
| Inventory | `Catalogue.php`, `Orders.php`, `Purchases.php` | Stock/reservation movement code and locks present. Full quarantine/dispositions, supplier returns/transfers, low-stock workflows and cost-layer accounting incomplete. |
| Checkout/orders | `Checkout.php`, `Orders.php`, checkout/customer/order-finance schemas | Price/stock recheck, phone-specific OTP, quote ownership, idempotent placement, reservations, COD, basic fixed/percent advance, delivery settings. Full order admin/detail/search/bulk actions, taxes/rule engine, gateway expiry and broader transitions incomplete. |
| Customer | `Customers.php`, account/address views, customer-tools schema | Phone/password auth, OTP signup/phone change, own orders, newly added OTP password recovery/session revocation and address book. New paths untested; full profile/security UX, social sign-in, wishlist persistence, return requests/evidence and review photos incomplete. |
| Reviews | `Reviews.php` | Delivered-owned-item text review, moderation/reply with version checks. Photos and broader approved UX incomplete. |
| Tracking | `Tracking.php`, tracking view/schema | Guest reference + phone OTP, bounded session access, order timeline. No real courier event integration. |
| Coupons | `Coupons.php`, coupons schema | Fixed/whole-percent/global limit/minimum/cap/schedule/pause, atomic redemption and unpaid-cancel release. First-order/per-customer/product/category/automatic combinations incomplete. |
| Payments | `Payments.php`, proofs/Settings/views, payments schema | Private proof upload; human verified receipts; unique transaction references, ledger entry, advances and dispatch hold. No actual gateway charging/refund/webhooks or full provider reconciliation. |
| Returns/refunds | `Returns.php`, `ReturnsView.php`, returns/refund-review schemas | Physical receipt/inspection, separate sellable restocking, quarantined remainder, refund proposals/reviews/commitment cap, approved immutable amount/source/method/destination, evidence-backed manual payout recording. Customer request/photo queue, failed delivery, alternative-destination policy, gateway execution and richer reconciliation remain. No money is sent by these forms. |
| Accounts/purchases | `Finance.php`, `Purchases.php`, finance schema | Cash/bank accounts, funds/transfers/expenses/corrections and basic one-line purchase receipt. Full supplier subledger/dues, multi-line landed-cost allocation, supplier payments, full POS and real profit reporting incomplete. |
| Content | `Content.php`, content views/schema | Bilingual hero/popup/fixed CMS drafts, revision-bound publication, scheduling, private assets/poster/video fallback. Full arbitrary page editor/slug ownership, contact/FAQ/help/bug reports, footer/social config and rich editor incomplete. |
| SEO | `Seo.php`, sitemap/robots | Basic global metadata, canonical base, conservative indexing and sitemap handlers. Master V5 full SEO scope NOT complete. |
| Providers | `Settings.php`, `Sms.php` | Encrypted settings foundation. Only actual SMS adapter code is sms.net.bd/Alpha SMS. User-selected provider is BulkSMSBD: mismatch remains; do not relabel it. No live credentials or delivery tests. |
| Customer AI/inbox | `dist/` preview, `INTEGRATIONS.md` | Demonstration only. Actual model, durable inbox, human assignments/channel RBAC and signed Meta webhooks still absent. |
| Owner AI | `OwnerAiPolicy.php`, `OwnerAiService.php`, owner-ai schema | Policy/hashed OTP/grants/exact approvals foundation. Owner-only Admin has real-email SMTP verification path, second code plus password reauthorization, expiring proposal review and audit. Provider delivery/DB/browser not tested; AI inference and business execution adapters are NOT connected, and financial/inventory/staff/live release remain locked. Safe backup/staging/rollback runner not implemented. |
| Installer | `public/setup.php`, `bin/prepare-install.php`, `Schema.php` | Token-gated empty-db browser bootstrap, config lock, schema importer. Complete no-SSH cPanel wizard, interruption/recovery/concurrency, directory layout and all installer screens still need work. |
| Testing | `scripts/check.mjs`, PHP tests | Historical preview report: 50 checks / 138 rendered demo states. NO new test execution in this snapshot. No PHP/MySQL/browser/concurrency acceptance evidence. |

## Current migrations, in order

`core`, `commerce`, `finance`, `owner-ai`, `checkout`, `customers`, `content`, `coupons`, `tracking`, `payments`, `returns`, `refund-review`, `customer-tools`, `customer-engagement`.

`app/Schema.php` is authoritative. There are fourteen SQL files. The last three are additive; do not assume an existing nine-file development database is current. No production database has been migrated. Older coverage rows below are the 13 October baseline; use the latest continuation above for changes. Fresh setup and upgrade paths both require later tests; schema importer is not a mature versioned migration runner.

## Changes finished for this handoff

- Completed the existing manual-return/refund continuation with explicit refund proposal/review versus payout recording, order-locked outstanding commitments and independent stock posting.
- Added private mandatory payout evidence on new refunds, original-destination verification checkbox, immutable approved amount/source and idempotent completion.
- Added dispatch hold while refunds have been recorded or pending/approved refund commitments exist. Business reconciliation beyond this conservative hold remains to implement.
- Added customer OTP password recovery, customer session-version invalidation and signed-in address CRUD/use-at-checkout. Saving addresses does not verify phone numbers.
- Updated setup/schema references and existing fixture dependency lists. No suites were executed.
- Included complete master V5, previous original spec and approved design addendum for cross-account continuity. No new design or publication.

## Prioritized remaining implementation

1. Map EVERY requirement in MASTER-SPEC-V5 and the approved addendum to a file/route/service/permission and status. Preserve existing work; mark absent functionality openly. Use the original spec only for context, not to drop V5 SEO additions.
2. Bring production storefront/customer/admin templates to approved design parity. Build a thin reusable PHP view/controller structure without a wholesale framework rewrite; preserve class names/assets and all interaction/translation/accessibility requirements. Prevent fake data from appearing as real inventory or transactions.
3. Complete operational catalogue, taxonomies/brands/attributes, per-variant pricing/costs/SKUs, stock movements, low-stock handling, multi-line purchasing/suppliers/dues, inventory costing, POS/manual receipts and filters/details. Current staff delivery creation has its own simplified flow; document and resolve verification override policy rather than silently expanding it.
4. Complete customer profile, persistent wishlist, return request/photo queue, evidence inspection/partial receipts, failed/wrong/damaged-item reconciliation, review photos and account UX.
5. Complete configurable shipping/tax/discount/COD advance rules by product/category/risk/order value, plus real chosen payment adapter, safe signed callbacks, idempotency, refunds and payment expiry. Distinguish claims, verified receipts, collection, settlement and refund execution.
6. Implement Steadfast booking/status/cancel via current official merchant contract, no fake IDs. Snapshot verified remaining COD, timeout reconciliation before retry, out-of-order event handling, failed-delivery returns, settlements/batches/fees/partial payouts/shortages and audit trail.
7. Full account/expense/supplier reconciliation, actual COGS and profit/loss, period/channel/product reports, CSV/Excel/PDF export, receipt/invoice generation. Never report revenue/cash inflows as profit or count unremitted courier COD as bank funds.
8. Implement ALL V5 SEO modules: entity metadata, categories/subcategories/brands/CMS/home; safe unique slugs and atomic 301s/route ownership; redirect loop/self/external-host rules; canonical-aware indexable sitemap splitting; robots/staging controls; valid truthful JSON-LD; verification; consent-ready GA/GTM/Meta/TikTok/Hotjar settings; 404 monitoring and admin warning overview. Do not invent ranking scores or product ratings.
9. Complete provider-specific Admin forms, mask/update secret semantics, verified enable/configure states, BulkSMSBD adapter, SMTP/queued notifications and staff/owner recovery; social OAuth only with verified official flows and safe linking. Credentials added later in Admin, optional installer steps can be skipped. Missing required OTP provider must not introduce verification bypass.
10. Customer AI and durable website/WhatsApp/Messenger inbox with language matching, private data ownership checks, explicit customer checkout confirmation and authorized staff takeover. Owner AI requires verified original-owner email session, exact approval, narrow nonfinancial adapters and fixed release runner with verified backups/tests/rollback. Keep it locked until these exist and are validated.
11. Finish browser installer/general store settings/role seeding/no-SSH shared-hosting delivery/installation recovery, security hardening and all deployment docs.
12. After implementation, execute the final acceptance matrix on isolated PHP/MySQL plus desktop/mobile browsers, fix failures, then deliver one complete cPanel-ready release. Runtime absence is a blocker to *claiming verification*, not proof of success.

## Known review targets / limitations to carry forward

- No new PHP lint or runtime validation has occurred; source presence is not proof the workflows run. Existing test fixture coverage is incomplete for newer migrations and many business cases.
- Large AdminView/store.php surfaces are functional scaffolding; layouts, pagination and input-preservation/error flows need consolidation.
- Current return workflow records delivered-item receipts; it does not implement customer return eligibility/windows or failed-delivery parcel reconciliation. Do not invent store policies. Wrong-variant sellable stock currently stays blocked/quarantined pending reconciliation.
- Refund destination verification is a human attestation in the current backend; it is not provider verification. No supported alternative-payee policy exists. There is no gateway payout executor.
- Private proof/document storage has validation and permission checks, but abandoned uploads/orphan evidence cleanup, download auditing, file scanning/limits and backup/recovery need final review.
- New OTP reset and phone-change epoch handling, same-time challenges/session revocation and address ownership/races need targeted acceptance tests. Account signup, checkout and recovery cannot be tested live without actual SMS setup.
- `due_minor` currently represents original order due after gross receipts; returns/refunds need a complete sales-credit/accounting model so reports never confuse amount refundable, credit due and collectible COD.
- Role model currently uses per-user roles as scaffolding; per-account financial access, distinct create/review/execute permissions and fuller staff overrides need master-spec parity.
- Payment/courier/AI/OAuth/SMTP real provider-dependent flows are absent, not merely awaiting keys. Adding a key to a generic settings record does not implement an adapter.
- Original user-supplied screenshots and motion video are not packaged. The actual interactive `dist/` source and its image assets preserve the implemented design/motion baseline; request originals only if a specific missing behavior cannot be inferred.
- No config.php, real provider secrets, populated database, paid orders or live deployment is included. Never depend on old ChatGPT Library IDs, private preview access or old workspace paths in the new account.


## Later checkpoint — 21 October 2026, public SEO route implementation (supersedes older route/schema/status wording)

Master V5 continues to govern ALL requirements. `cpanel/app/Schema.php` now lists **sixteen** SQL files: original fourteen plus `seo-errors` and `seo-routes`. `cpanel/app/PublicRoutes.php` implements current product URL ownership and immutable previous paths; Admin SEO handles local manual 301s, product slug updates preserve 301 history in one transaction with product changes, and the PHP storefront/links/sitemap use clean product URLs. Homepage canonical and sitemap URL use the configured root. Unfiltered shop pagination has individual canonicals and out-of-range pages 404; filter/sort URLs are noindex/follow. No database upgrade, HTTP rewrite or browser acceptance was performed. A code path's existence does not establish provider or production readiness. Full design parity, entity taxonomy/SEO, advanced redirects and all previously listed ERP/gateway/courier/AI/financial features remain on the backlog. See `IMPLEMENTATION-CHECKPOINT-2026-10-21-SEO-ROUTES.md` for exact code and test evidence.

## 21 October — structured CMS continuation

The authoritative `cpanel/app/Schema.php` now declares 22 additive schema files, ending `cms-rich-content`. New code: typed bilingual rich page sections/FAQ/CTA with strict server-side validation and escaping; private staff saved-draft preview; image release allowlist updated transactionally on an approved publication; CMS SEO metadata and server-side FAQPage from visible content. Staff content/SEO role gates and old published revisions are retained. Existing summary rows about 14 or 16 schemas are historical, not the latest count. No database/Apache/XAMPP or production tests executed, so maintain incomplete/untested status for all related acceptance requirements.
