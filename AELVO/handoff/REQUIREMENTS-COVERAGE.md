# Master scope coverage index — updated 21 October 2026

This is a section-level map, not a claim that every bullet is implemented. All MySQL/migration/browser/provider acceptance results are UNRUN. On 21 October, only 68-file PHP syntax and an offline SMS reply parser fixture were executed and observed; see `TEST-EVIDENCE-2026-10-21.md`. For detailed latest mapping, see `REQUIREMENT-COVERAGE-2026-10-21.md`. Read the full specification and latest backlog; unlisted subrequirements are not waived.

| Master section | Source / route area | Actual state and remaining work |
|---|---|---|
| 1. PROJECT GOAL | Entire project | Partial implementation; full release incomplete |
| 2. RECOMMENDED TECHNOLOGY | cpanel/app, public, database | PHP/PDO retained; MVC separation and consolidation pending |
| 3. UI / UX REQUIREMENTS | dist; public/store.php; AdminView.php | Approved reference preserved; full production parity pending |
| 4. CUSTOMER SYSTEM | Customers, Wishlist, SavedBag, ReturnRequests, Reviews | Customer workflows wired; all new execution/UX/security checks pending |
| 5. PRODUCT MANAGEMENT | Catalogue, Uploads | Taxonomy/brand/attributes/variant pricing and full media editor pending |
| 6. INVENTORY SYSTEM | Catalogue, Orders, Returns, Purchases | Basic ledger/reservations; costing/quarantine/transfers/supplier returns pending |
| 7. STOCK RESERVATION | Orders, Checkout | Reservation lifecycle partial; expiry/failed-delivery reconciliation pending |
| 8. ORDER MANAGEMENT | Orders, Tracking | Core status flow only; complete details/bulk/rules pending |
| 9. MANUAL / OFFLINE SALES | Orders; AdminView | Simplified single-line manual order; full POS pending |
| 10. PURCHASE & SUPPLIER MANAGEMENT | Purchases, Finance | Basic single-line receipt; suppliers/dues/multiline/landed costs pending |
| 11. ADVANCE PAYMENT / PARTIAL COD | Payments, Settings | Basic fixed/percent advance and dispatch gate; advanced rules pending |
| 12. PAYMENT SYSTEM | Payments | Manual claim/verified receipt only; real gateway adapters absent |
| 13. PAYMENT RECONCILIATION | Payments, Returns, Finance | Partial manual reconciliation; gateway/courier settlement absent |
| 14. COUPON & PROMOTION ENGINE | Coupons | Global fixed/percent rules only; scoped/per-customer/free-shipping/automatic rules pending |
| 15. SHIPPING SYSTEM | Settings, Checkout | Dhaka/outside fixed fees only; advanced rules pending |
| 16. STEADFAST COURIER INTEGRATION | INTEGRATIONS.md | Steadfast adapter and reconciliation absent |
| 17. CUSTOMER RISK / FRAUD SYSTEM | Checkout, Security | Basic throttling/OTP only; explicit risk workflow absent |
| 18. DUPLICATE ORDER PREVENTION | Checkout, Orders | Placement idempotency; broader fraud/duplicate heuristics pending |
| 19. ACCOUNTING / MINI ERP | Finance | Basic accounts/ledger; full accounting reconciliation pending |
| 20. EXPENSE MANAGEMENT | Finance | Basic expense entry; reports/category/approval detail pending |
| 21. PROFIT CALCULATION | No production profit engine | COGS/cost allocation and actual profit reports absent |
| 22. ADMIN USERS & RBAC | Security, Team | Basic RBAC; named-role editor/overrides/account-level permissions pending |
| 23. AUDIT LOG | Service::audit; core.sql | Append-only application events; full history/filters/export pending |
| 24. CMS | Content | Fixed pages/hero/popup draft publishing; full CMS/help/FAQ pending |
| 25. WEBSITE SETTINGS | Settings | Partial theme/delivery/provider settings; full store settings pending |
| 26. REPORTING | No completed production reporting module | Reports/CSV/Excel/PDF pending |
| 27. DASHBOARD | AdminView | Basic counts only; full truthful operational dashboard pending |
| 28. NOTIFICATIONS | Sms | Notification queue/SMTP/events absent |
| 29. SMTP / EMAIL | No SMTP adapter | SMTP and email recovery/verification absent |
| 30. COMPLETE SEO MANAGEMENT SYSTEM — CORE REQUIREMENT | Seo, sitemap.php, robots.php, seo-errors.sql, not-found-view.php | Basic global metadata plus partial 404 logger/view; full V5 SEO pending |
| 31. FILE UPLOAD SECURITY | Uploads, Payments, Reviews, ReturnRequests | Private storage/type/size checks; scanning/orphan handling and final security review pending |
| 32. CORE SECURITY | Security, Customers, services | Security foundation; full acceptance audit pending |
| 33. CONFIGURATION & SECRETS | Settings; config.example.php | Encrypted provider foundation; full secret lifecycle and provider forms pending |
| 34. ERROR HANDLING | bootstrap; controller handlers | Basic safe errors; richer logging/recovery/form preservation pending |
| 35. BACKUP & RESTORE | No verified backup/restore runner | Backup/restore incomplete; Owner AI release remains locked |
| 36. API / INTEGRATION ARCHITECTURE | Sms, Settings, INTEGRATIONS.md | Selected BulkSMSBD adapter/config source exists; no live SMS acceptance/delivery evidence; other real adapters absent |
| 37. AI READINESS | OwnerAiPolicy, OwnerAiService | Locked foundation; real customer/owner AI not integrated |
| 38. WEB INSTALLER / FIRST-TIME SETUP WIZARD | setup.php, Schema | Empty-database bootstrap; complete no-SSH wizard/recovery pending |
| 39. INSTALLER SECURITY | setup.php | Token/config lock present; concurrency/interruption/recovery acceptance pending |
| 40. CPANEL INSTALLATION FLOW | INSTALL.md | Staging instructions; final portable cPanel bundle not ready |
| 41. APACHE / .HTACCESS | public/.htaccess | Basic deny/index/rewrite scaffolding; final hosting acceptance pending |
| 42. ENVIRONMENT DETECTION | bootstrap, setup | Basic extension/environment checks; complete hosting diagnostics pending |
| 43. DATABASE DESIGN | database/*.sql | 15 additive schemas, ending seo-errors; full domain schema and versioned migrations incomplete |
| 44. DATABASE SAFETY | Schema, transaction services | Preservation/locking partial; versioned upgrade/recovery and concurrency tests pending |
| 45. ORDER TRANSACTION SAFETY | Checkout, Orders | Transactional order/reservations; full integrations and concurrency evidence pending |
| 46. PERFORMANCE | Catalogue and queries | Basic pagination; indexes/profiling/cache/performance gates pending |
| 47. IMAGE MANAGEMENT | Uploads, Reviews | Basic images; richer asset pipeline/optimization/metadata pending |
| 48. SEARCH & FILTER | Catalogue, search.php, store.js | Basic search/category/price/stock and inline suggestions; richer variant/brand filters pending |
| 49. TESTING REQUIREMENTS | tests; ACCEPTANCE-CHECKLIST | 68 PHP syntax checks and offline SMS parser fixture observed; full MySQL/HTTP/provider/browser checks UNRUN |
| 50. INSTALLER TEST CASES | ACCEPTANCE-CHECKLIST | Installer test matrix pending execution |
| 51. PRODUCTION READINESS AUDIT | STATUS-AND-BACKLOG | Production audit not performed |
| 52. FILE/FOLDER STRUCTURE | cpanel directories | Existing structure retained; controller/view/query separation incomplete |
| 53. README / DOCUMENTATION | INSTALL, README, PROGRESS, handoff | Development documentation present; final provider/operations docs pending |
| 54. DEFAULT INSTALLER EXPERIENCE | setup.php | Optional-provider behavior partial; full wizard incomplete |
| 55. UPDATE / VERSION READINESS | Schema | Ordered additive import; robust version/checksum/upgrade runner pending |
| 56. FINAL DELIVERY | Source snapshot | Not final delivery; full implementation/testing incomplete |
| 57. DEVELOPMENT WORKFLOW — VERY IMPORTANT | handoff and dist | Approved design retained; implementation ongoing; testing deferred by user |

## SEO subsection gates

All 30.x requirements remain release gates. Current `Seo.php` supplies global title/description/base/indexing; product/page inheritance is limited. There is no completed entity override/routing/redirect/structured-data/analytics/404 module.

| Subsection | State |
|---|---|
| 30.1 ADMIN NAVIGATION AND CONTROL | Incomplete — follow full V5 acceptance requirements |
| 30.2 GLOBAL SETTINGS | Incomplete — follow full V5 acceptance requirements |
| 30.3 PRODUCT SEO | Incomplete — follow full V5 acceptance requirements |
| 30.4 CATEGORY AND SUBCATEGORY SEO | Incomplete — follow full V5 acceptance requirements |
| 30.5 BRAND SEO | Incomplete — follow full V5 acceptance requirements |
| 30.6 CMS AND HOMEPAGE SEO | Incomplete — follow full V5 acceptance requirements |
| 30.7 URLS, SLUGS AND ROUTE OWNERSHIP | Incomplete — follow full V5 acceptance requirements |
| 30.8 REDIRECT MANAGEMENT | Incomplete — follow full V5 acceptance requirements |
| 30.9 XML SITEMAPS | Incomplete — follow full V5 acceptance requirements |
| 30.10 ROBOTS.TXT AND INDEXING | Incomplete — follow full V5 acceptance requirements |
| 30.11 CANONICAL, FILTER, SEARCH AND PAGINATION POLICY | Incomplete — follow full V5 acceptance requirements |
| 30.12 STRUCTURED DATA | Incomplete — follow full V5 acceptance requirements |
| 30.13 BREADCRUMBS AND INTERNAL LINKS | Incomplete — follow full V5 acceptance requirements |
| 30.14 SOCIAL SHARING | Incomplete — follow full V5 acceptance requirements |
| 30.15 IMAGE SEO AND DELIVERY | Incomplete — follow full V5 acceptance requirements |
| 30.16 INDEXING AND DISCONTINUED PRODUCTS | Incomplete — follow full V5 acceptance requirements |
| 30.17 SEO AND SOCIAL PREVIEWS | Incomplete — follow full V5 acceptance requirements |
| 30.18 SEO WARNINGS | Incomplete — follow full V5 acceptance requirements |
| 30.19 SEARCH CONSOLE VERIFICATION | Incomplete — follow full V5 acceptance requirements |
| 30.20 ANALYTICS AND MARKETING INTEGRATIONS | Incomplete — follow full V5 acceptance requirements |
| 30.21 PERFORMANCE AND MOBILE SEO | Incomplete — follow full V5 acceptance requirements |
| 30.22 CUSTOM 404 AND ERROR MONITORING | Partial: new `seo-errors.sql`, `Seo::record404/review404`, bilingual error page. Apache fallback, concurrency and final checks pending |
| 30.23 ROLE-BASED PERMISSIONS AND AUDIT | Incomplete — follow full V5 acceptance requirements |
| 30.24 CENTRAL ARCHITECTURE AND DATA MODEL | Incomplete — follow full V5 acceptance requirements |
| 30.25 FUTURE BLOG READINESS | Incomplete — follow full V5 acceptance requirements |
| 30.26 ACCEPTANCE CRITERIA AND RELEASE GATE | Incomplete — follow full V5 acceptance requirements |
| 30.27 OFFICIAL TECHNICAL REFERENCES | Incomplete — follow full V5 acceptance requirements |
