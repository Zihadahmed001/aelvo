-- Schema 18 — additive supplier directory, purchase landed-cost snapshots and verified payouts.
-- Existing text-only purchases stay intact and do not acquire fictitious suppliers/payments.
CREATE TABLE IF NOT EXISTS aelvo_suppliers (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(180) NOT NULL,
 company VARCHAR(180) NOT NULL DEFAULT '',
 phone VARCHAR(40) NOT NULL DEFAULT '',
 email VARCHAR(254) NOT NULL DEFAULT '',
 address TEXT NOT NULL,
 notes TEXT NOT NULL,
 active TINYINT UNSIGNED NOT NULL DEFAULT 1,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX supplier_name(name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_purchase_suppliers (
 purchase_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 supplier_id BIGINT UNSIGNED NOT NULL,
 CONSTRAINT fk_purchase_supplier_purchase FOREIGN KEY (purchase_id) REFERENCES aelvo_purchases(id),
 CONSTRAINT fk_purchase_supplier_directory FOREIGN KEY (supplier_id) REFERENCES aelvo_suppliers(id),
 INDEX supplier_purchases(supplier_id,purchase_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_purchase_charges (
 purchase_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 items_minor BIGINT UNSIGNED NOT NULL,
 shipping_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
 other_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
 discount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
 CONSTRAINT fk_purchase_charge_purchase FOREIGN KEY (purchase_id) REFERENCES aelvo_purchases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Landed total is recorded for each ordered item line, not a misleading single rounded unit cost.
CREATE TABLE IF NOT EXISTS aelvo_purchase_landed_items (
 purchase_item_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 landed_total_minor BIGINT UNSIGNED NOT NULL,
 CONSTRAINT fk_landed_item FOREIGN KEY (purchase_item_id) REFERENCES aelvo_purchase_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_supplier_payments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 purchase_id BIGINT UNSIGNED NOT NULL,
 account_id BIGINT UNSIGNED NOT NULL,
 finance_event_id CHAR(32) NOT NULL UNIQUE,
 amount_minor BIGINT UNSIGNED NOT NULL,
 reference VARCHAR(120) NOT NULL UNIQUE,
 document_id CHAR(32) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_supplier_payment_purchase FOREIGN KEY(purchase_id) REFERENCES aelvo_purchases(id),
 CONSTRAINT fk_supplier_payment_account FOREIGN KEY(account_id) REFERENCES aelvo_accounts(id),
 CONSTRAINT fk_supplier_payment_event FOREIGN KEY(finance_event_id) REFERENCES aelvo_finance_events(id),
 CONSTRAINT fk_supplier_payment_document FOREIGN KEY(document_id) REFERENCES aelvo_media(id),
 CONSTRAINT fk_supplier_payment_actor FOREIGN KEY(actor_id) REFERENCES aelvo_users(id),
 INDEX purchase_payments(purchase_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
