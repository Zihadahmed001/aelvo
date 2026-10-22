-- Schema 20: additive catalogue taxonomy and public SEO pages.
-- Existing product category codes and product rows are kept intact.
CREATE TABLE IF NOT EXISTS aelvo_categories (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 slug VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 root_code VARCHAR(80) NOT NULL,
 parent_id BIGINT UNSIGNED NULL,
 name VARCHAR(180) NOT NULL,
 name_bn VARCHAR(180) NOT NULL DEFAULT '',
 description TEXT NOT NULL,
 description_bn TEXT NOT NULL,
 seo_title VARCHAR(180) NOT NULL DEFAULT '',
 canonical_path VARCHAR(220) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
 og_image_id CHAR(32) NULL,
 seo_description VARCHAR(600) NOT NULL DEFAULT '',
 og_title VARCHAR(180) NOT NULL DEFAULT '',
 og_description VARCHAR(600) NOT NULL DEFAULT '',
 index_mode ENUM('inherit','index','noindex') NOT NULL DEFAULT 'inherit',
 follow_mode ENUM('inherit','follow','nofollow') NOT NULL DEFAULT 'inherit',
 status ENUM('Active','Hidden') NOT NULL DEFAULT 'Active',
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX category_tree(parent_id,root_code,status),
 CONSTRAINT fk_category_parent FOREIGN KEY(parent_id) REFERENCES aelvo_categories(id),
 CONSTRAINT fk_category_og_image FOREIGN KEY(og_image_id) REFERENCES aelvo_media(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_brands (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 slug VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 name VARCHAR(180) NOT NULL,
 name_bn VARCHAR(180) NOT NULL DEFAULT '',
 description TEXT NOT NULL,
 description_bn TEXT NOT NULL,
 seo_title VARCHAR(180) NOT NULL DEFAULT '',
 canonical_path VARCHAR(220) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
 og_image_id CHAR(32) NULL,
 seo_description VARCHAR(600) NOT NULL DEFAULT '',
 og_title VARCHAR(180) NOT NULL DEFAULT '',
 og_description VARCHAR(600) NOT NULL DEFAULT '',
 index_mode ENUM('inherit','index','noindex') NOT NULL DEFAULT 'inherit',
 follow_mode ENUM('inherit','follow','nofollow') NOT NULL DEFAULT 'inherit',
 status ENUM('Active','Hidden') NOT NULL DEFAULT 'Active',
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_brand_og_image FOREIGN KEY(og_image_id) REFERENCES aelvo_media(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_product_taxonomy (
 product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 category_id BIGINT UNSIGNED NOT NULL,
 brand_id BIGINT UNSIGNED NULL,
 FOREIGN KEY(product_id) REFERENCES aelvo_products(id),
 FOREIGN KEY(category_id) REFERENCES aelvo_categories(id),
 FOREIGN KEY(brand_id) REFERENCES aelvo_brands(id),
 INDEX products_by_category(category_id),
 INDEX products_by_brand(brand_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Legacy five category families are stable. Subcategories get a unique slug and a root_code.
INSERT INTO aelvo_categories(slug,root_code,name,name_bn,description,description_bn)
 SELECT seed.slug,seed.root_code,seed.name,seed.name_bn,'',''
 FROM (
  SELECT 'shoes' slug,'Shoes' root_code,'Shoes' name,'জুতা' name_bn
  UNION ALL SELECT 'socks','Socks','Socks','মোজা'
  UNION ALL SELECT 'belts','Belts','Belts','বেল্ট'
  UNION ALL SELECT 'wallets','Wallets','Wallets','ওয়ালেট'
  UNION ALL SELECT 'bags','Bags','Bags','ব্যাগ'
 ) seed
 WHERE NOT EXISTS (SELECT 1 FROM aelvo_categories c WHERE c.root_code=seed.root_code AND c.parent_id IS NULL);
-- Backfill existing products without altering their name, price, stock or original category.
INSERT IGNORE INTO aelvo_product_taxonomy(product_id,category_id)
 SELECT p.id,c.id FROM aelvo_products p JOIN aelvo_categories c
  ON c.root_code=p.category AND c.parent_id IS NULL;
-- Reserve current category/brand paths, including hidden ones; historical aliases must not be reused.
INSERT IGNORE INTO aelvo_public_routes(path,route_kind,entity_type,entity_id,current_owner)
 SELECT CONCAT('category/',slug),'current','category',id,CONCAT('category:',id) FROM aelvo_categories;
INSERT IGNORE INTO aelvo_public_routes(path,route_kind,entity_type,entity_id,current_owner)
 SELECT CONCAT('brand/',slug),'current','brand',id,CONCAT('brand:',id) FROM aelvo_brands;
