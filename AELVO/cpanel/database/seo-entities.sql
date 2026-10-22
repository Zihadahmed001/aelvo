-- Schema 17: persisted product SEO overrides, separate from generated defaults.
-- This migration is additive; no existing product data or historical URLs are rewritten.
CREATE TABLE IF NOT EXISTS aelvo_product_seo (
 product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 title VARCHAR(180) NOT NULL DEFAULT '',
 description VARCHAR(600) NOT NULL DEFAULT '',
 canonical_path VARCHAR(220) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
 og_title VARCHAR(180) NOT NULL DEFAULT '',
 og_description VARCHAR(600) NOT NULL DEFAULT '',
 og_image_id CHAR(32) NULL,
 index_mode ENUM('inherit','index','noindex') NOT NULL DEFAULT 'inherit',
 follow_mode ENUM('inherit','follow','nofollow') NOT NULL DEFAULT 'inherit',
 version INT UNSIGNED NOT NULL DEFAULT 1,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_product_seo_product FOREIGN KEY(product_id) REFERENCES aelvo_products(id),
 CONSTRAINT fk_product_seo_media FOREIGN KEY(og_image_id) REFERENCES aelvo_media(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ALT data belongs to its media assignment, not just the reusable asset.
CREATE TABLE IF NOT EXISTS aelvo_product_image_alt (
 product_id BIGINT UNSIGNED NOT NULL,
 media_id CHAR(32) NOT NULL,
 alt_en VARCHAR(255) NOT NULL DEFAULT '',
 alt_bn VARCHAR(255) NOT NULL DEFAULT '',
 version INT UNSIGNED NOT NULL DEFAULT 1,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(product_id,media_id),
 CONSTRAINT fk_product_alt_assignment FOREIGN KEY(product_id,media_id)
   REFERENCES aelvo_product_media(product_id,media_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
