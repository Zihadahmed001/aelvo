## 22 October 2026 — promotion eligibility, shipping offers and account limits (development only)

- Added append-only schema #41 `coupon-eligibility.sql`. Existing coupon codes/redemptions remain; existing codes default to unrestricted merchandise eligibility. Historical item coupon allocations deliberately stay `NULL` because their real per-item shares are not evidenced.
- Coupon Admin form now supports fixed/percentage/free-shipping offers; product, active category (including direct subcategories), and active brand targeting; verified-account targeting, first-account-order and per-account redemption limits; and local Asia/Dhaka schedules stored as UTC Unix instants. No offer was automatically activated or seeded.
- Customer checkout now calculates **only eligible** merchandise discount, splits exact minor units deterministically across order items, stores that allocation in newly placed order-item snapshots, and independently reloads current product/taxonomy/prices/settings/coupon eligibility during its locked order placement. A shipping offer removes only the configured delivery charge and records the avoided shipping fee separately from product discount. Tax and required COD advance are recalculated on the resulting quote. Old orders are not rewritten.
- For account-only offers, anonymous checkout remains unsupported; staff cannot attach an order by matching a phone number. Account-row locking protects first-order/per-account usage checks across concurrent placements, in addition to coupon-row locking and the existing order/idempotency guards. `coupons:edit` plus `customers:view` is required to target one customer. Guest OTP rules remain unchanged.
- Admin coupon register now has server-side search, status filters and bounded 25-row pagination instead of an arbitrary first-200-row listing. Quote and redemption rejection leave the Bag intact; an unavailable promotion requires a new checkout review.
- Observed local checks: PHP syntax and pure/offline tests on the actual source, including eligibility, category ancestry, allocation exactness, cap, minimum spend, exclusive expiration and legacy migration-order regression; no DB, browser, concurrent MySQL, SMS or financial-provider transaction executed. The release SHA-256 manifest only checks local package integrity, not publisher authenticity.
- **Remaining promotion scope** includes automatic offers, combined offer precedence, merchant draft/preview/scheduling UX, fuller product-level campaigns, analytics, historical allocation reconciliation, partial refund allocation and dynamic banner-campaign linkage. Runtime migration, MySQL concurrency, UI and tax-policy acceptance are pending. NOT production-ready.

## 22 October 2026 — merchandising prices, approved product badges and photo priority (source-only)

- New **append-only schema #40** (`product-merchandising.sql`) adds optional product sale price and manual Featured/New Arrival flags; existing products default to no sale/flags. Historical order line prices are untouched. Sale is valid only when 0 < sale price < regular price.
- Explicit variant prices remain independent; only variants inheriting the product base price receive its sale price. Catalogue price sorting/filtering, public product pages, product picker, SEO price range, checkout quote/locked placement and staff-assisted orders share the server-side effective price rule. No sale schedules or automatic expiry have been invented.
- To prevent undisclosed stacking, existing/new coupon records default to **not** combining with product sale. Owner-authorized coupon editor exposes `allow_sale_stack`; quote and transactional placement both fail closed when a restricted coupon meets a sale-priced variant. Full promotion eligibility/allocations are still not complete.
- Homepage product order prioritizes genuinely marked Featured/New Arrival products; cards label only server-provided flags. Hidden/draft products remain absent. Merchants explicitly set flags.
- Fixed a critical gallery issue: `aelvo_media.id` is a 32-character hexadecimal string, not an integer. Public colour-gallery rendering now accepts proper IDs. Product uploads append to gallery order, and authorized Admin users can choose any already-linked image as the primary using version/ownership checks and an audit event. Private/unpublished media remains protected by existing `media.php` policy.
- Observed in this local environment: 174 PHP syntax checks, 3 JavaScript syntax checks, 43 offline PHP scripts passed; 3 database-dependent scripts could not run because PDO MySQL is unavailable. Offline PHP source checks and relevant pure regression tests are available. **No MySQL DDL, coupon concurrency, actual payments, XAMPP/browser or production acceptance** has been run. Later acceptance must include migration, race conditions, visual/mobile gallery, sale/coupon cases, and provider failures.

## 22 October 2026 — product details and variant attributes (source-only)

- Additive migration `product-details` (#39) stores optional product SKU/barcode, short bilingual description, material/care, package weight/dimensions, tags and per-product low-stock threshold; optional material/style and attribute-version on variants. Existing products have no invented facts and retain 5-unit low-stock defaults until configured.
- Admin product edit saves these facts in the same authorized/version-checked transaction as the product, and stores variant material/style with separate optimistic edit protection; FIFO cost, stock, existing orders and variant prices are untouched. Duplicate product SKU/barcode rejects changes rather than overwriting a different product.
- Public product details are escaped and bilingual; selected variants show recorded material/style. Storefront/Admin SKU/barcode search and Inventory/Reports low-stock alerts reference these records. This does not implement full Master V5 catalogue, provider shipping weights, or product-level cost editing.
- PHP syntax and offline regressions executed without database/provider access; MySQL DDL, browser, concurrency and production acceptance remain pending.

## 22 October 2026 — variant-level BDT pricing (source implementation only)

- Additive schema `variant-pricing` adds nullable `price_override_minor` and optimistic `pricing_version` to variants. NULL inherits the product base price; prior stock, order-line snapshots and catalog records remain unchanged.
- Admin product editor creates and updates per-colour/size overrides with product ownership, RBAC, CSRF and stale-edit rejection. No direct stock writes.
- Product picker, product cards, catalog price filtering/sorting, bag subtotal, checkout quote + locked order placement, staff-created orders, search suggestions and Product structured data now use effective selectable variant prices. Checkouts reject price changes between quote and placement; historical order line prices are not rewritten.
- Offline checks are not proof of MySQL DDL/transaction or visual acceptance. The next isolated XAMPP integration phase must test migration upgrade, concurrent price edit vs checkout, price-filter performance and mobile/browser display.

## 22 October 2026 — refund tax allocation (development; unverified runtime)

Implemented separately recorded refunded-tax amount within approved refund proposals, historical missing-allocation holds, and refund-tax amounts in the financial bridge and CSV. Added additive migration 37; no refunds sent, tax policy invented, or MySQL migration executed. **Not production ready.**

## 22 October 2026 — approved storefront homepage and footer parity (development only)

The PHP storefront now renders the approved AELVO homepage editorial bag panel, published-category collection pills, curated product section with catalogue records only, and the AELVO notes/story link (shown only while the published About page is active). The homepage hero, Buy It Now, checkout, inventory and current content publication workflows are unchanged. The footer is ported to the approved responsive four-column layout: published shop categories, real help routes, published policies and visible published CMS pages. No demo catalogue records, invented social accounts, business contacts or unpublished content are exposed. The approved bag photography is copied unchanged from `dist/assets/bag.png` to the production public asset folder; the `dist/` reference itself is untouched.

Offline view regression checks cover bilingual rendering, escaping, policy visibility, About publication state, real category routes, asset presence and source wiring. PHP syntax and release integrity must also pass for the updated package; actual database/browser/visual/mobile acceptance is still deferred to the final XAMPP phase. This is not a production release.

## 21 October 2026 — Returns admin work queues (development)

Added server-side filtered/paginated registers for return requests, delivered items, inspected stock and refund proposal/approval. Permissions for finance data and actual-money actions remain unchanged; Owner/staff work queue counters highlight unreviewed requests and approved payouts. Offline PHP lint/selected scripts were exercised; MySQL/browser/finance acceptance still pending. Not a production release.

## 21 October — recorded financial performance and return-adjusted cost coverage (development only)

New read-only Admin Reports screen + CSV show event-dated delivered order value, applied discounts, FIFO COGS, recorded sellable return cost reversals, paid refunds, stock losses, operating expenses and gateway/courier fees, plus an incomplete numerical bridge explicitly marked **NOT profit**. The order drilldown is limited to 100 delivered orders and shows lifetime refund/return amounts. Missing historical costs and incomplete taxes/fulfilment/accruals remain blockers for true net profit/loss; no new database schema, provider transactions, or deployments occurred. Real MySQL/browser acceptance is deferred.

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

## 21 October 2026 development checkpoint — not a production release

- Selected BulkSMSBD OTP transport and encrypted Owner Admin configuration added to existing PHP source (no real send, saved status is not connected/delivered).
- Partial V5 SEO custom 404/status/noindex and route-level aggregated monitor with additive fifteenth schema and authorized review.
- Offline SMS parser checks and 68-file PHP syntax preflight executed; MySQL, cURL/provider, HTTP/browser/security/concurrency gates unrun.
- Read `handoff/STATUS-AND-BACKLOG.md`, `handoff/REQUIREMENT-COVERAGE-2026-10-21.md` and `handoff/TEST-EVIDENCE-2026-10-21.md`. Preserve the existing approved `dist/` reference. No real credentials/deployment.

# AELVO development update — 10 October 2026

This is a local source update for the cPanel delivery path. No Git push, Sites publication or cPanel deployment was performed for this update. The previously published private preview still shows version 3.

## Implemented in the frontend

- Uniform outlined View/Edit/Delete controls, with destructive actions distinguished by colour.
- English/Bengali storefront switch and bilingual product name/description/material/dimensions/care fields, retaining English fallback for missing catalogue translations.
- Deterministic assistant response templates in Bengali, Banglish and English. Latest-message language controls replies. This is not a connected language model.
- Buy It Now on all product cards and product views. Card actions open colour/size selection; the selected variant checks out independently of the existing Bag. OTP/stock checks remain in force. Leaving direct checkout discards that temporary selection.
- Product description/information sections and related products, with current, inactive, archived and out-of-stock products excluded from suggestions.
- Verified delivered-order reviews, optional images, moderation/public replies and removal permission checks. No fabricated reviews or rating counts are seeded.
- Admin welcome popup with image, bilingual text, up to two internal-link buttons, schedule, delay, visit/day/first-visit frequency and draft/publication separation. Checkout/account/payment routes are excluded.
- Homepage image/video banner upload, poster requirement, mute/play/pause and reduced-motion handling. Published hero is preserved when editing a draft.
- Owner-only AI entry screen with an honest locked state until real backend authentication/email/AI is connected. A staff role cannot unlock it and there is no sample email code.

All frontend data and uploaded media are session-memory examples. Only language preference and popup display-frequency markers use browser storage. Real server validation, persistent uploads and database writes remain backend work.

## Backend foundation

The `cpanel/` folder contains Owner AI policy/service PHP source, MySQL tables and an unexecuted policy test. Exact, expiring, session-bound approvals, replay prevention and the financial-action exclusion are represented in server code. Real login/email/business/AI adapters and a safe live-code release runner are not connected. Read `cpanel/README.md` before using this foundation.

## Validation

50 automated JavaScript checks pass, including 138 route/state renders and local asset references. New checks cover isolated Buy It Now, abandoned checkout, verified reviews, related-product exclusions, popup publication/scheduling/frequency, video requirements/draft isolation, language switching and Owner-only boundaries.

Browser visual/native-interaction QA and supported-context WebMCP checks have not run. PHP/MySQL tests have not run because this environment has no PHP runtime or MySQL test database. This ZIP is a development source handoff, not a production-ready cPanel installation.


## Soft sage colour refinement — 10 October 2026
- Default theme is now Sage, with muted green primary actions and a warm off-white storefront.
- Delivery strip, Ask AELVO launcher, language selection, secondary shopping buttons, editorial and footer use pale accent tints rather than saturated fills. Mobile navigation uses deep green.
- Admin theme presets/custom colours remain available; light tint and border colours are derived from the selected accent, with calculated text contrast.
- Product photography is unchanged. Local source only; no deployment.

## Banner spacing and hover feedback — 10 October 2026
- Hero copy and bottom caption now share normal flow with a minimum banner height. Wrapped English/Bengali copy can grow the banner instead of colliding with the CTA.
- Storefront and Admin controls have shared subtle hover lift, shading and shadows; navigation links gain movement or colour feedback. Disabled controls are excluded and reduced-motion preferences suppress movement.
- Hover effects are limited to hover-capable fine pointers. Keyboard focus stays visible. Browser visual QA remains unperformed.

## Main backend phase 1 — 11 October 2026

Implemented PHP 8.2+/PDO MySQL configuration, core schema, token-protected single-Owner setup with a locked database row, real password login, CSRF, strict server sessions, account/IP throttling, live role-permission lookups and a protected dashboard. Added XAMPP/cPanel installation instructions and isolated database regression checks. The frontend remains separate; no catalogue/order persistence or role-management UI yet. PHP/MySQL runtime installation was blocked in this environment, so backend checks remain unexecuted. No deployment or Git push.

## Main project continuation — 11 October 2026

PHP storefront now has durable checkout quotes, delivery fees configured from Admin, SMS verification through a fixed HTTPS Alpha SMS adapter, explicit COD confirmation, transactional reservations and duplicate-submit protection. Secrets remain encrypted; unconfigured SMS cannot verify checkout. Private backend directories have Apache access denial and public defaults to the storefront. PHP runtime, real SMS, database/concurrency and browser tests remain unexecuted. The existing 50 demo JavaScript checks pass. See cpanel/PROGRESS.md for the full unfinished scope; no partial handoff or deployment.

## 21 October — continuing cumulative PHP implementation (not a release)

- Product SEO per-product overrides, social metadata, photo ALT, and a new additive schema; partial Master V5 SEO, not finished.
- Staff multi-line order entry and private printable summary; multi-line purchasing and supplier directory/landed costs, separate verified supplier payout ledger and private purchase record.
- Damaged/quarantined quantity handled independently from on-hand and reservations, with audited reasoned hold/release/writeoff, stock-aware checkout/bag/catalogue/staff validation.
- Schema registry now has 19 additive schemas. PHP 8.4.24 source syntax preflight passed on 76 files. No database, Apache/browser, provider or concurrency acceptance has run; source is not production-ready.

- Hardened bundled SQL command parser so semicolons in comments/quoted values do not break schema statements; checked SQL command boundaries offline.

## 21 October — cumulative taxonomy / brand SEO implementation (development only)

- Additive schema 20 `taxonomy-seo.sql`: seeded legacy fashion root categories, category/subcategory and brand records, product association/backfill. Historical product prices/categories and original `dist/` remain unchanged.
- SEO-authorized Admin category/brand editing supports Bangla/English descriptions, titles, robots, social metadata, canonical fields and safe social photo references. Product editor selects category/subcategory and brand within the existing fashion category family.
- Public `/category/<slug>` and `/brand/<slug>` routes, public product lists, paginated collection pages, links, metadata and eligible sitemap entries. Category/brand slug history uses transactional route ownership and 301 redirects. Hidden content never resolves as a public collection.
- No MySQL migration or browser/Apache acceptance has been run. The schema/URL checks are limited to syntax and offline static policy; use a backed-up isolated development database during the final XAMPP integration stage. Full V5 remains incomplete (CMS SEO, full payments/courier, reports, AI, UI parity, production hardening).

## 21 October — cumulative custom CMS pages + content SEO (development, not a release)

- Additive schema **21** (`cms-pages.sql`) holds independently versioned private drafts and public snapshots. Admin → Banners & pages → Custom CMS pages now allows bilingual plain-text page authoring, optimistic draft saves, reviewed explicit publication, optional footer links, and SEO overrides controlled by the SEO edit permission. Published content does not change until publication, and hidden/unpublished pages cannot be read via public routes.
- Public clean `/pages/<slug>` URLs use the same transactional immutable route registry as products/categories/brands. Publishing a renamed page reserves historical paths and points them directly to the latest public URL. Manual aliases may also point only to live CMS routes.
- Fixed About, Delivery & returns, Privacy and Terms pages now have per-page title, description, social title/description and index/follow controls, stored with the explicitly published draft. Unauthorized content editors cannot alter existing SEO fields.
- The XML sitemap splits static/collection/CMS URLs and products into bounded 1,000-URL documents, excludes hidden and noindex content, and keeps indexing disabled until the Admin opts in for the configured public HTTPS URL.
- Offline `php tests/cms-pages.php`, `php tests/seo-routes.php`, and `php tests/sql-parser.php` are basic safety checks; real MySQL, Apache/browser, concurrent publication, RBAC, and deployment acceptance **have not run**. Full rich CMS/media editor, schema markup, SEO previews/verification/analytics, provider/ERP/AI integration and design parity remain unfinished. No public deployment was performed.

## 21 October — CMS structured content, staff preview and media publication (development only)

- Additive schema **22** (`cms-rich-content.sql`) introduces `aelvo_cms_published_media`, a published-only image allowlist linked to existing media and CMS pages. A failed publish transaction rolls back the media references and route ownership together; editing a private draft does not release its images.
- Custom CMS pages accept at most 24 server-validated structured blocks: paragraphs, secondary headings, bullet lists, quotations, uploaded images with ALT/captions, FAQ question-and-answer accordions and same-site CTA buttons. Bangla fields fall back to English. Arbitrary user HTML, scripts, external buttons and unrecognized media cannot be published.
- Admin gets a progressive block list editor (add, remove, reorder) and private content media library. New `/cms-preview.php?id=<id>` requires an active staff session and content-view permission, is noindex/noarchive and serves the exact saved draft, not unsaved form changes. Admin/preview layout is still PHP scaffolding, not the approved final UI parity.
- Published CMS pages now render their structured content server-side and supply visible FAQ-only JSON-LD with safe JSON encoding. Fixed a storefront wiring defect so the CMS SEO resolver receives its published page snapshot; the default description can use blocks when legacy text is empty.
- Offline PHP lint and CMS/SEO/input/SQL parser checks were run locally; no MySQL migration, permission/browser/concurrency QA, private media HTTP access check or provider test was performed. No claim of production readiness.

### Storefront product gallery and responsive Admin shell (21 October, development)

Implemented server-rendered published-media product gallery with colour filtering, Add-to-Bag confirmation with explicit Bag link, clean search suggestion URLs, and permission-aware real PHP Admin responsive sidebar/workspace. Existing visual preview `dist/` and customer/ERP business actions remain unchanged. PHP syntax and JavaScript parse were checked; browser/database integration and complete approved design parity remain outstanding. This is **not** a production release.

### 21 October — uncertain booking review (development source)

A new additive courier-resolution migration and documentary, independently approved reconciliation workflow were added to the original PHP/MariaDB project. This reconciles an existing portal-confirmed consignment only; no duplicate create_order request, payout, dispatch, refund or stock mutation is authorized. Manual portal verification is an attestation, not live API validation. Full database/provider/browser acceptance is outstanding.

### 2026-10-21 — Courier failure/cancellation exception controls (development)

An additive #32 SQL migration and evidence-only exception workflow were added, including two-person approval, idempotent filing, reviewed delivery-recovery clearance, restricted private document download, fulfillment/COD collection guards and Admin visibility. No Steadfast cancellation endpoint has been implemented or invoked. Approval is not a parcel cancellation, order fulfillment, bank credit, refund, or physical stock receipt. This feature has not been tested in MySQL, a browser, or with a live provider.

### 2026-10-21 — gateway exception investigation (development, not deployable)

Added migration 33, restricted gateway dispute/uncertain-outcome evidence cases, independent staff review and audit, and private documents. Neither submitting nor reviewing a case changes money, order, gateway or inventory states. No live provider, PDO MySQL or browser validation yet. Full settlement matching and failure/retry resolution remain outstanding.

### 2026-10-21 — Grouped SSLCommerz bank payout matching (source-only)
Added provider-validated charge-to-bank-credit allocation ledger, a single-cash-posting batch form, evidence permissions and read-only aggregated reports across old and new postings. Added immutable migration #34. Do not deploy to production: XAMPP/MySQL migration, concurrency, provider evidence, browser and recovery acceptance are unverified. Old single-charge Admin POST is retired; old settlement records remain intact.


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

## 2026-10-22 — tax configuration (development; migration 36)
Tax remains disabled by default, without assuming a statutory rate. Owner can choose a tax-exclusive rate and whether the shipping charge is taxable. Customer checkout and staff manual orders calculate tax after coupon discount, verify the current configuration, save immutable order-time tax facts, and include tax in totals and required advances. Orders/reports/CSV show tax distinctly; the incomplete financial bridge excludes charged tax. **Not tax advice or statutory filing support.** Inclusive pricing, partial-return/refund tax allocations and remittance remain unimplemented and require business/legal decisions. No real MySQL/Apache/payment acceptance occurred.


### 22 October 2026 — Approved Admin navigation parity
- Added permission-aware, mobile Admin liquid tabs: Overview, Orders, Stock, Accounts, More; inaccessible modules are never linked, and the More menu contains only server-authorized pages. Mobile layouts protect the bottom safe area, keyboard focus and reduced-motion preferences.
- Added desktop Admin in-place module finder using server-generated authorized module links; no internal operational data or permission checks are delegated to client JavaScript.
- Added an offline Admin-navigation RBAC regression script. No migration; **MySQL/browser acceptance remains unverified**.

Implementation note: the navigation indicator uses **fixed CSS classes rather than inline style attributes** because the Admin Content-Security-Policy does not permit inline styling. As of this continuation, 168 PHP syntax checks and 40 offline scripts passed; three MySQL-dependent scripts were blocked by missing PDO MySQL. These are not browser or production acceptance results.
