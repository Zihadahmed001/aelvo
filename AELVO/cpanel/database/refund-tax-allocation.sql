-- New proposals include an explicit tax share. Never rewrite historic refund amounts.
-- Historic taxable requests without a share must be separately reconciled.
CREATE TABLE IF NOT EXISTS aelvo_refund_tax_allocations (
 refund_request_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 tax_minor BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_aelvo_refund_tax_request FOREIGN KEY (refund_request_id) REFERENCES aelvo_refund_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
