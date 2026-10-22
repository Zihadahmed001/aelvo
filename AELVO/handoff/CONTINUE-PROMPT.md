Continue my existing AELVO Fashion e-commerce + Mini ERP project from the attached `AELVO-Current-Source-and-Handoff-2026-10-13.zip`. This is a continuation of an existing codebase, NOT a request for a new demo, redesign, generic plan or replacement project. Read the archive and edit its actual files. Do not merely tell me how to build it.

## 1. Immediate task and handoff context

I am transferring this work to another account. You do not have access to my previous chat or workspace. Treat the attached source and its handoff documents as the context. Extract the ZIP, locate `AELVO/`, read the files below, map the remaining requirements, and continue implementation from the current state. Preserve existing work and keep durable checkpoints.

Read in this order:
1. `START-HERE-BN.md`
2. `handoff/STATUS-AND-BACKLOG.md`
3. `handoff/DESIGN-LOCK.md`
4. `handoff/MASTER-SPEC-V5.txt` IN FULL — the complete scope including full SEO.
5. `handoff/APPROVED-DESIGN-AND-RULES.md` IN FULL — detailed approved business/visual behavior, interpreting later overrides correctly.
6. `INTEGRATIONS.md`, `cpanel/INSTALL.md`, `cpanel/README.md` and the latest dated section of `cpanel/PROGRESS.md`.
7. `handoff/ACCEPTANCE-CHECKLIST.md` and the actual relevant code before changing it.

`handoff/ORIGINAL-SPEC.txt` is supplied as earlier context; it does not replace or reduce V5. Older dates/monochrome descriptions/progress lists are historical. My latest instructions and DESIGN-LOCK govern superseded colours/navigation/workflow choices. When a real unresolved business choice matters, ask a focused question; do not invent a policy or remove the feature.

The previous assistant estimated implementation at approximately 45–55%, not a measured or tested completion percentage. Independently audit code coverage. Do not inherit that estimate as proof, mark every existing file complete, or claim production readiness. The last 50 passing checks were an older JavaScript demo suite; no new PHP/MySQL/browser/concurrency verification was completed in this snapshot.

## 2. Non-negotiable stack and deployment target

- PHP 8.2+, MySQL/MariaDB, PDO, HTML/CSS/JavaScript, modular MVC direction, Bootstrap 5 where compatible with the existing approved styling.
- XAMPP locally, shared cPanel hosting via file upload as the production target. I have Apache and MySQL working in XAMPP.
- Do not migrate to React/Next.js, Supabase, Firebase, Node backend, Sites hosting or another database/framework without my explicit request. Node may be used for the existing frontend checks/build tooling, not as a new server dependency.
- Provide a self-contained cPanel upload package and secure browser installer, with PHP source, schema/migrations, assets, configuration examples and clear setup/upgrade instructions. No Git/SSH should be required from me for normal installation.
- No live deployment, paid provider action or real message/payment is authorized merely by this prompt. Build configuration/adapters and document callback setup. Secrets are entered later through Admin and stay on the server; never ask me to paste passwords/API keys into chat.

## 3. Preserve the SAME approved design

`dist/` is the feature-rich interactive design reference. `cpanel/` contains the current unfinished PHP implementation. The simple PHP scaffolding is not the final design. Port the approved storefront, customer and admin screens to real backend services while preserving layout, typography, components, spacing, assets, responsive behavior and motion. Do not rebuild from a blank starter, substitute a generic admin template, or ask for new mockup approvals.

Preserve the latest soft sage/mint surfaces, warm off-white background and muted green accents. Do not revert to the earlier all-black/white appearance or harsh dark green strip/AI button. Keep hero text/CTA/tagline in nonoverlapping flow, including Bangla and narrow screens. Keep subtle hover movement, shadows/colour feedback, keyboard focus and reduced-motion support across the whole site.

Preserve in-place header search, colour-specific images/galleries, product information, related products, verified reviews, Buy It Now and independent regular bag. Mobile bottom navigation is Home, Shop, Bag (centre), Wishlist, Account with the existing liquid indicator. Admin buttons use the existing uniform system. Hero image/video and welcome popup remain Admin-controlled via draft and explicit publication. Use `DESIGN-LOCK.md` and the actual modules/assets as the visual authority.

Do not use the demo data, hardcoded OTP, simulated payments or in-memory Admin permissions in production. Keep `dist/` as a reference; production writes must persist in MySQL and reauthorize on the server.

## 4. Mandatory business/security rules

- Fashion only: men's/women's shoes, socks, belts, wallets, bags. Bangladesh/BDT defaults and Bangla/English UI. Customer AI responds in Bengali, Banglish or English matching the latest customer message.
- Signup verifies the mobile number before creating/activating an account. Signed-in checkout using that account's current verified phone does not require a fresh OTP. A different delivery/contact phone needs checkout-bound OTP. Guest checkout always requires OTP, even if the phone belongs to an existing account.
- A phone match never logs someone in or attaches guest order history to an account. An alternate checkout phone does not silently change the account phone. Saved addresses do not verify numbers. Recovery requires real OTP, attempt/rate/expiry limits and revocation of previous sessions.
- Validate active variants, current price, stock, discounts, delivery/tax and totals on the server. Order placement, inventory reservation, coupon usage and ledger effects must be transactional and idempotent under retries/concurrency.
- Separate on-hand, reserved, available and damaged/quarantined stock. Receive/restock/return/cancel/deliver each create the correct movement once. No direct stock overwrite or double-restock. Audit corrections instead of deleting posted history.
- Payment screenshots/references are claims, not verified funds. Verified advance reduces remaining COD. Courier booking, dispatch, delivery, collection and remittance are different events. No made-up tracking IDs, GPS, payout or payment success.
- Return request, physical receipt/inspection, sellable restocking, refund proposal/approval and actual payout are separate. Cap cumulative paid and committed refunds. Require evidence and exact approved destination/source/amount for recording a manual payout. Approval does not send money or debit a ledger. No automatic stock restock from a courier status alone.
- Account transfers conserve total funds; contributions are not sales; unremitted courier COD is not bank cash; reports need actual cost/expense/return/fee data. Never fabricate profit using gross revenue.
- Enforce RBAC, ownership, CSRF and upload permissions on every route; escaping/prepared statements are required. Preserve protected Owner identity and append-only audit/financial history. Secrets/config/backups/private uploads remain outside public access.
- Published content must stay unchanged while a new draft is edited. Private drafts/previews require real authorization; noindex alone is not privacy.
- Customer AI may guide and prepare a bag/draft, but cannot bypass customer confirmation, OTP, permissions or verified payment. Human takeover pauses automated replies. Website/WhatsApp/Messenger access needs both support and channel permissions.
- Owner AI is for the ORIGINAL OWNER only, with real verified-email session, expiring exact-change approval and history. Permanent prohibition on AI executing/approving payments, refunds, transfers, balance corrections or gateway configuration. Never add unrestricted SQL/shell/settings tools to bypass this.
- Live-code AI changes require a verified restorable backup, isolated staging checks, exact artifact digest approval, fixed safe release runner, health checks and rollback. Keep Owner AI/code release locked until this actually exists and is validated; do not simulate unlock/test/backup success.

## 5. What already exists / what you must finish

Use STATUS-AND-BACKLOG for the file-by-file truth. Current code includes foundational auth/RBAC, catalogue/variants/uploads, stock/orders, customer/checkout OTP, tracking, reviews, coupons, content publication, basic SEO/installer, accounting/purchases, manual payment proof/receipt and the latest return/refund/recovery/address code. Those are not all complete or runtime-tested.

Finish all remaining master requirements, including:
- Full approved frontend/admin parity and durable interactions; catalogue taxonomy/brands/attributes/media and full product/variant fields.
- Full inventory/purchasing/supplier/landed-cost workflows, multi-item POS/manual sales and receipt/invoice generation; all staff/customer operations and permissions.
- Customer profile/security/wishlist, return request/evidence and review photos, help/contact/FAQ/bug report, richer CMS and general settings.
- Full coupon/promotions, delivery/tax/COD advance/risk rules; payment gateway and signed/idempotent webhook handling; lifecycle/refund/cancellation reconciliation.
- Real Steadfast booking/status/cancel/timeout reconciliation, COD settlement/fees/partial remittances/shortages and reports.
- Actual accounting, COGS/profit/loss, reporting, filters/pagination and export formats.
- ALL Master V5 SEO modules: per-entity overrides, clean unique slugs, atomic 301 route ownership, safe redirects, canonical/indexable sitemap splitting, robots/staging, truthful JSON-LD, verification, consent-ready analytics/marketing, 404 monitoring and admin warnings.
- Real provider-specific Admin settings, SMTP/notifications/recovery, selected SMS adapter and social OAuth. BulkSMSBD is selected; current `Sms.php` implements sms.net.bd instead. Resolve explicitly using current official documentation, never invent endpoints or mark a saved key connected. Missing optional keys must not stop installation; missing mandatory verification setup must fail closed.
- Real customer model/support channel integration and narrowly authorized Owner AI adapters/release system.
- Finished secure no-SSH installer, migration/recovery/update paths, shared-hosting directory layout and final production acceptance.

As of the 14 October continuation, read the latest checkpoint first. Customer wishlist/saved bag, profile/password, customer return requests/evidence/partial receipt links, review photos and search/mobile navigation now have wired implementation, all UNRUN. Current schema order is `core`, `commerce`, `finance`, `owner-ai`, `checkout`, `customers`, `content`, `coupons`, `tracking`, `payments`, `returns`, `refund-review`, `customer-tools`, `customer-engagement`. Read `app/Schema.php` rather than trusting older notes saying five/seven/nine schemas. Preserve existing database data and use additive/versioned migrations.

## 6. Working and testing instructions

Work on actual source autonomously. Give concise Bangla progress updates and ask only for a missing business decision, genuinely unavailable source or unavoidable blocker. Do not repeatedly stop after a small phase and require me to say "continue". If a turn must end, save a precise checkpoint and state exactly what remains. Do not claim work continues in the background after the response ends.

My preference is to complete implementation first and perform full testing at the end. Do not repeatedly make me set up tests while modules are unfinished. Preserve/extend the final test plan and existing tests. When implementation is ready, use an isolated `_test` database and PHP/MySQL environment, then run the supplied suites and missing acceptance/concurrency/browser/provider tests. My XAMPP is available for necessary local results, but you cannot assume access to my localhost.

Never claim PHP lint, SQL migration, browser QA, gateway delivery or any test passed unless actually executed and observed. If runtime/provider access is unavailable, clearly distinguish code present from verified and specify the exact missing gate. The historical 50 JavaScript checks only cover the demo. Do not call placeholders, dead buttons or fake provider states completed features.

Keep a requirements-to-code coverage matrix and test results with dates/commands. Preserve original design while fixing defects. Final acceptance must cover fresh install/upgrade, auth/permissions/ownership, all OTP branches, concurrency/duplicate operations, financial and stock reconciliation, provider failure paths, responsive UI and full master scope.

## 7. Expected result

A complete, working, documented AELVO Fashion PHP/MySQL store + Mini ERP in the SAME approved design, with secure Admin-managed integrations, an uploadable cPanel installer package and factual test evidence. Until that is achieved, mark remaining work precisely. Do not reduce the scope to finish faster or label this handoff ZIP production-ready.

Start now by reading the attached files, identifying the exact next implementation gaps and continuing the existing project. Briefly tell me what you found, then do the work.
