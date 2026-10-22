# AELVO checkpoint — SEO Routes — 21 October 2026

This is continuation of the previous source snapshot. No demo replacement, live deployment or provider action occurred. Approved `dist/` preserved.

## Implemented files

- `cpanel/database/seo-routes.sql` — schema **16**, public canonical and historical path table; backfill for existing products.
- `cpanel/app/PublicRoutes.php` — normalizes paths; restricts to same-site safe paths; validates product slug; direct current/redirect resolving; transactional slug history rebasing; authorized, audited manual 301 create and enable/disable.
- `cpanel/app/Catalogue.php` — product saves reserve slug ownership and audit changes in product transaction. Existing product status cannot make draft or archived URLs public.
- `cpanel/public/.htaccess`, `store.php`, `navigation-view.php` — Apache clean route rewriting, canonical product links and legacy numeric URL 301, path normalization, 404 for missing unpublished routes and invalid shop pagination.
- `cpanel/app/Seo.php`, `public/sitemap.php` — homepage root canonical/sitemap, clean product canonical/sitemap, page-specific unfiltered shop pagination and noindex/follow filtered pages.
- `cpanel/app/AdminView.php`, `public/index.php` — CSRF/RBAC redirects screen and route status. Exact local destination to published product only; no external URLs, regex or blind query carryover.
- `cpanel/app/Schema.php` — sixteen ordered SQL files and strict ownership integrity check after additive backfill.
- `cpanel/tests/seo-routes.php`, `bin/check.php` — offline safety tests and suite hook.

## Observed test evidence (local; not acceptance)

- `find cpanel -name '*.php' -type f -print0 | xargs -0 -n1 php -l` — **70 files**, no syntax errors, PHP 8.4.24.
- `php cpanel/tests/seo-routes.php` — passed local slug/path safety checks.
- `php cpanel/tests/sms-adapter.php` — passed offline SMS parser response fixtures, **no real SMS send**.
- Environment: PHP lacks PDO MySQL and cURL; there is no local MySQL/MariaDB instance. Therefore **NO** schema import, DB integration suite, HTTP 301/404 tests, XAMPP/browser QA or real provider validation passed or was executed.

## Risk and TODO

- This schema is **not production-migrated**. Back up existing DB before applying additive schema. A reusable, versioned, failure-recoverable upgrade runner remains outstanding.
- Master V5 per-entity SEO (brand/category/CMS/home), strict route namespace/canonical overrides, 302/manual edit, richer sitemap splitting, structured data and Search Console/consent marketing are unfinished.
- Broader PHP design parity, ERP stock/purchasing/POS, payment gateway and Steadfast settlement, reports, AI and installer/upgrade work unfinished. No production readiness claim.
- End-stage acceptance should cover product duplicates, simultaneous slug updates/rollback, old-link 301 and double slashes, disabled/deleted destinations, active/draft/archived, queries, language, canonical and sitemap, and hostile paths.

**Next implementation:** per-entity SEO override model, category/brand registry and safe canonical validation, then continued UI/ERP work without dropping Master V5 scope.
