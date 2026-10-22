-- Schema 34: one bank/wallet credit matched to multiple independently verified gateway charges.
-- Legacy single-attempt settlements stay untouched. A batch creates exactly one cash ledger event.
CREATE TABLE IF NOT EXISTS aelvo_gateway_payout_batches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 account_id BIGINT UNSIGNED NOT NULL,
 event_id CHAR(32) NOT NULL UNIQUE,
 bank_reference VARCHAR(120) NOT NULL,
 bank_reference_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 credit_minor BIGINT UNSIGNED NOT NULL,
 fee_minor BIGINT UNSIGNED NOT NULL,
 document_id CHAR(32) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(account_id) REFERENCES aelvo_accounts(id),
 FOREIGN KEY(event_id) REFERENCES aelvo_finance_events(id),
 FOREIGN KEY(document_id) REFERENCES aelvo_media(id),
 FOREIGN KEY(actor_id) REFERENCES aelvo_users(id),
 UNIQUE KEY bank_line_once(account_id,bank_reference_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_gateway_payout_allocations (
 payout_id BIGINT UNSIGNED NOT NULL,
 attempt_id CHAR(32) NOT NULL,
 credited_minor BIGINT UNSIGNED NOT NULL,
 fee_minor BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(payout_id,attempt_id),
 INDEX attempt_payout(attempt_id),
 FOREIGN KEY(payout_id) REFERENCES aelvo_gateway_payout_batches(id),
 FOREIGN KEY(attempt_id) REFERENCES aelvo_gateway_receipts(attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
