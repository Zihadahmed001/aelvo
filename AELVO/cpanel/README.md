> **Latest source (22 October 2026):** `Schema::FILES` has **39** ordered migrations, ending `product-details.sql`. Merchant product facts and low-stock threshold handling are source-implemented; database/browser acceptance is still pending. Older schema counts below are historical.

**Upgrade safeguard (21 October):** bundled `RELEASE-MANIFEST.json` is required by the installer/CLI migration runner. Owner → Upgrade preflight (`public/upgrade-review.php`) checks file integrity, migration status and web-root exposure without changing data. Browser-based apply/backup/restore/rollback remain unavailable until acceptance criteria are implemented and tested. See `INSTALL.md`.

> Current Schema::FILES: **28** (last: `staff-sessions.sql`). An immutable migration ledger and owner-only read-only schema status were added; legacy populated databases are blocked from automatic upgrade. See INSTALL.md. No MySQL acceptance yet. Older schema counts below are historical.

> **Latest source status (21 October 2026):** `app/Schema.php` is authoritative and currently lists 26 additive schema files, ending in `inventory-costing.sql`. FIFO COGS coverage is implemented but database/browser/concurrency acceptance is NOT complete; do not advertise as a final P&L or production-ready store. The older schema counts below are historical.

> Latest development source, 21 October 2026: 23 additive schemas, including a support inbox. Staff-only support attachments, operational reports and CSV export are source-implemented but NOT database/browser accepted. See `PROGRESS.md`.

> 21 October 2026: selected BulkSMSBD implementation and partial SEO 404 work are in source, not runtime accepted. Database schema count: 15; see PROGRESS.md and ../handoff/REQUIREMENT-COVERAGE-2026-10-21.md.

# AELVO PHP/MySQL main-project source

Current status and remaining scope: **PROGRESS.md**. This is unfinished internal development source, not a production release.

The PHP catalogue and initial COD checkout now use persistent database records. The older `dist/` preview remains separate. Read INSTALL.md and app/Schema.php for all sixteen ordered schemas. Current handoff: ../handoff/STATUS-AND-BACKLOG.md.

## Included

- `app/OwnerAiPolicy.php`: original-owner identity checks, verified-session expiry, allowed operation fields, permanent financial-action exclusion, canonical change digest.
- `app/OwnerAiService.php`: PDO-backed email challenges, resend/attempt limits, exact-proposal approval, single-use execution state and audit writes.
- `database/owner-ai.sql`: additive InnoDB tables for this subsystem only. Apply it after `database/core.sql`.
- `tests/policy.php`: standalone policy checks to run with `php cpanel/tests/policy.php`.

No PHP executable was available in the implementation environment. PHP syntax/runtime tests and MySQL concurrency tests have **not** run. JavaScript test results are not evidence that these PHP services work on the target host.

## Required integration before enabling Owner AI

1. Complete host testing of the new secure login/Owner setup, then integrate it with this subsystem. Load the actor from the server's authenticated session and database on every request. A role called Super Admin is insufficient. Keep the Owner ID and secrets outside `public_html`.
2. Add HTTPS-only secure, HttpOnly, SameSite sessions, session rotation at login and successful verification, CSRF-protected POST routes and no permissive CORS. The browser must never supply its own trusted actor or verification grant. Clear the old grant before issuing a new challenge and on logout, password/email change or Owner disablement. Store the successful `verify()` result only in the server session.
3. Send the code only to the already verified Owner email from the database. Supply a real SMTP adapter for the `sendEmail` closure. A successful SMTP submission is not proof of delivery. Codes expire after 5 minutes, are single use, allow 5 attempts, and have a 60-second resend interval / 5 requests per hour per Owner. The optional AI access password is a server-side `password_hash()` result, never the Gmail password.
4. The grant expires after 15 minutes and after 5 minutes of inactivity. Update `last_seen_at` only after successful authorized activity; background polling must not extend it. All proposals remain bound to the exact verification nonce and expire after at most 5 minutes.
5. Register and integration-test `TransactionalOwnerChange` adapters. Each must strictly validate its payload, lock the affected record, compare `baseVersion`, apply only the reviewed change, and use the supplied PDO transaction. No external side effects or implicit commits belong in these adapters. No adapters are registered in this bundle.
6. Build the Owner confirmation UI to show server-loaded operation, affected records, before/after values and digest. Approval is a separate explicit human action. Do not expose `approve` or `execute` to model tools. A changed payload/base version/session needs a new proposal and approval. Never auto-approve a model response.
7. Permanently exclude payment execution, payment approval, refunds, transfers, balance corrections and gateway configuration from Owner AI operations. Explanations are allowed. Do not introduce a generic SQL, shell or unrestricted settings tool that bypasses this boundary.
8. Provision the selected AI provider with server credentials and permission-scoped retrieval. Source context must exclude secrets, credentials, environment/config files and unrelated customer data. Untrusted source comments, chats and documents cannot change the policy.

## Live code changes: intentionally not executable yet

The service can represent an approval for an immutable staged artifact, but `execute()` rejects `code.release`. There is no live file writer or release runner in this bundle. A verified backup, isolated staging tests and rollback cannot be replaced with client-supplied booleans or an AI claim that tests passed.

The later release runner must:

- Resolve the staged artifact ID server-side and verify the reviewed SHA-256 and current source revision.
- Keep its authorization policy, secrets and financial handlers outside the assistant's writable boundary. Changing those files requires a separate manual deployment; otherwise an arbitrary code patch could bypass the financial prohibition.
- Make and verify a restorable backup outside the web root before touching live files.
- Run fixed, administrator-provisioned syntax, regression and security checks against staging; never execute model-supplied shell commands.
- Ask for new approval if the artifact or baseline changes. Use a release lock and journal, health check the new release, and restore the backup on failure. Test crash recovery and rollback on the actual host before enabling this feature.
- Record the Owner approval, artifact digest, test evidence, backup identifier, changed files and result without logging secrets.

## Release gates still open

PHP lint/policy tests; MySQL migration/transaction/concurrency tests; real email delivery; login/session/CSRF tests; replay/expiry/permission-revocation tests; business adapters; staging/backup/rollback runner; actual AI integration; full durable store backend and installer. The Owner AI screen remains locked and accepts no demo unlock code.

## Phase 1 implementation

- `database/core.sql`: durable users, original Owner identity, role grants, login throttles and audit records.
- `public/index.php`: token-protected Owner setup, database-backed login, logout and permission-gated dashboard.
- `app/Security.php`: strict server sessions, CSRF, prepared permission lookups, account/IP throttling, idle and absolute expiry. Sessions use PHP server storage, not database session storage.
- `bin/migrate.php`: CLI schema initialization; phpMyAdmin import is also supported.
- `tests/database.php`: development-database regression checks, transactionally rolled back.
- `INSTALL.md`: XAMPP and cPanel setup and manual acceptance checks.

The original storefront/Admin preview remains separate. PHP products, uploads, stock, orders and staff permissions now persist. Recovery, Owner email verification, AI wiring and other modules remain pending. No production deployment has been performed.


Owner AI review is not live automation: first verify the original Owner email with real TLS SMTP via Admin Settings, then request a distinct Owner AI code and re-enter the Owner password. The Owner-only page records exact draft text proposals and approvals without executing them. There is no provider-connected Owner model, arbitrary tool execution, live code release, or money movement. Final integration/acceptance still required.
