-- Additive SEO 404 aggregation. Never store query strings, customer identifiers or IPs.
CREATE TABLE IF NOT EXISTS aelvo_seo_404 (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 path VARCHAR(320) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 hits INT UNSIGNED NOT NULL DEFAULT 1,
 first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reviewed_at DATETIME NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 UNIQUE KEY unique_path(path),
 INDEX recent_404(last_seen_at),
 FOREIGN KEY (reviewed_by) REFERENCES aelvo_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
