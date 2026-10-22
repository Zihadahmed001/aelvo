# AELVO support and ordering integration contract

## Confirmed 10 October additions

Production delivery uses PHP/MySQL on shared cPanel hosting, with an uploadable bundle. The customer assistant must reply in the latest customer's language: Bengali, Banglish or English. It may guide the customer through the full checkout and a secure provider payment handoff, but does not collect payment PINs/passwords/payment OTPs, execute a payment or invent success. Customer confirmation and the existing phone-OTP rules remain mandatory; only server-verified payment evidence changes payment state.

The separate Owner AI is exclusively for the original Owner. It requires real Owner login plus a code delivered to the already verified Owner Gmail/email, with optional separate AI access password. Every mutation requires approval of the exact proposed change. Financial actions (payment, refund, transfer, balance, payment approval, gateway configuration) are permanently guidance-only. Live code changes additionally require verified backup, staging tests, explicit artifact approval and rollback. See `cpanel/README.md` for the new foundation and the still-open release gates. The model must never receive the approval capability or access to secrets.

Status: planned backend work. The current Site is a private, static frontend preview. It has no durable database, live model, WhatsApp/Messenger connection, OAuth session, or external message delivery.

## Agreed channels and access

The customer-support experience is intended to cover the website, WhatsApp, and Facebook Messenger in one Admin inbox. Only the owner/Admin and staff explicitly granted access by the owner may view or handle each channel.

Enforce authorization on every backend read and mutation; a hidden button or frontend permission is not security. Require both the general `support` permission and one channel permission (`support-website`, `support-whatsapp`, or `support-messenger`). Assignments must target active staff with that same channel's edit permission. Re-check actor, channel access, assignment, and conversation state on every reply, resolve, reassign, or resume action. Owner access must not be revocable through ordinary staff configuration.

## Conversation lifecycle

- In assistant mode, answer only from approved store facts and explicitly connected, access-controlled records.
- When the customer requests a person, the model is uncertain, or the topic needs a protected action (for example refund review), persist a handoff and alert the authorized queue.
- Human mode pauses automated replies. Only an authorized assigned agent can reply or resolve. Returning a thread to assistant mode is an explicit agent action and must be logged.
- Store channel/provider IDs, event IDs, timestamps, message state, assignment, handoff reason, and audit actor without exposing credentials to the browser.
- Webhook retries must be idempotent. Verify incoming provider authenticity, reject invalid signatures, deduplicate provider event/message IDs, and make outbound delivery state/retries visible. Do not treat a webhook receipt or send attempt as customer delivery.

## AI data and actions

The model may explain published products, selectable colours/sizes, current available stock, configured delivery fees, and approved policies. These facts must come from the server. Do not invent stock, discounts, delivery promises, payment status, return eligibility, or policy exceptions.

Private order lookup requires an authenticated customer identity and server-side ownership check. A phone number typed into chat is not sufficient verification. Do not expose another customer's name, address, phone, order items, or status. Guest conversations may use public catalogue and policy information only.

The assistant may prepare a **draft** cart/order from a selected product variant and quantity. The server must validate that the product and variant are active, recalculate price/discount/shipping, re-check stock, and show the complete order for explicit customer confirmation. Do not create the order, reserve stock, charge money, verify a payment, book a courier, approve a refund, or change account data just because the model inferred the customer's intent. Keep normal sign-in, checkout validation, OTP, payment review, and order-status rules in force. Only the checkout transaction creates an order and stock reservation.

Treat customer text, attachments, and retrieved messages as untrusted data, not system instructions. Use approved guidance and narrowly scoped tools; do not pass secrets, credentials, or unrelated customer records into model context. Log the selected model/prompt version and permitted tool actions in a privacy-appropriate audit trail.

## Provider setup and secrets

Keep model API keys, Meta app secrets/tokens, webhook secrets, SMS credentials, and OAuth client secrets server-side (environment/config outside the web root or an equivalent secrets manager). Never render a secret into HTML or browser storage. Use least-privilege provider permissions and a separate test setup before production. Confirm current provider requirements and the merchant's chosen AI provider during the PHP implementation; this preview does not lock an API version or endpoint.

Google/Facebook sign-in is a separate, not-yet-connected feature. Implement provider authorization, state/nonce validation, safe account linking, callback verification, session rotation, and secure server sessions before marking either provider connected. Social login must not imply a verified Bangladesh phone number; checkout's phone verification requirement remains independent.

For WhatsApp and Messenger, configure official business/page identities, webhook subscriptions and verification through the merchant's own Meta account. Incoming events should appear in the shared inbox only after verification and authorization checks. No live integration exists in the current frontend.

BulkSMSBD remains the selected OTP SMS provider. Confirm its current merchant API documentation and approved Sender ID before building the PHP adapter; keep failures fail-closed for phone-dependent checkout.

## Release checks

Before enabling any channel, test at minimum:

1. Duplicate and out-of-order webhook events do not duplicate messages, orders, payments, stock, or customer notifications.
2. A role without the general support permission, without the channel permission, or with only view access cannot reply, assign, or resolve through direct requests.
3. Revoking a staff member's channel permission blocks the next action, including on a conversation they previously handled.
4. An unauthenticated or mismatched customer cannot retrieve private order data; successful lookup reveals only that customer's own order.
5. AI suggestions never create an order or reservation before an explicit checkout confirmation; cart price and availability are recalculated server-side.
6. Handoff stops automated replies until an authorized agent explicitly resumes them; refunds, payment claims, courier actions, and policy exceptions remain human-controlled workflows.
7. Invalid webhook signatures, provider outages, SMS failures, model failures, and rate limits have safe retry/error states and do not bypass OTP, payments, or permission checks.

The static preview covers only sample conversation rendering, permission-aware routing, deterministic catalogue suggestions, handoff state, and bag/checkout separation. These checks do not substitute for server/API security tests.

## 21 October 2026 selected BulkSMSBD OTP adapter (implementation, not live-tested)

`cpanel/app/Sms.php` uses the published BulkSMSBD endpoint `https://bulksmsbd.net/api/smsapi`, HTTPS POST with `api_key`, `senderid`, `number` in 8801... form, and `message`. Source: https://www.bulksmsbd.com/bulksms-api-bangladesh.php and https://bulksmsbd.com/faq.php. Only a response code 202 is a provider submission acknowledgement, **not** a delivery receipt or verification of a telephone number. Any unknown status, missing encrypted credentials or transport failure blocks the OTP operation. The old sms.net.bd provider is inactive; it is not a hidden fallback.

In the private Owner Admin Settings, select the pre-chosen BulkSMSBD configuration, enter an approved Sender ID and API key. Key remains encrypted; blank key on later updates preserves the saved key. Use the merchant's approved route. Bangla OTP is the default to avoid vendor masking/English restrictions; English is an explicit setting requiring merchant confirmation with the provider. Sender approval, prepaid credit, configured source-IP policy and delivery need validation by the store Owner at staging; no credential or actual provider request is present in this snapshot. Missing optional unrelated integrations must not block install. Never log keys, real OTPs or raw provider responses.


## Steadfast courier — source adapter only (21 October 2026)

- Endpoint adapter: `https://portal.steadfast.com.bd/api/v1`, `POST /create_order`, `GET /status_by_cid/{id}`. Credentials: `Api-Key` and `Secret-Key` stored encrypted in `aelvo_provider_secrets` through Owner-only Admin settings. This endpoint contract is based on public Steadfast PHP package examples; confirm your own merchant's official contract/base URL before enabling bookings. Reference: https://github.com/steadfast-it/SteadFast-Courier-Laravel-Package
- No general-purpose configurable courier URL (SSRF protection); no redirect, TLS verification enabled, fixed timeouts, bounded JSON. Only real authorized Admin booking sends a create request. The latest source has **not** connected or tested a real merchant account.
- After a timeout/ambiguous response, `aelvo_courier_shipments.booking_state` is frozen (`Prepared`/`Unknown`) to avoid duplicating parcels. No automatic retry or simulated tracking ID. Provider cancellation APIs must not be invented; manual reconciliation handling is unfinished.
- `aelvo_courier_collections`: independently documented courier custody, not cash owned by AELVO. `aelvo_courier_remittances`: confirmed actual account credit and separate withheld fee; supports partial credits while conserving collected total. Staff needs courier/finance permissions and privately uploaded evidence.
- Test `php tests/courier-offline.php` and `php tests/courier-accounting-offline.php` for standalone code invariants; these do not validate HTTP, MySQL transactions, external delivery/remittance or browser flows. A final isolated `_test` migration/concurrency/provider acceptance must happen before production.

## 21 October 2026 — website customer chat implementation (source only)

- A durable MySQL **website-channel** conversation service (`cpanel/app/CustomerChat.php`) and three additive tables (`database/customer-chat.sql`) preserve customer/staff messages, human takeover events, account-versus-guest ownership, idempotent customer submissions, and concurrency-checked model responses.
- Logged-in customer conversations are accessible only to the verified customer ID. Guests have a separate random session-bound token and are not linked to existing customer accounts or orders using names, email addresses or phone numbers. Unauthenticated GET requests do not create database chat rows. Sensitive/order data is **not** fetched for AI context.
- The OpenAI Responses adapter (`cpanel/app/CustomerAi.php`) uses a fixed HTTPS endpoint, `store=false`, no tools, bounded messages and response size, model allowlist, opt-in and basic identifier redaction. Only explicitly consenting conversations may call the adapter. Credentials are entered through **Owner → Settings → Customer AI**, stored encrypted on the server; no model requests happen merely by saving. Without valid settings/cURL/provider reply the chat becomes a human-only support queue rather than issuing a pretend answer.
- Staff need both `support:view/edit` and `channel.website:view/edit`. Taking over a thread atomically invalidates any in-flight model reply, and an expiring/uncertain AI response cannot turn into an order, payment, refund, bag modification or verified claim. Agent replies are explicitly posted and audited.
- **Not integrated:** WhatsApp/Messenger signed webhooks, external channel send/retry, admin AI knowledgebase retrieval, customer product suggestions/bag drafts, live provider testing, MySQL migration/transaction/race testing, browser/UX or private-account access testing. WhatsApp/Messenger remain disabled rather than simulated. Original Owner AI remains locked.
- Production dependencies include PHP 8.2+, PDO MySQL, cURL and Sodium, with an outbound HTTPS connection. Do not enable provider until privacy/consent, response quality and failure cases are tested in XAMPP/staging. No live AI calls were made in this implementation step.
