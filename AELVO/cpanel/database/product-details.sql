-- Optional factual merchandising fields. Legacy products and orders are unchanged.
CREATE TABLE aelvo_product_details (
 product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 parent_sku VARCHAR(80) NULL UNIQUE,
 barcode VARCHAR(80) NULL UNIQUE,
 short_en VARCHAR(600) NOT NULL DEFAULT '',
 short_bn VARCHAR(600) NOT NULL DEFAULT '',
 material_en VARCHAR(160) NOT NULL DEFAULT '',
 material_bn VARCHAR(160) NOT NULL DEFAULT '',
 care_en VARCHAR(1000) NOT NULL DEFAULT '',
 care_bn VARCHAR(1000) NOT NULL DEFAULT '',
 weight_grams INT UNSIGNED NULL,
 length_mm INT UNSIGNED NULL,
 width_mm INT UNSIGNED NULL,
 height_mm INT UNSIGNED NULL,
 tags_csv VARCHAR(800) NOT NULL DEFAULT '',
 low_stock_alert INT UNSIGNED NOT NULL DEFAULT 5,
 FOREIGN KEY(product_id) REFERENCES aelvo_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE aelvo_variants
 ADD COLUMN material VARCHAR(100) NOT NULL DEFAULT '',
 ADD COLUMN style VARCHAR(100) NOT NULL DEFAULT '',
 ADD COLUMN attributes_version INT UNSIGNED NOT NULL DEFAULT 1;
