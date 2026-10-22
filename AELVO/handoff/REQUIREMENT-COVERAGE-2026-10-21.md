# AELVO — Requirements → code coverage (21 October 2026)

**Interpretation.** This is an **initial section-level mapping**, not a weighted completion score and **not** a statement that every bullet is implemented. Requirements source: `MASTER-SPEC-V5.txt` (57 numbered sections) plus the current `DESIGN-LOCK.md` and `APPROVED-DESIGN-AND-RULES.md`. A future acceptance pass must expand these rows into every mandatory field/action/permission/provider case. Do not use the historical 45–55% estimate to mark completion.

**Status semantics:** `Partial` = real source present with known unfinished elements; `Preview only` = approved `dist/` demo, no production backing; `Absent` = implementation not found for central requirement; `Process/open` = deployment/test/documentation requirement still to be evidenced. Unless otherwise stated, **all PHP/MySQL full runtime, migration, browser, concurrency and provider acceptance gates are UNRUN**. The October 21 SMS offline parser and edited PHP syntax checks are narrower evidence.

| V5 section | State | Evidence/source and next missing requirement |
|---|---|---|
| 1 Project goal | Partial | `cpanel/app/` core modules; full store + ERP incomplete |
| 2 Technology | Partial | PHP/PDO, MySQL schema, `dist/` HTML/CSS/JS; clean MVC/service separation and shared hosting integration still incomplete |
| 3 UI/UX | Partial | Approved `dist/` intact; `cpanel/public/store.php`, `AdminView.php` not in full design parity |
| 4 Customer | Partial | `Customers.php`, `Wishlist.php`, `SavedBag.php`, `ReturnRequests.php`; account/security/social and acceptance incomplete |
| 5 Products | Partial | `Catalogue.php`, `Uploads.php`, `commerce.sql`; taxonomy/brand, full variant cost/fields, publication, barcode, SEO absent |
| 6 Inventory | Partial | `Catalogue.php`, `Orders.php`, movement ledger; quarantine, cost layers, warehouse/alerts incomplete |
| 7 Reservations | Partial | `Checkout.php`, `Orders.php` locks and releases; all concurrency/failed-delivery branches unverified |
| 8 Orders | Partial | `Orders.php`, `Checkout.php`, `Tracking.php`; full workflow/status/admin reconciliation incomplete |
| 9 Manual/POS | Partial | Single-item staff-assisted order in `Orders.php`/`AdminView.php`; multi-item POS/paid receipt/invoice absent |
| 10 Purchasing/suppliers | Partial | `Purchases.php`, finance schema; multi-line PO, landed cost, supplier dues/payment/returns incomplete |
| 11 Advance/partial COD | Partial | `Checkout.php`, `Payments.php`; rule engine, verified net COD and courier lifecycle incomplete |
| 12 Payments | Partial | Private proofs + manual verified receipts; actual gateway, signed callbacks and automated refunds absent |
| 13 Reconciliation | Partial | Basic receipts; gateway disputes/courier settlement/variance reconciliation absent |
| 14 Coupons/promotions | Partial | `Coupons.php`, `coupons.sql`; per-user first-order/category/product/automatic/stackable rules absent |
| 15 Shipping | Partial | Basic zone fees in `Settings.php`, checkout; rates/tax/risk rules incomplete |
| 16 Steadfast | Absent | No real booking/status/cancel provider adapter, signed events or timeout reconciliation |
| 17 Customer risk | Preview only | UI demonstration in `dist/`; durable risk case/decision/evidence flow absent |
| 18 Duplicate orders | Partial | Checkout request keys + transaction constraints; wider idempotency/concurrency matrix incomplete |
| 19 Accounting/Mini ERP | Partial | `Finance.php`, `FinanceReconciliation.php`, `InventoryCosting.php`, `FinancialPerformance.php`; actual receipts, FIFO cost coverage, return reversal and reporting bridge exist as unverified source; fully reconciled P/L still missing |
| 20 Expenses | Partial | `Finance.php`, private documents; drafts/approval/reconciliation/report exports incomplete |
| 21 Profit calculation | Partial | `FinancialPerformance.php` exposes source-only delivered-sale/refund/return/fee cost bridge and unknown-cost counts, explicitly NOT full or verified net profit; tax/fulfilment/accrual reconciliation missing |
| 22 Admin users/RBAC | Partial | `Security.php`, `Team.php` role grants; per-user overrides, detailed scope and recovery incomplete |
| 23 Audit | Partial | `aelvo_audit` writes present; comprehensive before/after, financial append-only coverage and retention not accepted |
| 24 CMS | Partial | `Content.php`, staged hero/popup/static pages; full CMS/marketing editor absent |
| 25 Website settings | Partial | `Settings.php`, Admin forms; general/contact/footer/provider details incomplete |
| 26 Reports | Partial | `Reports.php`, `FinancialPerformance.php`, Admin reports and restricted CSV exports cover dated operational and provisional finance data; production-grade actual financial reporting, deep filters and full test acceptance missing |
| 27 Dashboard | Partial | Simple `AdminView.php` numbers; approved performance/dashboard/real report parity missing |
| 28 Notifications | Absent | Real message queue/transactional notification flows missing |
| 29 SMTP/email | Absent | Encrypted placeholders only; actual SMTP queue, send policy and Owner verification missing |
| 30 SEO | Partial | `Seo.php`, robots/sitemap basics, `seo-errors.sql`; see 30.1–30.25 breakdown below |
| 31 Upload security | Partial | `Uploads.php`, `Reviews.php`, private evidence handlers; scanning, lifecycle/orphan cleanup, auditing incomplete |
| 32 Core security | Partial | Auth, CSRF, RBAC and escaping foundations; end-to-end exploit/permission tests unrun |
| 33 Configuration/secrets | Partial | Encrypted `aelvo_provider_secrets`, protected config; specific providers and secure backups incomplete |
| 34 Error handling | Partial | User-friendly errors and generic exception handler; detailed redaction/recovery verification unrun |
| 35 Backups/restore | Absent | No proven restorable upload+database backup/rollback path |
| 36 API/integration architecture | Partial | New BulkSMSBD submission transport; payment/courier/Meta/AI adapters absent |
| 37 AI readiness | Partial | Locked `OwnerAiPolicy.php`/`OwnerAiService.php`; model, inbox, email, release runner absent |
| 38 Web installer | Partial | `public/setup.php`, setup token, `Schema.php`; reliable no-SSH recovery/update flow incomplete |
| 39 Installer security | Partial | Authenticated token/empty database checks; interruption/concurrency/permissions unverified |
| 40 cPanel installation | Process/open | Docs and folder layout exist, final clean upload/bootstrap recovery not proven |
| 41 Apache/.htaccess | Partial | Basic isolation and sitemap aliases; arbitrary Apache 404 handling, route registry and live host configuration incomplete |
| 42 Environment detection | Partial | `public/setup.php` PHP/PDO/Sodium/cURL checks; preflight on actual XAMPP/cPanel unrun |
| 43 Database design | Partial | 15 additive SQL files in `Schema.php`; planned taxonomy/SEO/courier/settlement ledgers absent |
| 44 Database safety | Partial | Locks and transactions in major services; versioned idempotent upgrades/recovery/concurrent load unverified |
| 45 Order transaction safety | Partial | Price/stock/redeem checks in `Checkout.php`; full contention/provider adverse cases missing |
| 46 Performance | Process/open | Query and pagination foundations; metrics/cache/index design acceptance absent |
| 47 Image management | Partial | `Uploads.php`, colour galleries, private review media; full ALT/srcset/optimizations absent |
| 48 Search/filter | Partial | `Catalogue.php`, inline `public/search.php`; taxonomy/full criteria and parity incomplete |
| 49 Testing | Process/open | Historical 50 JS demo tests only. New PHP offline SMS fixture check, syntax lint; `_test` database and browsers pending |
| 50 Installer tests | Process/open | Test list exists; clean/failure/interrupted installer tests not executed |
| 51 Production audit | Process/open | Release cannot be called ready |
| 52 Folder structure | Partial | Current code `cpanel/app`, `public`, `storage`, `database`; final shared-hosting layout/release not accepted |
| 53 Documentation | Partial | INSTALL, README, PROGRESS, handoff; final operational/provider/SEO instructions missing |
| 54 Default installer experience | Partial | Preflight/token bootstrap only; polished multi-step browser wizard incomplete |
| 55 Update/version readiness | Partial | Idempotent CREATE TABLE additions; no full versioned DB migration runner or safe recovery |
| 56 Final delivery | Process/open | Development checkpoint ZIP only, not complete live release |
| 57 Development workflow | Process/open | Continuing original code and approved design, saving factual checkpoints; substantial implementation and final gates remain |

## V5 full SEO breakdown (not limited to meta tags)

| V5 SEO | State | Evidence and next missing requirement |
|---|---|---|
| 30.1 Admin/navigation | Partial | Single SEO screen in `AdminView.php`, new 404 table; full dedicated SEO modules/tabs absent |
| 30.2 Global metadata | Partial | `Seo.php`, `Settings.php`; organization/social/schema/defaults incomplete |
| 30.3 Product metadata | Absent | Per-product override model/editor and slug/publish cases missing |
| 30.4 Category/subcategory | Absent | Durable taxonomy and per-entity SEO missing |
| 30.5 Brand SEO | Absent | Durable brand registry + metadata missing |
| 30.6 CMS/home SEO | Partial | Global fallback and content pages, no separate entity overrides |
| 30.7 URL/slug ownership | Absent | Atomic route registry, unique slugs and history missing |
| 30.8 Redirects | Absent | Validated server-side redirect admin and loop protection missing |
| 30.9 Sitemap | Partial | `sitemap.php`, `Seo.php`; canonical-specific eligibility/route ownership incomplete |
| 30.10 Robots/indexing | Partial | `robots.php`, global noindex; fine-grained policies and staging testing missing |
| 30.11 Canonical/query/pagination | Partial | Conservative defaults, no full canonical and filter/variant/pagination rules |
| 30.12 Structured data | Absent | Product/Offer/Organization/Breadcrumb verified truthful JSON-LD missing |
| 30.13 Breadcrumbs/internal links | Partial | Product related links, no full taxonomy hierarchy canonical routing |
| 30.14 Social sharing | Partial | Base OG tags; images/URLs/type/Twitter per-entity missing |
| 30.15 Image SEO | Partial | Uploads/media; ALT editor, resize/srcset/dimensions incomplete |
| 30.16 Discontinued/indexing | Partial | Product archive; granular disposition/410/replacement/noindex policy missing |
| 30.17 SEO previews | Absent | Google/social effective previews missing |
| 30.18 SEO warnings | Absent | Diagnostics overview and actionable warnings missing |
| 30.19 Search Console verification | Absent | Exact token/file/DNS models and validation missing |
| 30.20 Analytics/marketing | Absent | Consent-ready approved integrations and event IDs missing |
| 30.21 Mobile/performance SEO | Partial | Server HTML present, design parity and measured CWV incomplete |
| 30.22 404/error monitor | Partial | **New** `seo-errors.sql`, `Seo::record404/review404`, `not-found-view.php`; app routes covered only, Apache/ErrorDocument and concurrent HTTP acceptance pending |
| 30.23 SEO RBAC/audit | Partial | Existing coarse `seo.edit`/`view`, review audit; fine-grained SEO Manager permissions missing |
| 30.24 Shared SEO services/data | Partial | `Seo.php` foundations; entity registry/structured data/redirect services absent |
| 30.25 Future blog readiness | Absent | Planned extension hooks not yet created; no full blog without approval |
| 30.26 SEO acceptance criteria | Process/open | Server metadata/redirect/sitemap/mobile/security/browser and ownership checks not completed |
| 30.27 Official technical references | Process/open | Check official SEO and integration technical references against implementation before final acceptance |

## Next implementation working order

1. Build **SEO route ownership + taxonomy/brand data foundation** as additive versioned schema + reusable route resolver; do not publish search URLs or auto-redirect invalid products. Then per-entity SEO editors and canonical-aware sitemap, JSON-LD and analytics.
2. In parallel, port exact approved `dist/` presentation/components to PHP without importing demo data/permissions; finish operational catalogue/variants/cost, purchasing/stock/POS, staff permissions.
3. Complete payment gateway, courier/COD, accounting, SMTP/notification/social integration, customer-support AI and Owner-only locked release infrastructure.
4. Implement secure browser upgrade/recovery and run exhaustive final tests on `_test` MySQL plus actual desktop/mobile browsers, including these new SMS/404 flows.

**Missing product decisions:** return/exchange eligibility window and first live payment gateway have not been supplied as firm policies; do not invent them. They do not block the independent implementation tasks above. No passwords or provider credentials should be sent in chat.


## Additional implementation — 21 October, continuation B (16th schema)

- **SEO 30.7 URL/slug ownership**: Partial — current product paths, atomic history reservation and route collision enforcement in `app/PublicRoutes.php`, `app/Catalogue.php`, `database/seo-routes.sql`; category, brand, CMS namespace and tested upgrades outstanding.
- **SEO 30.8 Redirect management**: Partial — automatic product slug 301s and bounded same-site manual 301 create/enable/disable with audit, no automatic external/regex support. Editing, reviewed state, optional 302, full diagnostics still outstanding.
- **SEO 30.9 Sitemaps**: Partial — homepage root and current published product URLs, bounded chunk output; broader entities/per-entity noindex and HTTP validation pending.
- **SEO 30.11 Canonical/pagination**: Partial — product clean URLs, homepage root, per-page unfiltered shop canonicals, filtered/sorted `noindex,follow`, out-of-range page 404. Full facets/curated landing pages/category URLs pending.
- **Testing/Schema**: Partial local preflight — 70 PHP lint checks and offline path safety checks. MySQL/PDO Apache/browser/provider integrations untested. Current schema order 16 files; no production migration.
