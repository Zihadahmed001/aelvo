# Final acceptance checklist — not executed

The user requested full testing after implementation. This checklist is not evidence of passes. Extend it from every master requirement. Use only isolated fixtures and provider sandboxes unless the owner explicitly authorizes real actions.

## Commands / environment

- PHP 8.2+, PDO MySQL, InnoDB MySQL/MariaDB, required extensions from installer, Apache/XAMPP and target cPanel-like layout.
- Fresh dedicated empty database ending `_test`, private config and all schemas from app/Schema.php.
- From `cpanel/`: `php bin/migrate.php`, then `php bin/check.php` after required test updates. It invokes lint + policy/database/checkout/customer-content suites.
- From `AELVO/`: `node scripts/check.mjs` for the reference demo only.
- PHP suite cleanup/empty-database guards and fixtures must be reviewed for ALL additive tables before running. Never point at real/populated data.
- Add missing module suites; do not equate the supplied four PHP files with complete coverage. Save exact command, environment, result and failure/fix evidence.

## Functional and integrity gates

- Fresh install, incorrect DB credentials/host, missing extension, unwritable config/storage, nonempty DB refusal, interrupted migration, two installers racing, refresh/back/direct steps, CSRF, setup lock/re-entry and Owner uniqueness.
- Config/setup keys/logs/backups/private media inaccessible via Apache; only public/ exposed in production. Actual shared-hosting no-SSH installation documented and verified.
- Owner/staff/customer login/session expiry/logout/disable, permission revocation and direct-route/API authorization. Owner identity cannot be forged by role labels or client fields.
- Signup OTP expiry, wrong attempts persisted, resend/rate limits, superseded challenges, replay, provider failure and concurrent attempts.
- Matching verified account-phone checkout bypasses NEW OTP only; guest/alternate-number checkout requires its own valid verification. Changing number/account invalidates prior association. No history merge by phone match.
- Password recovery wrong/expired/replayed code, unknown/disabled account, concurrent reset/phone change, previous-session invalidation, new-session epoch and CSRF rotation. No password/OTP leakage in logs or responses.
- Address CRUD ownership (including guessed IDs), maximum entries, stale edits/deletes, concurrent saves, checkout prefill, old-order snapshots unchanged and alternate phone still unverified.
- Variant activation, colour photos, stock availability, product archive, search escaping, all filters/pagination and bag survival across catalogue pages.
- Independent Buy It Now bag, reload/back/duplicate confirmation, stale prices/delivery/coupon, out-of-stock race, rollback on failure, reservation/delivery/cancellation invariants.
- Coupon global/per-customer/product limits, boundaries/schedules, stacked rules, simultaneous last use, quote changes, unpaid cancellation release and historical discount preservation.
- Payment proof access/limits/MIME/oversize, conflicting references/claims, verification role checks, duplicate callbacks/receipts, under/overpayment, insufficient/disabled account, advance dispatch hold and correct remaining COD.
- Pending refund commitment concurrency, proposal replay/mismatch, exact approval, rejection/revocation, invalid destination/evidence, duplicate payout/ref, permission removal, insufficient funds, failed transaction rollback, no duplicate refund/ledger entry.
- Partial return quantities, over-return, physical SKU match, damaged/wrong-item quarantine, separate restock action, duplicate stock post, receive-without-refund and refund-without-automatic-restock.
- Courier booking eligibility and verified COD, timeout ambiguity reconciliation, failed booking/retry, signed callbacks, stale/duplicate status, cancellation/failed delivery and physical return handling.
- Settlements: collections distinct from remittance; fees booked once; partial actual bank receipt posted correctly; documented shortage remains receivable or authorized adjustment; no duplicate collections/payments/revenue.
- Multi-item POS: stock/price recheck, correct cash change, atomic sale+payment+stock, retry, authorized cancellation/refund and invoice references.
- Purchase draft/receipt/payment independence, multi-line costs, discounts/landed-cost allocation, partial receipts, supplier dues/payments/returns, no duplicate liability/payment/stock/COGS.
- Account funds/transfer/expenses/corrections: immutable history, reference uniqueness, same-account rejection, locks, sufficient funds and balancing ledger.
- Profit/revenue/tax/discount/returns/fees/cost reconciliation; exclude owner contributions/transfers and unremitted COD from cash/profit; exports agree with source records and filters; spreadsheet formula-injection prevention.
- Review delivered ownership/moderation/photos/reply permissions, no fabricated ratings; private order/profile data isolated across customers.
- CMS draft/version conflict/private preview/media permission, publish/unpublish/schedules, safe HTML/URL handling, video range/poster/pause and popup frequency/routes.
- SEO: entity inheritance/overrides, canonical/indexability, reserved/duplicate/concurrent slugs, atomic redirects/no loops, external-host policy, sitemap exclusions/limits, robots staging/noindex, truthful schema and consent-aware analytics.
- Customer AI retrieval permissions, actual catalogue/price/stock truth, malicious messages/attachments, safe cart draft only, explicit checkout confirmation and provider errors/rate limits.
- Support general+channel permission, staff assignment, revocation during conversation, authenticated ownership, handoff stops bot, signed/idempotent Meta webhooks and outbound retry state.
- Owner AI real verified email only, expiry/inactivity, exact proposal digest/version/nonce, single use, financial prohibition, restricted adapters, no generic shell/SQL bypass.
- Release runner backup verification, staged artifact digest, fixed tests, explicit artifact approval, source-change conflict, lock/crash recovery/health check/rollback and secrets/policy exclusion. Stay locked if any gate is missing.

## Visual and deployment gates

- Compare approved `dist/` screens to implemented PHP at desktop and mobile widths in EN/Bangla. Same components/assets/colour direction; no new generic template.
- Hero CTA/caption never overlap; long translations and validation messages do not clip. Tables/forms/popup/search/video/bottom navigation avoid overlap.
- Hover/touch/keyboard focus, disabled states, reduced motion, input autofill/paste, loading/error/success only after real server results.
- No demo OTP/sample financial reports/fake tracking/placeholder success in production. Real setup readiness shown honestly; optional integrations skipped without fake enabled state.
- Backup/restore, browser cache/private responses, error logging/redaction, input/output/upload/SQL/CSRF/path traversal/role bypass cases; performance/query counts/pagination and asset optimization.
- End-to-end fresh cPanel-style install, Owner configuration, products, guest and account purchase, verified advance, fulfillment, payment/settlement, return/refund and report reconciliation.

Record UNRUN/BLOCKED/FAILED/PASSED accurately. Do not call the release ready until blockers and required acceptance criteria are resolved.

## 14 October customer continuation — UNRUN

- Apply `customer-engagement.sql` after `customer-tools.sql`; fresh setup and existing development schema paths both need execution.
- Wishlist: guest authentication, account isolation, idempotent save/remove, 100-item bound under simultaneous requests, inactive product privacy/removal, refresh/device persistence.
- Saved bag: guest-to-account merge only once even after interrupted redirect, 30-variant/100-unit limits, account switch/logout/expiry isolation, concurrent devices without overwriting each other's changes, archived variant removal, stock recheck. A saved bag never reserves stock. Verify normal checkout decrements purchased units transactionally exactly once; independent Buy It Now does not alter the regular bag.
- Customer return: delivered item ownership, no guest phone-match access, amount/quantity manipulation, duplicate key same/different payload, concurrent last eligible units, pending + partial requests + physical receipts cannot exceed delivered units.
- Request review: view/edit permissions, stale version, customer-visible explanation, pending-only transition, no stock or money effects. Physical receipt requires accepted matching request and cannot exceed its remaining units; partial/final states and append-only event history agree. Unlinked staff receipts must not bypass open online requests.
- Evidence: malformed arrays, oversized/invalid/polyglot image, 25MP bound, failed file move/DB rollback cleanup, another customer and unauthorized staff cannot fetch request evidence. Review photos stay private until approved; hiding a review or archiving a product removes anonymous access. No stale public cache of hidden photos. Verify up to three photos per review, duplicate review rejection, safe HTML escaping and original customer text preservation.
- Profile: optimistic concurrent name updates, no historical order rewrite, alternate account cannot change profile. Password: current password/rate limits, matching length-bounded replacement, old sessions and outstanding challenges revoked, current session rotated, no credentials in logs or HTML.
- Header search: DB results only, invalid inputs, empty/no results, stale/cancelled requests, keyboard escape/focus, mobile fit and ordinary search submit when JavaScript/network fails.
- Mobile navigation: same approved icons/order, Bag centered, active indicator, safe area, EN/Bangla and reduced-motion behavior. Review photos and added controls require desktop/mobile parity inspection.

## 21 October 2026 SMS/404 additions — only offline parsing and PHP lint attempted

- BulkSMSBD: Owner versus staff POST permission/CSRF; fresh API-key save, blank update keeps old key, malformed sender/key/language rejected, encryption secrecy; no legacy SMS route fallback; local +88/880 phone normalization; masking Bangla/explicit English configuration; missing cURL/Sodium/credentials fail closed.
- BulkSMSBD: 202 `response_code` is submitted only; reject failure codes, HTTP 4xx/5xx, ambiguous/oversized/non-JSON replies, TLS errors, timeout and 3xx redirect. Confirm real vendor response format and delivery manually in a private, authorized staging setup without exposing keys or treating submission as an OTP grant. Test resend throttles and OTP expiry/attempt/replay with provider failure.
- SEO 404: apply 15th additive schema to isolated `_test` without wiping prior data; unknown `page`, invalid product ID, missing/unpublished info content return actual HTTP 404 + noindex without canonical, custom EN/Bangla responsive screen; recommendations drawn from published items only.
- SEO 404: do not store raw query strings, personal tokens, IPs, external request Host or secrets; concurrent repeated hits aggregate; 5,000-path cap/retention behaves under load; staff without SEO edit cannot mark reviewed; CSRF/version conflict, audit, no automatic redirect. Verify Apache/static unknown URLs separately. No full SEO acceptance claimed.

### Additional final XAMPP gates — supplier and quarantine continuation

- [ ] Run all 19 schemas on an empty dedicated `_test` MySQL database and an independently backed-up upgrade copy; verify all foreign keys and safe schema replays.
- [ ] Verify supplier create/edit, disabled supplier rejection on new purchases, purchase totals with costs/discounts, exact conservation of line landed totals, and legacy text-only purchase visibility.
- [ ] Verify purchase receiving idempotency, atomic multi-variant stock posting, duplicate purchase references, invalid document claims and permission bypass attempts.
- [ ] Verify partial/complete supplier payout accounting, no overpayments, insufficient cash rejection, duplicate reference and identical/reused request-key behavior, reversal/reconciliation process and required private evidence access restrictions.
- [ ] Verify quarantine hold/release/writeoff boundaries, retry idempotency, exact stock movement and audit history; test simultaneous checkout/manual-order/purchase/adjustment/quarantine operations for oversell and deadlocks.
- [ ] Verify all public saleability views, account saved bag, product screens, search and staff dashboards exclude quarantined stock consistently.
- [ ] Do not infer final COGS/profit from purchase landed-cost snapshots until a tested stock cost-layer and expense reconciliation engine exists.

### Database-backed taxonomy, category and brand SEO (schema 20; final tests pending)

- [ ] Existing products retain original category/prices/variants after additive migration; root backfill runs idempotently, including renamed category slugs.
- [ ] Active category, subcategory, and brand public pages show only applicable active products; hidden entities and deleted routes return real 404.
- [ ] Permission/CSRF and optimistic version conflicts block unauthorized or stale taxonomy changes.
- [ ] Concurrent/new/renamed slugs preserve single current owner and immutable direct 301 history; redirected aliases cannot be hijacked.
- [ ] Per-entity metadata, pagination self canonicals, truthful brand schema, sitemap filtering, OG images and staging-wide noindex are verified in the PHP HTML/HTTP response.

### Added custom CMS acceptance gate — schema 21 (NOT RUN)
- [ ] Fresh installation and additive upgrade on isolated XAMPP `_test` database; inspect every table/route and ensure pre-existing published content/orders are retained.
- [ ] Content-editor vs SEO-editor permissions on create, edit and explicit publish; CSRF, optimistic stale revision, and concurrent slug collisions leave published content unchanged.
- [ ] Draft hidden from all public URLs and sitemap; publication/unpublication, footer choice, bilingual plain-text escaping, 404 HTTP status and noindex of hidden routes.
- [ ] Old CMS slug redirects directly by HTTP 301 to new owned page after publication; no redirect loop, external host, stale target or URL takeover.
- [ ] CMS/fixed-page metadata and index/follow combinations, globally disabled indexing, true 1,000-URL sitemap chunking, document loc escaping, no draft leakage.
- [ ] Manual responsive/browser compare against approved `dist/` (PHP UI currently not at design parity).

- [ ] CMS structured blocks: save/reorder/remove/bilingual fallback/FAQ and safe CTA; invalid type, external URL, cross-site redirect, script/text injection rejected. Verify published revision does not change while drafts are edited.
- [ ] CMS private preview: anonymous/non-content staff 404; authorized reviewer sees saved draft only; no cache/index, no access to other private files, images remain protected before publish.
- [ ] CMS published image allowlist: valid JPEG/PNG/WebP and ALT, changed/disabled revisions revoke old media; transaction rollback leaves live page/route/media unchanged; repeated concurrent publishes cannot leak draft media.
- [ ] CMS metadata/JSON-LD: published page drives true SEO title/description/canonical, FAQPage emitted only for visible answers, HTML/script injection cannot terminate JSON-LD, sitemap and bilingual links agree.

### Finance reconciliation acceptance (source added 21 October; unverified on MySQL)
- [ ] On isolated `_test` database, insert balanced and mismatched account/ledger entries; verify latest snapshot and signed SUM checks detect mismatches without making corrections.
- [ ] Transfers require exactly two distinct accounts and zero combined delta; CSV export must respect `finance:view` AND `reports:view`.
- [ ] Confirm reconciliation of source amounts and signs for manually verified order receipt, refund payout, supplier payment, courier remittance and gateway settlement; expose missing/double ledger entries without changing balances.
- [ ] Distinguish draft purchases from received supplier dues; flag legacy received purchases lacking landed-cost rows; never show gross revenue or cash receipts as profit.
- [ ] Test overcollection, overrefund, courier COD, uncertain bookings and settlement overpayments, including 100+ anomalies with accurate export truncation notices and CSV formula injection.

### Storefront Bag / customer account continuation (source added 21 October; XAMPP NOT RUN)
- [ ] In `_test` MySQL with two customer sessions and one guest, verify additive and absolute bag quantity mutations remain separate, require CSRF, enforce active variant/available stock bounds, and reject another customer's variant. Confirm concurrent quantity changes serialize under customer lock and checkout revalidates stock atomically.
- [ ] Ensure a repeated `bag-quantity` POST cannot increment units, stale or unavailable variants can be removed, oversold/unavailable items disable checkout, independent Buy It Now is not modified by regular bag changes, and signed-in saved bags survive logout/login without leaking into the next account.
- [ ] Compare responsive bag summary, product cards (real product link vs Buy Now), empty bag, out-of-stock and order history screens to approved `dist/` design on desktop/phone in both languages; keyboard input, focus, contrast, mobile safe area and reduced motion.
- [ ] Verify `orders_page` bounds with 0, negative, huge, invalid and valid pages, ownership of each order/return/review/payment proof, no guest order linking by phone, and stable previous/next page links.
- [ ] Under strict storefront CSP confirm mobile bottom navigation liquid indicator functions without blocked inline style, in-page search works, and the browser console has no CSP errors.

### Product detail variant-picker acceptance (source-only; not executed in browser)
- [ ] Keyboard/touch/desktop/mobile verify colour and size chips, unavailable sizes, correct stock label, colour photo gallery, quantity limits, and no-JavaScript select fallback. Test with >1 colour, duplicate size labels, no media, all variants unavailable and a variant that becomes unavailable before POST.
- [ ] Confirm colour HEX/media ALT/size/name are escaped; CSP allows SVG swatches without inline style; actual ID remains tied to server-validated variant on Bag and independent Buy It Now, with no in-memory or browser-submitted price authority.
