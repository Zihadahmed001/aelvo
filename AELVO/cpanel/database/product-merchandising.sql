-- Merchant controlled merchandising, no automatic campaigns or invented labels.
-- Sale price applies ONLY to variants inheriting the base product price.
ALTER TABLE aelvo_products
 ADD COLUMN sale_price_minor BIGINT UNSIGNED NULL DEFAULT NULL AFTER price_minor,
 ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER sale_price_minor,
 ADD COLUMN is_new_arrival TINYINT(1) NOT NULL DEFAULT 0 AFTER is_featured,
 ADD INDEX merchandise_featured (status,is_featured,id),
 ADD INDEX merchandise_arrivals (status,is_new_arrival,id);

-- Default deny combined product markdown + coupon unless explicitly authorized.
ALTER TABLE aelvo_coupons
 ADD COLUMN allow_sale_stack TINYINT(1) NOT NULL DEFAULT 0 AFTER active;
