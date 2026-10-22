> **13 October 2026 handoff:** Start with `START-HERE-BN.md` and `handoff/STATUS-AND-BACKLOG.md`. `dist/` is the approved visual/demo reference; `cpanel/` contains the unfinished PHP/MySQL implementation. Earlier dated descriptions below refer to their original preview stage. This snapshot is not production-ready.

# AELVO Fashion — combined frontend preview

An interactive preview of the selected monochrome storefront and Admin / Mini ERP designs. Current local update: 10 October 2026. See RELEASE-NOTES.md for the newly implemented storefront, marketing and Owner AI entry features. The cpanel/ folder contains an unconnected PHP security foundation; the full production PHP application is not implemented. Delivery target is shared cPanel hosting via file upload. No Git push or preview publication was performed for this update.

## Try the preview

Use the top **Storefront**, **Admin**, and **Installer** links to switch areas.

1. Use the header search panel or open a product, select a colour and size, add it to the centered **Bag** tab and check out. Demo OTP: `123456`.
2. Alternatively, choose **Use the verified demo account** on Sign in. Its phone is `01700007788`. The account's own verified number skips checkout OTP; a different number requires verification.
3. Place a sample order and find it in **Admin → Orders**. A submitted bKash reference leaves payment pending review. Record a verified demo payment from the order to reduce its balance.
4. Try **Manual sales**, a draft and posted **Stock Entry**, **Accounts → Transfer**, or the fixed **Courier settlement** example.
5. Open **Ask AELVO** to browse sample products or request a support handoff. Try **Admin → Support inbox** under the owner or a staff role with both inbox and channel permission.
6. Inspect **Returns** before restocking or approving a refund. Approval does not execute a payment.

All records are fictional and held in browser memory. Refreshing resets the preview. No SMS, emails, courier bookings, payment requests, or database writes are sent. Use sample information only.

## Included views

| Area | Preview coverage |
| --- | --- |
| Storefront | Home, animated header search panel, catalogue, product detail and quick view, cart, checkout, confirmation, tracking, wishlist, centered Bag navigation and support widget |
| Customers | Demo sign-in, signup OTP, account overview, orders, return request, addresses, profile and primary-phone verification |
| Store information | About, help, contact, draft policy layouts and 404 |
| Operations | Orders and details, manual sales, product editor, variant inventory, stock movements, purchase drafts, suppliers and customers |
| Finance | Account balances, transfers, funds, expenses, payment review, COD settlement, returns, refund approval, fixed report samples and CSV exports |
| Administration | Marketing rule drafts, banner editor, CMS editor, SEO modules, theme/login/AI settings, role previews, staff records, channel-aware support inbox, archived-record restore, audit and installer simulation |

The layouts include responsive rules, floating mobile navigation, focus styles and reference-inspired motion. Separate mobile mockups were waived by the user; responsive implementation remains in scope.

## Behavior boundaries

- Demo OTP is phone-bound, expires after two minutes, allows five attempts and limits resend frequency. This is a UI demonstration, not secure authentication.
- Signup captures a required email. Google/Facebook buttons and settings are configuration previews only; no OAuth authentication or account linking happens here.
- The assistant is a deterministic catalogue helper, not a language model. It suggests in-stock sample items and adds a selected variant to the bag; it cannot create an order, reserve stock, confirm payment, or bypass checkout/OTP.
- Handoff pauses assistant replies. Inbox access requires both the Support permission and the matching Website, WhatsApp, or Messenger permission. Assignment targets are filtered to active, authorized sample staff, and reply permissions are checked again on submit.
- WhatsApp and Messenger inbox threads are clearly labeled fictional samples. No provider webhook, outbound message, or Meta account is connected. See `INTEGRATIONS.md` for the production handoff contract.
- Record lists use View / Edit / Delete actions where records are mutable. Delete moves records into a restorable archive; posted financial/stock history is not erased, active unpaid order reservations are released, and a paid active order cannot be archived before its payment/cancellation is resolved. Audit history remains append-only.
- Checkout reserves stock; delivery commits physical inventory once; cancellation releases reservations once. A cancelled reservation cannot be reopened through the status dropdown.
- Purchase drafts do not receive stock or pay suppliers. Stock drafts do not change quantities. Posting checks availability and duplicate references.
- Manual sale proceeds are the sale total, not the amount tendered before change. Transfers conserve total funds. Posted expenses cannot be overwritten.
- Payment proof, verified payment, courier collection and bank settlement are separate states. The settlement example requires the actual receipt to match the expected payout before posting.
- Return restocking requires a passed inspection and cannot repeat. Refund approval checks the sample refundable amount and does not move funds.
- Reports explicitly identify fixed sample periods. CSV export and browser print are available; live aggregation and native Excel/PDF generation are later backend work.
- Checkout offers are fixed examples: `AELVO10` gives 10% off at a minimum subtotal of BDT 2,000, capped at BDT 500; `SHIPFREE` removes the shipping fee. Marketing edits are draft rule examples and do not modify these presets.
- CMS, banners, SEO and settings demonstrate editing, validation and local draft/status changes. They do not install production routes, change real indexing, execute analytics, or configure service credentials.
- Policy copy and actual product measurements need business review. Product artwork and catalogue data are illustrative.
- The installer is a simulation. It does not connect to MySQL or install the application.

## Source and validation

This buildless preview uses HTML, CSS and JavaScript modules; it has no runtime package dependencies. Serve `dist/` through a static HTTP server; opening `index.html` as a `file:` URL will not load ES modules correctly.

| File | Purpose |
| --- | --- |
| `dist/index.html` | Entry document, preview metadata and module loading |
| `dist/assets/core.js` | Shared calculations and validation |
| `dist/assets/data.js` | Fictional initial records and in-memory state |
| `dist/assets/ui.js` | UI primitives and storefront shell |
| `dist/assets/storefront.js` | Storefront and customer templates |
| `dist/assets/admin.js` | Operations and finance templates |
| `dist/assets/configuration.js` | SEO, settings, content, staff and installer templates |
| `dist/assets/engagement.js` | Header search, theme controls, assistant workflow, channel permissions and inbox |
| `dist/assets/management.js` | Shared record actions, safe archive/restore and record views |
| `dist/assets/app.js` | Navigation and demo event handlers |
| `dist/assets/editors.js` | Colour galleries, document attachments, stock/finance controls and staff editors |
| `dist/assets/access.js` | Configurable frontend role and staff permission checks |
| `dist/assets/styles.css` | Shared desktop, responsive, print and reduced-motion styles |
| `scripts/check.mjs` | Syntax, route/asset and actual event-handler checks |
| `INTEGRATIONS.md` | Production AI, channel, permissions and order-confirmation contract |

Run `npm run check` with Node.js. On 10 October 2026, all 50 checks passed, including 138 route/state template renders and local asset resolution. The harness invokes the real event handlers with a small DOM adapter, including support permissions/handoff, checkout gating, theme/social controls, shared record actions and archive safeguards. It does not test browser layout, native form validation, focus behavior or animation. Browser visual/interaction QA was unavailable in this buildless managed preview workflow and has not been performed.

Two optional, feature-detected WebMCP tools expose the same demo cart state. Their behavior in a supported browser context remains unvalidated; ordinary UI use does not depend on WebMCP.

## Feedback update — 10 October 2026

- Phone autofill accepts spaced international numbers, local numbers and Bengali digits. The restrictive checkout HTML pattern was removed; validated numbers normalize to the same Bangladesh mobile format.
- Product colour swatches change the displayed photo. Each colour has a gallery, and colour/size combinations have independent stock. The catalogue includes Cloud and Graphite sample shoe photos. Product create/edit supports JPG, PNG and WebP uploads; an active colour needs a photo.
- Inventory includes photos and owner stock corrections with a required reason, reserved-stock checks and a new adjustment movement instead of overwriting history.
- New purchases require a slip (JPG/JPEG, PNG, WebP or PDF, up to 10 MB). Legacy sample purchase records do not have real documents. Receive-stock actions carry the purchase reference into the stock form.
- Balance corrections record before/after amounts and a reason. Add funds and transfers require an explanation and accept an optional supporting document. Uploaded files are local object URLs available only for the current session; there is no persistent server upload yet.
- Promotions can be deleted from the active list, retaining sample redemption history. A deleted preset code cannot be applied again; historical order discounts remain unchanged.
- Staff records include phone, email, designation, address, joining date and notes. The owner retains full access. The owner can create roles, assign module/action permissions, set individual staff overrides, and preview staff access. These are frontend guards, not production authorization.
- BulkSMSBD is selected for SMS setup. Its public site redirected to its sign-in page during review; no API contract, account, credentials or live SMS delivery was configured. The actual adapter must follow the merchant account's current documentation during PHP implementation.
- Navigation decision applied: search opens as a panel over the current page; bottom navigation is Home, Shop, **Bag** (center), Wishlist, Account. The entire added-to-bag toast links to the bag.
- Theme settings offer graphite/navy/forest/burgundy presets and custom accent/surface colours with contrast-aware text. Changes persist only in this browser session.
- The reference-video motion is adapted into the existing brand: animated search panel, cart feedback/flying product, liquid mobile-navigation indicator, order-confirmation truck pill, upload feedback and promo-code focus/applied motion. Reduced-motion preferences disable nonessential movement.
- Google and Facebook login settings/buttons are visible but do not authenticate anyone until a secure server-side OAuth integration is configured.
- Website assistant and channel-aware inbox are workflow previews. Website messages, WhatsApp and Messenger samples route to the owner and only staff explicitly granted both inbox + channel permissions. Handoff disables assistant replies until an assigned authorized person resumes them.
- Admin records now share View/Edit/Delete controls, with archive/restore or audited correction for histories that must not be erased.

## Production phase

The approved production architecture remains **PHP 8+, MySQL/MariaDB, PDO, Bootstrap 5, modular MVC and a cPanel installer**. The current custom preview CSS does not change that requirement.

Production work still includes server-side authentication and RBAC, CSRF protection, database constraints and transactions, durable inventory/accounting ledgers, actual cost history, secure uploads, real OTP, Google/Facebook OAuth, provider callbacks and reconciliation, Steadfast integration, a promotion engine, dynamic CMS/SEO routes, audit persistence, backups and real installer validation. Live AI, WhatsApp and Messenger connections require server-side credentials and webhook setup; routing, privacy and order-confirmation requirements are captured in `INTEGRATIONS.md`. Frontend role restrictions and demo verification must not be reused as security controls.


## Main backend — phase 1
PHP/MySQL Owner setup and login now live in `cpanel/`. Start with `cpanel/INSTALL.md`. This is a separate backend development entry point; the approved `dist/` storefront remains an in-memory demo until its data modules are connected. Backend runtime tests have not run in this environment.
