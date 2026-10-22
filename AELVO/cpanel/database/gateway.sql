-- External gateway funds are a receivable until an independently verified bank/wallet settlement.
-- Live validation must NEVER directly mutate aelvo_accounts.
CREATE TABLE IF NOT EXISTS aelvo_gateway_attempts (
 id CHAR(32) PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 provider VARCHAR(25) NOT NULL DEFAULT 'sslcommerz',
 mode VARCHAR(10) NOT NULL,
 tran_id VARCHAR(30) NOT NULL UNIQUE,
 expected_minor BIGINT UNSIGNED NOT NULL,
 customer_email VARCHAR(254) NOT NULL,
 customer_city VARCHAR(80) NOT NULL,
 state VARCHAR(25) NOT NULL DEFAULT 'Initiating',
 session_key VARCHAR(120) NOT NULL DEFAULT '',
 gateway_url VARCHAR(800) NOT NULL DEFAULT '',
 val_id VARCHAR(60) NULL UNIQUE,
 bank_reference VARCHAR(120) NOT NULL DEFAULT '',
 provider_result TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES aelvo_orders(id),
 INDEX order_attempt(order_id,created_at), INDEX review_state(state,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_gateway_receipts (
 attempt_id CHAR(32) PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 amount_minor BIGINT UNSIGNED NOT NULL,
 val_id VARCHAR(60) NOT NULL UNIQUE,
 bank_reference VARCHAR(120) NOT NULL,
 confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(attempt_id) REFERENCES aelvo_gateway_attempts(id),
 FOREIGN KEY(order_id) REFERENCES aelvo_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_gateway_settlements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 attempt_id CHAR(32) NOT NULL,
 account_id BIGINT UNSIGNED NOT NULL,
 event_id CHAR(32) NOT NULL UNIQUE,
 received_minor BIGINT UNSIGNED NOT NULL,
 fee_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
 reference VARCHAR(120) NOT NULL,
 document_id CHAR(32) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(attempt_id) REFERENCES aelvo_gateway_receipts(attempt_id),
 FOREIGN KEY(account_id) REFERENCES aelvo_accounts(id),
 FOREIGN KEY(event_id) REFERENCES aelvo_finance_events(id),
 FOREIGN KEY(document_id) REFERENCES aelvo_media(id),
 FOREIGN KEY(actor_id) REFERENCES aelvo_users(id),
 INDEX by_receipt(attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
