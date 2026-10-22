**22 October catalogue addition:** Schema 39 (`product-details`) adds optional product facts and descriptive variant attributes. Do not edit old migrations or reimport a populated database. It remains source-only, pending the isolated XAMPP upgrade/install test and a verified backup.

**22 October source update:** Schema 37 (`refund-tax-allocation`) is additive. Historical taxable refund requests without allocations must be reconciled before approval/payout; do not retroactively assume zero tax refunded. Execute migration only after verified backup, isolated schema preflight and final XAMPP acceptance.

# Browser release preflight (new source feature — 21 October 2026)

`public/upgrade-review.php` provides an **Owner-only, read-only** no-SSH release audit. It checks the bundled `cpanel/RELEASE-MANIFEST.json` against deployed source files, rejects untracked PHP/SQL/JS/CSS, shows ordered migration history and pending schema checksums, detects exposed private folders, and outputs a proposal fingerprint/optional sanitized JSON report. Neither this page nor the report is proof of a restored backup or successful staging validation. No upgrade action is exposed.

**`RELEASE-MANIFEST.json` must be uploaded with `cpanel/`, including when the larger `AELVO/` directory is not present.** `Schema::migrate()` and `bin/migrate.php` refuse all SQL execution if release integrity fails or the manifest is absent. The top-level `FILE-MANIFEST-SHA256.json` remains an archive reference, not a deployment prerequisite. An attacker with permission to rewrite both files and the manifest can defeat this check; it is **not a signed release**. Do not regenerate the manifest on an already-running server to silence an integrity error—compare against the trusted release package.

Until independently verified full backup/restore, staging and rollback controls have actually passed, upgrades remain intentionally disabled in the browser. The existing CLI migration path is not an authorized production shortcut. Owner-only preflight requires a successfully installed database and working login/session; it cannot repair an interrupted fresh install. Preserve the database and inspect errors rather than deleting config, resetting SQL history or rerunning the installer on populated records.

---

# Current safe installer and migration procedure (development — 21 October 2026)

**Source of truth: `app/Schema.php` currently contains 28 ordered SQL files.** The older numbered import instructions below are historical and **must not be followed** for a new release. The current installer requires PHP 8.2+, PDO MySQL, Sodium, cURL and fileinfo. No complete MySQL/browser/cPanel acceptance has been performed.

**Fresh installation, empty database only:** place the nonpublic `cpanel/` source outside `public_html` and serve only its `public/` directory. Use `config/setup.key` (64 random lowercase hex chars, generated locally or by `bin/prepare-install.php`); visit `public/setup.php` over HTTPS, or loopback XAMPP. Enter existing **empty** dedicated MySQL database details. The installer imports bundled SQL under a MySQL advisory lock, records each version and file SHA-256, then writes `config/config.php` only after schema success. Keep the private installation token for the one-time Owner creation in `public/index.php`. Optional provider credentials remain unset.

**Never point fresh installer at a populated database.** A partially applied MySQL DDL file is not transactional. Its migration entry remains `failed`/`applying`; the runner refuses automatic retries. If first-time setup fails, investigate server logs and start again with a **new empty dedicated database** rather than purging business records or deleting `config.php`. No real customer data may be discarded to recover a development installation.

**Upgrade diagnostic:** an authenticated original Owner may open `public/schema-status.php` for a read-only migration plan. Old installations created before the ledger are reported as *untracked* and are deliberately blocked from automated upgrade. A legacy-to-tracked, backup-aware upgrade workflow is **not finished**. Do not forcibly mark old migrations applied.

**CLI operator migration preview:** `php bin/migrate.php` is **read-only by default**; `--apply` applies missing files only to a fresh or already tracked database. On tracked databases with missing versions, `--apply` also requires `--restore-tested` to record a human operator's attestation of a backup that has ACTUALLY been restored in an isolated environment. That flag does not itself create, verify or restore a backup. Do not use on production until concurrency, rollback/recovery and PHP/MySQL/browser acceptance have been completed. cPanel no-SSH production upgrader is still unfinished.

**Source-only verification:** `php tests/migration-ledger-offline.php` checks manifests and failure cases without connecting to MySQL. It does not prove a real install or upgrade.

---

## Historical development instructions (superseded where inconsistent with the above)

> **Latest source-only accounting continuation (21 October 2026):** `app/Schema.php` now lists **26** additive schemas, ending `inventory-costing.sql` (new). FIFO purchase/delivery/return cost journals require this migration. Do NOT attempt live upgrades before a verified restorable backup. No MySQL integration, browser or production tests have been completed. Earlier schema counts below are historical.

> **Latest development source (21 October 2026):** `app/Schema.php` lists **23** additive schemas, ending in `support-inbox`. Earlier schema counts in historical instructions below are superseded. No database migration, production/browser testing, or live provider checks have been executed for these changes. Back up existing data before any additive migration.

# AELVO backend — internal development setup

Requirements: PHP 8.2+, PDO MySQL, MySQL/MariaDB with InnoDB, Apache for XAMPP/cPanel. This source includes durable catalogue, staff administration, inventory, accounts and initial COD checkout. Full approved design and business-feature parity remain incomplete; see PROGRESS.md. Do not use for real customers yet.

## XAMPP

1. Start Apache and MySQL. Copy the ZIP's `AELVO/cpanel` directory to `C:\xampp\htdocs\aelvo-backend`.
2. In phpMyAdmin create an empty database named `aelvo` using utf8mb4. Import `database/core.sql`, `database/commerce.sql`, `database/finance.sql`, `database/owner-ai.sql`, then `database/checkout.sql`, `database/customers.sql` and `database/content.sql`, `database/coupons.sql` and `database/tracking.sql`, `database/payments.sql`, `database/returns.sql`, `database/refund-review.sql`, and `database/customer-tools.sql`, `database/customer-engagement.sql`, `database/seo-errors.sql` and `database/seo-routes.sql`, in that order. `app/Schema.php` is the authoritative ordered list; CLI/browser setup imports it automatically.
3. Copy `config/config.example.php` to `config/config.php`. Enter your local database credentials. Set `secure_cookies` to `false` ONLY for this local HTTP setup.
4. In a Windows terminal run `C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32));"`. Put the generated 64-character token into `install_token` in your config. Generate a second independent 64-hex value for `encryption_key`. Never share the config file.
5. Open `http://localhost/aelvo-backend/public/index.php`. Supply that token, your name/email and a new password of at least 12 characters. There is no default login.
6. Sign in with the account you just created. The setup is now locked by the stored Owner ID. Email is not yet verified by this step; Owner AI stays disabled.
7. After installation replace `install_token` with an empty string. Keep database credentials unchanged.

Optional CLI schema setup, instead of importing via phpMyAdmin:

```
C:\xampp\php\php.exe C:\xampp\htdocs\aelvo-backend\bin\migrate.php
```

The existing frontend still runs separately at `http://localhost/AELVO/dist/`. Its Admin demo is not secured by this backend and must not be published as a real administration portal.

## cPanel staging

- Create a dedicated MySQL database and user; cPanel normally adds your account prefix to the database/user names. Use the full names in config.
- Upload the complete backend outside `public_html`, e.g. `/home/ACCOUNT/aelvo-backend`.
- Configure a staging subdomain's document root as `/home/ACCOUNT/aelvo-backend/public`. Only `public/` should be web-accessible. If your plan cannot set this document root, stop and adapt the entry-point path for that host rather than exposing the full source directory.
- Select PHP 8.2+ and enable PDO MySQL. Enable HTTPS, keep `secure_cookies=true`, and configure PHP session storage to a private writable directory.
- Import all sixteen SQL files through phpMyAdmin. Fill the private config and generate a random installation token, then follow the Owner setup steps above over HTTPS.
- Remove the token after setup. Keep config readable only by the account/PHP process. Never upload backups, tests or the source ZIP into the public directory.
- Do not deploy the in-memory frontend Admin as a production backend.

## Verification before the next release

From the backend directory, lint all PHP files (PowerShell):

```
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { & C:\xampp\php\php.exe -l $_.FullName }
C:\xampp\php\php.exe tests\policy.php
C:\xampp\php\php.exe tests\database.php
```

`tests/database.php` requires an isolated empty development database whose name ends with `_test`. Configure that database and import all sixteen SQL files first. The checks run inside a transaction and roll back their fixtures; auto-increment counters may advance. Never point the test at live data.

Manual checks:

1. Wrong/missing installation token cannot create an Owner; the valid token creates one account. A second setup request cannot replace the Owner.
2. Wrong password gives a generic error. Repeated account failures block further attempts for the 15-minute window. Success opens the dashboard; a fresh private browser remains signed out.
3. An invalid CSRF token rejects POST actions. Logout requires POST and clears authentication.
4. Disabling a staff account or removing a permission in the database takes effect on the next request. A role name alone does not grant Owner identity.
5. Verify cookie flags on HTTPS and session expiry after 30 minutes idle / 8 hours total.
6. Direct URLs for `config/`, `app/` and `database/` must not expose content.

## Current test status

PHP and MySQL were unavailable in the build environment. Package installation was blocked by the environment. PHP lint, database tests, HTTP login tests and browser checks are not yet run. Supplied tests and manual checks are release gates, not claimed passes.

## Current storefront and checkout

Open `public/store.php` for the PHP storefront. It reads the same products and inventory as Admin. This is distinct from the old `dist/` demo.

In Admin Settings, save actual Dhaka/outside delivery fees. The same Delivery & COD form now supports **optional order-value shipping tiers** (ascending rows: minimum order BDT, Dhaka fee BDT, outside fee BDT), **free shipping from a configured net merchandise threshold**, and an **optional higher-order advance override**. Empty fields disable the additional policies; old installations retain their existing Dhaka/outside flat-rate and basic advance rules. Coupons are calculated on merchandise first, then the discounted merchandise amount is used for shipping eligibility. Checkout locks and recalculates the shipping fee, coupon and required advance before final order placement; changed merchant policy invalidates an old quote. No district/weight/product-specific delivery policy, tax policy or automated customer-risk rule is presumed by this extension. Staff-created manual orders still use an explicitly entered shipping fee and require separate workflow review.

In Admin Settings, save actual Dhaka/outside delivery fees. COD stays closed by default. Verified signed-in customers using their current account number can confirm without another OTP. Guests and alternative contact numbers require checkout-specific OTP. Signup and primary-phone changes require OTP. For OTP use Admin → Settings → BulkSMSBD, with your approved Sender ID and API key. The field is encrypted and not redisplayed. Blank key keeps a previously valid stored key. PHP Sodium and cURL are required. Default SMS is Bangla for masking compatibility; confirm with the vendor before selecting English for a masking route. API contract: https://www.bulksmsbd.com/bulksms-api-bangladesh.php . Test real delivery on staging before opening orders. There is no test code or verification bypass in the storefront.

`tests/checkout.php` requires a dedicated empty `_test` database. It injects a fake SMS sender only into the test and performs no external calls. Run this separately from `tests/database.php`. A production deployment additionally needs concurrency tests and the remaining release gates in PROGRESS.md.

## Browser installation (implemented, runtime verification pending)

On an empty installation, run `php bin/prepare-install.php` once. Keep the printed key private. If your cPanel has no terminal, generate a random 64-hex token locally using PHP and upload it as `config/setup.key` outside the public root. Open `public/setup.php` and enter that key plus an existing empty MySQL database's credentials. HTTPS is required except for loopback XAMPP. The installer checks required extensions, refuses a nonempty database, generates the encryption key and writes the private configuration once. It then imports all sixteen schemas. Use the same installation key for the original Owner account in `index.php`.

An existing config locks the installer. If schema import fails after config creation, inspect the server error log and recover with `php bin/migrate.php`; never remove config to install over real data. Back up private configuration and the encryption key. Remove the installation token from config after Owner setup.

## Customer, content and SEO interfaces

- Customer account: `store.php?page=account`. Signup is inactive until the supported SMS adapter is configured. No demo OTP or password exists. Phone/password sign-in, verified primary-phone change, account-only order history and text reviews are implemented. OTP password recovery, saved addresses and guest tracking now have implementation code; review-photo uploads remain open. All new PHP flows still require runtime verification.
- Orders link to an account only when that account authenticated the checkout. Matching phone numbers never attach prior guest orders or another customer's history.
- Admin → Reviews approves/hides reviews and adds a separate store reply. The customer's original text is not editable by staff.
- Admin → Banners & pages saves drafts, uploads poster/image/video assets and publishes an explicitly reviewed revision. Unpublished media is accessible only to staff with content-view access. Schedule dates are UTC. Popup display is once per published revision per browser session.
- Admin → SEO configures the final HTTPS public-directory URL, default title/description and indexing. Indexing defaults off. Apache rewrite serves `sitemap.xml` and `robots.txt`; `sitemap.php` and `robots.php` are their underlying handlers. Per-entity overrides and the remaining advanced SEO modules are not implemented yet.
- Admin permissions now include reviews, content and SEO.

## One command for PHP validation

Use an **isolated empty database with a name ending `_test`**, import all sixteen schemas, configure it privately, then run `php bin/check.php`. It lints PHP and invokes policy, database, checkout and customer/content suites. Failure/skip conditions return a nonzero exit status. The suites do not call real SMS providers. Never point the tests at production or populated data. Restore development configuration afterwards.

This command has **not run in the current build environment**. PHP/MySQL, HTTP/browser and concurrency verification remain required. The older JavaScript suite validates only `dist/` preview behavior.


## Coupons and order tracking (12 October implementation)

The schema list is centralized in `app/Schema.php`; use `bin/migrate.php` on existing development databases to add the coupon and tracking tables. Browser setup uses the same list. No production migration has been executed.

Admin → Coupons supports fixed BDT or whole-percent discounts, a minimum product subtotal, optional discount cap, global usage cap, UTC start/end and pause state. Amounts are computed server-side. A quote does not consume usage. Placement locks/rechecks the coupon and records an immutable discount snapshot in the same transaction as the order. A changed coupon requires a fresh review, and failure leaves the Bag and entered checkout details intact. Cancelled unpaid orders release their use once; delivered orders retain it. First-order, per-customer and category/product-specific promotions remain pending.

`store.php?page=tracking` allows guests to verify their order contact number against an exact reference. Successful access expires after 15 minutes and shows status/payment status/total plus actual recorded order transitions. It does not disclose address, customer name, phone, other orders or create an account link. Courier shipment events are not fabricated. This uses the configured SMS adapter and has no demo OTP.

The user requested testing after implementation is finished. No tests were executed in this continuation; all new code still requires the final PHP/MySQL and browser validation stage.

## 13 October handoff additions (not runtime tested)

Apply migrations through `app/Schema.php`; do not run only the older five/seven/nine-file subset. Current ordered schemas: core, commerce, finance, owner-ai, checkout, customers, content, coupons, tracking, payments, returns, refund-review, customer-tools.

- Returns: Admin → Returns & refunds. Use the delivered item references, record physically received variant/quantity and inspection, then post accepted sellable stock separately. Wrong-variant units remain quarantined. Customer request/evidence queue and failed-delivery returns are not yet implemented.
- Refunds: propose exact amount/account/method/destination → human review → verified manual payout evidence → record once. The app does not send the refund. Pending/approved amounts are committed; rejected requests release that commitment. Historical direct records remain visible.
- Customer recovery: Account → Forgot password → SMS OTP. A completed password reset or primary-phone change increments the customer session version and invalidates old customer sessions. No staff/Owner recovery is added.
- Saved addresses: Account → Saved addresses. Customer-only ownership, up to ten entries, optimistic editing; using an address fills checkout details but does not verify the phone or mutate old orders.
- Missing real SMS configuration still blocks signup/recovery/guest verification. The implemented adapter is sms.net.bd, whereas the selected provider is BulkSMSBD. Resolve this mismatch; do not mislabel the adapter or invent endpoints.

Read `../handoff/STATUS-AND-BACKLOG.md` for current truth; older dated notes are historical. This is a transferable source snapshot, not a complete storefront release.

## 14 October continuation (implementation only)

The schema list now contains **fourteen** files. Import `database/customer-engagement.sql` immediately after `database/customer-tools.sql`, or use the centralized `app/Schema.php` sequence. This supersedes older thirteen-file counts above. This is an additive development upgrade; take a backup before any schema change. No migration was executed here.

Customer accounts now have persistent wishlist/saved bag, name/password editing and delivered-item return requests. Admin → Returns reviews the request and supplies instructions; staff then records inspection using the accepted request ID. A return request is not a receipt or refund. Review-photo uploads appear publicly only with an approved review; customer return evidence stays private. Both upload categories need writable private `storage/` directories. Configure PHP `upload_max_filesize` at least 5M, `post_max_size` at least 20M and `max_file_uploads` at least 3 for the review form, while keeping web-server request limits appropriate.

Final customer concurrency, ownership, upload and design checks are documented in `../handoff/ACCEPTANCE-CHECKLIST.md`. Do not enable production based solely on source presence.

## 21 October 2026 additive development update (UNTESTED migrations)

`app/Schema.php` now contains **15** ordered schema files, ending `customer-engagement`, `seo-errors`. For a fresh empty database use centralized setup; for an existing development database take a restorable backup first, then apply the *new* `database/seo-errors.sql` after the prior fourteen files. Never delete or recreate an existing database to apply an additive change. Check the actual target installation's schema history before upgrading; the versioned/recoverable update runner is not finished.

The current PHP Admin form stores selected BulkSMSBD credentials encrypted outside `public/`. Stored does **not** mean verified or connected. SMS requires an approved sender/route and explicit live acceptance/testing on staging before opening production OTP signups/guest checkout. Legacy `smsnetbd` credentials are not used by current OTP routes. The provider's code `202` signifies *submitted*, not delivered, and only OTP entry grants phone verification. Errors fail closed.

`database/seo-errors.sql` adds grouped route-level 404 recording and versioned Owner/SEO-edit review. A new PHP 404 screen handles application routes and missing products/info pages; unknown Apache/static paths require separate host-specific ErrorDocument verification. The SEO system remains incomplete and should stay `noindex` for staging.

Full suite is deferred to final implementation acceptance by the user's request. Actual limited syntax/offline-parser checks and missing runtime gates are recorded in `PROGRESS.md`.

## 21 October follow-up — clean product routes and SEO URLs (schema 16)

This section supersedes prior schema counts. Current ordered `Schema::FILES` contains **16** files and ends with `seo-errors`, `seo-routes`. If applying to an existing development database, take a verified restorable backup, then run the **additive** `database/seo-routes.sql` (only if not previously applied). Do not reset or drop existing data. Fresh setup imports all files in `Schema::FILES` order. The importer now checks that every product has exactly its current route; any conflict requires a safe manual review, not blind overwrite.

Enable Apache `mod_rewrite` and `.htaccess` overrides for the document root. Active product URL is `/product/<slug>` without trailing slash; the old `store.php?page=product&id=<id>` redirects 301 to that product, and prior slugs redirect directly to the latest URL. Routes and redirects persist in `aelvo_public_routes`. Slug changes and route updates are transactional, with audit entries. Manual redirects from Admin → SEO only accept local paths targeting a currently published canonical product route. No external URL, query propagation, or wildcard rules are supported. A trailing slash is normalized by 301 on GET/HEAD.

The homepage canonical and sitemap entry now use the configured site root. Unfiltered shop pagination uses page-specific canonical URLs; ordinary filters/sort/search use `noindex,follow` with no false consolidated canonical. Out-of-range shop pagination and unknown content return 404. This is an *interim* policy until clean category/brand/CMS routes and full V5 per-entity SEO are implemented.

Offline check: `php tests/seo-routes.php` tests local path parsing and URL safety, without a database. Final validation must exercise fresh and upgraded MySQL schemas, simultaneous slug updates, route collisions/history, redirect loops/disabled targets, Apache rewrites, headers and sitemap inclusion via browser/HTTP. Those acceptance gates remain unexecuted. No production launch is authorized.

## Development continuation — schema 17–19 (not a completed upgrade/release)

This section supersedes the historical schema counts above. `app/Schema.php` currently enumerates **19** schema files. The new additive files after `seo-routes.sql` are `seo-entities.sql`, `supplier-operations.sql`, and `inventory-quarantine.sql`, in that order. Both an existing database and the production launch require a **verified restorable private backup**, the eventual versioned upgrade runner, and isolated MySQL/XAMPP migration checks before these changes are applied. Do **not** reset the database or run a destructive schema import as a shortcut. An installation that predates schema 16 must apply every missing schema in the `Schema::FILES` order.

`seo-entities.sql` stores product SEO overrides and photo ALT text. `supplier-operations.sql` introduces an optional supplier-directory mapping for newly recorded purchases, landed-cost snapshots and an independent supplier-payment ledger. Existing text-only supplier records remain unmapped and are **not** retroactively assigned or marked paid. `inventory-quarantine.sql` keeps sellable units distinct from on-hand, reserved and quarantined stock, with reasoned hold, release and write-off events. Historic inventory is not rewritten; no opening quarantine balance is invented.

Admin → Purchases: create suppliers, select one for a draft, provide one variant/qty/unit cost BDT per line, add real shipping/import/other costs and discounts, and attach a private document. Draft creation never posts stock or money. Receiving posts each item once. Verified supplier payment recording requires an authorized finance-and-purchasing editor, private receipt, request key, actual source account, amount and acknowledgement. It **debits the recorded ledger** but does **not** transfer money. Do not record a payout until the actual transfer is independently confirmed. Print the private purchase summary from Purchases for line/landed-cost breakdown. Costs are historical line-level snapshots only; full cost-layer depletion, COGS and profit reports are still pending.

Admin → Inventory: hold unreserved sellable stock, release inspected quarantine units or write them off physically, with a required reason and append-only event. Server-side online checkout, manual orders, saved bag and public availability subtract quarantined quantity. Never modify the quarantine tables directly to "fix" a discrepancy: use the audited disposition form after investigation. Full damaged-return and supplier-return reconciliation remains future work.

**Pending final XAMPP acceptance:** fresh/upgrade imports for all 19 schemas, owner/staff RBAC/CSRF and HTTP screen checks, duplicate supplier-payment and quarantine replay, concurrent reservation/quarantine/purchase actions, cost allocation invariants and price boundaries, upload privacy, historical legacy purchase compatibility, multi-line purchase receiving only once, reporting consistency and rollback/restore. These have **not** run in the current environment. Real provider calls and production deployment are not authorized.

## Continuation: taxonomy/brand schema 20 (not applied or production accepted)

`app/Schema.php` is now the source of truth for the **20** ordered schemas. The latest file is `database/taxonomy-seo.sql`, which adds reusable category/subcategory, brand and product association tables, and reserves matching public SEO routes. An existing backed-up database must be migrated through the eventual versioned/restore-aware updater. Do **not** rerun destructive imports or erase customer/order data. No migration has been executed during this source-only increment.

After the final implementation phase, acceptance must include: existing-product category/brand backfill, editor permission enforcement, hidden vs active collection links, subcategory assignment and family constraints, concurrent slug ownership and 301 history, canonical/indexable sitemap correctness, collection pagination, SEO social-photo permissions, installer/upgrade/rollback and mobile navigation. Product/category/brand search appearance needs HTTP/browser checks against the actual XAMPP or cPanel rewrite rules.

## Additive source update — schema 21: custom CMS pages (21 October 2026)

`app/Schema.php` now includes **21** ordered SQL files ending in `cms-pages.sql`. It creates `aelvo_cms_pages` without rewriting fixed `aelvo_content`, products, orders or published URLs. **Do not rebuild a populated database or overwrite its data.** For an existing development installation, preserve the private config/encryption key and obtain a verified restorable backup before applying missing additive schema files in the declared order. The recoverable/versioned production upgrader and XAMPP migration acceptance remain outstanding.

Under Admin → Banners & pages → Custom CMS pages, save a private draft and explicitly publish its reviewed version. Published paths use `/pages/<slug>` and historical paths are reserved. A page cannot be seen publicly before explicit publication, and making it hidden removes it from footer, public navigation and sitemap without erasing its audit/route history. Staff with Content edit but not SEO edit cannot change page SEO settings. The current editor renders escaped plain text (not arbitrary HTML); the approved rich visual editor and broader CMS scope remain pending.

`php tests/cms-pages.php` checks input/route/Unicode summary rules offline; it does not require PDO MySQL and does not demonstrate actual publish/redirect/database behavior. Final XAMPP checks must cover fresh/upgrade schema 21, route collisions/old-slug 301, concurrent drafts/publish, staff permissions, bilingual pages, privacy of hidden drafts, page-specific meta/robots, footer, sitemap sections, and browser appearance. No MySQL or browser pass is claimed here.

## Additive source update — schema 22: custom CMS rich content (21 October 2026)

`app/Schema.php` is authoritative: it lists 22 SQL files, last `database/cms-rich-content.sql`. The new table `aelvo_cms_published_media` references existing `aelvo_media` and `aelvo_cms_pages` rows; do not delete/recreate existing tables or reset the database. The deployment/upgrade runner is not yet production-qualified. Preserve a restorable backup and the encryption key before any eventual migration. No SQL was executed on the user's MySQL/XAMPP in this step.

The private `/cms-preview.php?id=<id>` is restricted to signed-in content-view staff, with `noindex`, no-cache and CSP. `cms-blocks.js` only manages editing UI; authoritative block validation, path validation and media publication checks run in PHP. Uploaded content images are staff-private until an enabled CMS page revision is explicitly published, and old published media references are removed in that same transaction. Confirm this on the final isolated `_test` database with real HTTP session tests, privilege checks, rollbacks, missing image IDs, image MIME mismatches and media reuse. Run `php tests/cms-blocks.php` for offline typed-content checks, `php tests/cms-pages.php` for slug checks, and complete the final acceptance suite and responsive screenshot comparison before production.

### Source upgrade note — 21 Oct 2026, website chat

The ordered SQL list in `app/Schema.php` is authoritative; it now also includes additive `database/customer-chat.sql` (29th schema, after `staff-sessions`). Use the existing tracked migration runner/preflight after a verified backup and a restore rehearsal. Never simply re-import all SQL files into a populated database. Until provider installation and acceptance, Customer AI should be disabled; chat falls back to human support. Original Owner AI is still locked. Shared hosting requires PHP cURL and Sodium for optional customer AI; no background worker or Node server is required.


## Owner-only email verification / Owner AI review (development, NOT production unlocked)

The only existing Owner is the immutable `aelvo_installation.owner_id`. Its `aelvo_users.email_verified_at` starts NULL and is not trusted just because the installer stored an email. Owner Settings now has encrypted SMTP credentials (public DNS hostname, TLS 465 or STARTTLS 587, sender/from, username and password); **saving SMTP is not a connection test**. The Owner may use `?page=owner-ai` to request a short-lived real email code with attempt and send limits, then explicitly verify the email. After email ownership verification, the Owner may request an independent Owner AI email code and must re-enter the real Owner password. A session-bound short-lived grant allows drafting/reviewing limited product-text proposals and explicitly approving their exact SHA-256 payload for audit. This review **does not change a product**, send money, or call an AI model. No AI proposal execution endpoint or release button is exposed. Payments, refunds, stock, staff access, account balances, gateway configuration, arbitrary code/SQL/shell, and all live code releases remain permanently blocked from model-directed tools.

`owner-email-verification.sql` is additive schema 30. Existing schema/manifest verification remains mandatory; no live migration was run here. An improperly configured SMTP host, missing TLS, missing private encryption key, or absent email verification locks Owner AI. Runtime acceptance still requires isolated `_test` database, real SMTP/OTP delivery, transaction/concurrency checks, session revocation and browser authorization tests. Do not claim this is a working production AI assistant.

## Courier uncertain-booking resolution (source implementation, not runtime accepted)

Schema 31 `courier-resolution.sql` adds documentary case history for uncertain Steadfast booking attempts. Existing courier bookings and accounting tables are not rewritten. Only authorized staff with `courier:edit` can submit a private PDF/image from the merchant portal; a distinct staff/Owner with `courier:approve` must independently review the same invoice, booked COD, recipient/contact and evidence. Approval of a **verified existing consignment** attaches its ID to the original shipment, and normal status sync must still independently query Steadfast. An absent/unmatched consignment, rejection or interrupted booking **never** authorizes a duplicate booking, cancellation, payment posting, order delivery, refund or inventory restock. The booking remains locked. Approved/rejected reviews retain their evidence and audit timeline; the reviewer cannot be the proposer. A stuck `Prepared` row can enter this review only 30 minutes after its last update; this is not proof the booking failed.

The `courier:approve` permission is initially Owner-only unless explicitly granted to a different authorized staff user. A single user cannot complete both steps. This intentional safety requirement can leave an uncertain booking pending until a second reviewer is available. Portal checks are human attestations, NOT cryptographic API verification of invoice/recipient ownership. The provider's customer booking/cancellation/refund APIs and manual recovery decisions need separate acceptance. Do not use these forms for real production bookings before full MySQL, provider, authorization, concurrency and browser testing.

### Courier cancellation/failure exception migration (schema 32)

`database/courier-exceptions.sql` is an **additive** migration. The courier exception register and its separate recovery-clearance table record private merchant evidence with independent two-person review. A cancelled provider status must be **final** (`cancelled`) before a final cancellation report is accepted; a cancellation request or approval-pending status is never proof of a completed cancellation. A failed-delivery/return-to-sender incident blocks dispatch/delivery and fresh courier COD collection while pending or approved. A later confirmed provider `delivered` status can clear a reviewed failed/return report only with **new uploaded evidence** and second-staff approval. Confirmed provider cancellations cannot be cleared using delivery recovery. The system never invokes a courier cancellation API, restocks goods, changes orders, sends refunds or assumes remittance from an exception or clearance. Separate authorization and physical receipt checks are still required for these workflows. Do not deploy without the full MySQL, permission/concurrency and browser acceptance tests.

### Gateway payment investigation migration (schema 33)

The additive `database/gateway-exceptions.sql` creates evidence-only gateway investigation cases. Run **only through the guarded, versioned migration workflow** after independently verified backup/restore testing on the final XAMPP/cPanel environment. Never manually overwrite pre-existing schema files or database records. Owner may grant the separate `payments:approve` permission to reviewers who also have `finance:view`. Staff proposing cases need `payments:edit` and `finance:edit`. Document review intentionally cannot clear gateway holds or create a paid/failed/cancelled transaction; only independently validated provider-specific reconciliation can do so. No payment or bank account should be assumed credited from a case status.

### Gateway grouped bank payout matching (schema 34; development only)

`gateway-payout-batches.sql` adds a distinct bank payout register and item allocations. Apply it **only through the immutable versioned migration process**, after a verified backup/restore on isolated testing. No live payout/API operation is performed by the Admin form. A staff user with both `payments:edit` and `finance:edit` must independently verify the SSLCommerz provider statement and the bank/wallet credit, upload a private document, supply the *exact bank statement reference*, and match each verified live charge as `transaction ID | credited BDT | withheld fee BDT`. The credit total and fee total must both match the bank statement; a batch records **one bank ledger entry**, not one per order. Every allocation is bound to a provider-validated receipt. Previously recorded single-charge settlements count toward the same receivable, and new bank references cannot be reused in the same account. The previous one-charge Admin POST route is retired; existing records are preserved for reporting and audit.

**Not verified yet:** MySQL DDL/transaction locking, concurrent batch/single writes against a legacy installation, XAMPP browser input, statement evidence access, payout-file import, live SSLCommerz provider statements, partial/negative adjustments, dispute/chargeback settlement and cPanel upgrade/restore. The source-only PHP/offline tests do **not** establish financial correctness or production readiness. For complex payouts/adjustments that cannot be represented exactly by the matched lines, leave the funds outstanding and investigate; never invent credit or fees.

### Tax policy in later development releases (schema #36)
`order-tax.sql` adds `aelvo_orders.tax_minor` (default 0) and `aelvo_order_tax_snapshots`. Run only after a verified restorable backup, through guarded migration preflight after every previous schema in `Schema::FILES`. Owner must consult applicable tax advice and set any explicit exclusive rate and shipping tax setting; absence of tax configuration means tax OFF (no assumed VAT). Checkout quote becomes invalid if settings change; existing placed orders retain their own tax snapshot. No tax-inclusive pricing, exemptions, VAT invoices, or partial-refund tax allocations are claimed complete. Final XAMPP/MySQL and concurrency acceptance are mandatory before production.
