-- Schema 26: additive FIFO landed-cost ledger. Never backfill unproven historical costs.
-- Costs are in BDT minor units, NULL means UNKNOWN (not zero).
CREATE TABLE IF NOT EXISTS aelvo_inventory_cost_layers (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 variant_id BIGINT UNSIGNED NOT NULL,
 origin_type VARCHAR(24) NOT NULL,
 origin_id VARCHAR(80) NOT NULL,
 received_qty INT UNSIGNED NOT NULL,
 received_cost_minor BIGINT UNSIGNED NULL,
 remaining_qty INT UNSIGNED NOT NULL,
 remaining_cost_minor BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY cost_origin(origin_type,origin_id),
 INDEX layer_fifo(variant_id,id),
 CONSTRAINT fk_cost_variant FOREIGN KEY (variant_id) REFERENCES aelvo_variants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_order_item_cost_postings (
 order_item_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 delivered_qty INT UNSIGNED NOT NULL,
 known_qty INT UNSIGNED NOT NULL,
 unknown_qty INT UNSIGNED NOT NULL,
 known_cost_minor BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_cost_posting_item FOREIGN KEY(order_item_id) REFERENCES aelvo_order_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_order_item_cost_allocations (
 order_item_id BIGINT UNSIGNED NOT NULL,
 layer_id BIGINT UNSIGNED NOT NULL,
 qty INT UNSIGNED NOT NULL,
 cost_minor BIGINT UNSIGNED NULL,
 PRIMARY KEY(order_item_id,layer_id),
 CONSTRAINT fk_cost_alloc_item FOREIGN KEY(order_item_id) REFERENCES aelvo_order_items(id),
 CONSTRAINT fk_cost_alloc_layer FOREIGN KEY(layer_id) REFERENCES aelvo_inventory_cost_layers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- An audited non-sale disposal reduces inventory value, NEVER counts as COGS.
CREATE TABLE IF NOT EXISTS aelvo_inventory_cost_disposals (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 variant_id BIGINT UNSIGNED NOT NULL,
 source_type VARCHAR(32) NOT NULL,
 source_id VARCHAR(80) NOT NULL,
 qty INT UNSIGNED NOT NULL,
 known_qty INT UNSIGNED NOT NULL,
 unknown_qty INT UNSIGNED NOT NULL,
 known_cost_minor BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY unique_cost_disposal(source_type,source_id),
 INDEX disposed_variant_time(variant_id,id),
 CONSTRAINT fk_cost_disposal_variant FOREIGN KEY(variant_id) REFERENCES aelvo_variants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_inventory_cost_disposal_lines (
 disposal_id BIGINT UNSIGNED NOT NULL,
 layer_id BIGINT UNSIGNED NOT NULL,
 qty INT UNSIGNED NOT NULL,
 cost_minor BIGINT UNSIGNED NULL,
 PRIMARY KEY(disposal_id,layer_id),
 CONSTRAINT fk_disposal_alloc_event FOREIGN KEY(disposal_id) REFERENCES aelvo_inventory_cost_disposals(id),
 CONSTRAINT fk_disposal_alloc_layer FOREIGN KEY(layer_id) REFERENCES aelvo_inventory_cost_layers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
