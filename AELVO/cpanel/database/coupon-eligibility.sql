-- Additive promotion eligibility, redemption snapshots and allocation for partial-return reconciliation.
-- Existing coupons keep unrestricted merchandise scope and existing redemption history.
ALTER TABLE aelvo_coupons
 ADD COLUMN scope_kind ENUM('all','product','category','brand') NOT NULL DEFAULT 'all' AFTER allow_sale_stack,
 ADD COLUMN scope_id BIGINT UNSIGNED NULL AFTER scope_kind,
 ADD COLUMN per_customer_limit INT UNSIGNED NULL AFTER scope_id,
 ADD COLUMN first_order_only TINYINT(1) NOT NULL DEFAULT 0 AFTER per_customer_limit,
 ADD COLUMN customer_id BIGINT UNSIGNED NULL AFTER first_order_only,
 ADD INDEX coupon_customer(customer_id),
 ADD CONSTRAINT fk_coupon_target_customer FOREIGN KEY(customer_id) REFERENCES aelvo_customers(id);
ALTER TABLE aelvo_coupon_redemptions
 ADD COLUMN shipping_discount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER discount_minor;
ALTER TABLE aelvo_order_items
 ADD COLUMN coupon_discount_minor BIGINT UNSIGNED NULL DEFAULT NULL AFTER unit_price_minor;
-- NULL for historical lines: legacy order-level coupon allocation is unknown.
-- For newly placed orders, the allocated coupon discount is an exact minor-unit snapshot.
