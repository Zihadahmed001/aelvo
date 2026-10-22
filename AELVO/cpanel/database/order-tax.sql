-- Preserve all legacy order values; previously created orders had no configured tax.
ALTER TABLE aelvo_orders ADD COLUMN tax_minor BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER delivery_minor;
CREATE TABLE IF NOT EXISTS aelvo_order_tax_snapshots (
 order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 mode VARCHAR(16) NOT NULL,
 rate_basis_points SMALLINT UNSIGNED NOT NULL,
 shipping_taxable TINYINT(1) NOT NULL,
 taxable_merchandise_minor BIGINT UNSIGNED NOT NULL,
 taxable_shipping_minor BIGINT UNSIGNED NOT NULL,
 tax_minor BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_aelvo_tax_order FOREIGN KEY (order_id) REFERENCES aelvo_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
