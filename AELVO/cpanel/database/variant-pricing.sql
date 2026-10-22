-- Variant pricing overrides are optional. Existing products inherit their unchanged base price.
-- Applied once by the versioned migration runner. Original order-item prices are immutable snapshots.
ALTER TABLE aelvo_variants
 ADD COLUMN price_override_minor BIGINT UNSIGNED NULL DEFAULT NULL,
 ADD COLUMN pricing_version INT UNSIGNED NOT NULL DEFAULT 1;
