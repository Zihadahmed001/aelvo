-- Schema 19 — additive damaged/quarantine units and append-only disposition events.
-- Stock remains physical on-hand; sellable available = stock - reserved - quarantined.
CREATE TABLE IF NOT EXISTS aelvo_variant_quarantine (
 variant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 quantity INT UNSIGNED NOT NULL DEFAULT 0,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_quarantine_variant FOREIGN KEY(variant_id) REFERENCES aelvo_variants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_quarantine_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 variant_id BIGINT UNSIGNED NOT NULL,
 request_key CHAR(64) NOT NULL UNIQUE,
 request_hash CHAR(64) NOT NULL,
 action ENUM('hold','release','writeoff') NOT NULL,
 qty INT UNSIGNED NOT NULL,
 explanation TEXT NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_quarantine_event_variant FOREIGN KEY(variant_id) REFERENCES aelvo_variants(id),
 CONSTRAINT fk_quarantine_event_actor FOREIGN KEY(actor_id) REFERENCES aelvo_users(id),
 INDEX variant_event(variant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Zero-only backfill for preexisting variants. Never overwrites an actual hold balance.
INSERT IGNORE INTO aelvo_variant_quarantine(variant_id,quantity)
 SELECT v.id,0 FROM aelvo_variants v;
