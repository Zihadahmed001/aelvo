-- Schema 16: durable same-site public paths and reserved redirect history.
-- Historical paths are never silently released for a different entity.
CREATE TABLE IF NOT EXISTS aelvo_public_routes (
 path VARCHAR(220) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 route_kind ENUM('current','redirect') NOT NULL,
 entity_type VARCHAR(24) NOT NULL,
 entity_id BIGINT UNSIGNED NULL,
 current_owner VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
 destination_path VARCHAR(220) CHARACTER SET ascii COLLATE ascii_bin NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX route_entity(entity_type,entity_id),
 INDEX route_dest(destination_path),
 CONSTRAINT fk_route_actor FOREIGN KEY(created_by) REFERENCES aelvo_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Backfill the existing slug column, including draft/archived products, so all
-- historical owner paths remain reserved. No existing product data is rewritten.
INSERT IGNORE INTO aelvo_public_routes(path,route_kind,entity_type,entity_id,current_owner)
SELECT CONCAT('product/',slug),'current','product',id,CONCAT('product:',id)
FROM aelvo_products;
