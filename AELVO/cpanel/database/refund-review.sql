CREATE TABLE IF NOT EXISTS aelvo_refund_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL, return_id BIGINT UNSIGNED NULL,
 amount_minor BIGINT UNSIGNED NOT NULL, account_id BIGINT UNSIGNED NOT NULL,
 method VARCHAR(30) NOT NULL, destination VARCHAR(180) NOT NULL,
 reason TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'Pending',
 request_key CHAR(64) NOT NULL UNIQUE, request_hash CHAR(64) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL, reviewed_by BIGINT UNSIGNED NULL,
 review_note TEXT NULL, reviewed_at DATETIME NULL,
 refund_id BIGINT UNSIGNED NULL UNIQUE,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES aelvo_orders(id),
 FOREIGN KEY(return_id) REFERENCES aelvo_returns(id),
 FOREIGN KEY(account_id) REFERENCES aelvo_accounts(id),
 FOREIGN KEY(actor_id) REFERENCES aelvo_users(id),
 FOREIGN KEY(reviewed_by) REFERENCES aelvo_users(id),
 FOREIGN KEY(refund_id) REFERENCES aelvo_refunds(id),
 INDEX order_commitment(order_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_return_dispositions (
 return_id BIGINT UNSIGNED PRIMARY KEY, variant_id BIGINT UNSIGNED NOT NULL,
 sellable_qty INT UNSIGNED NOT NULL, damaged_qty INT UNSIGNED NOT NULL,
 inspection TEXT NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
 posted_at DATETIME NULL, posted_by BIGINT UNSIGNED NULL,
 FOREIGN KEY(return_id) REFERENCES aelvo_returns(id),
 FOREIGN KEY(variant_id) REFERENCES aelvo_variants(id),
 FOREIGN KEY(actor_id) REFERENCES aelvo_users(id),
 FOREIGN KEY(posted_by) REFERENCES aelvo_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
