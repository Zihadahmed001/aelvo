CREATE TABLE IF NOT EXISTS aelvo_wishlist (
 customer_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(customer_id,product_id),
 FOREIGN KEY(customer_id) REFERENCES aelvo_customers(id),
 FOREIGN KEY(product_id) REFERENCES aelvo_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_customer_return_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 customer_id BIGINT UNSIGNED NOT NULL, order_item_id BIGINT UNSIGNED NOT NULL,
 qty INT UNSIGNED NOT NULL, reason TEXT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'Pending', version INT UNSIGNED NOT NULL DEFAULT 1,
 request_key CHAR(64) NOT NULL UNIQUE, request_hash CHAR(64) NOT NULL,
 review_note TEXT NOT NULL, reviewed_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, reviewed_at DATETIME NULL,
 FOREIGN KEY(customer_id) REFERENCES aelvo_customers(id),
 FOREIGN KEY(order_item_id) REFERENCES aelvo_order_items(id),
 FOREIGN KEY(reviewed_by) REFERENCES aelvo_users(id),
 INDEX customer_requests(customer_id,id), INDEX item_requests(order_item_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_return_request_photos (
 id CHAR(32) PRIMARY KEY, request_id BIGINT UNSIGNED NOT NULL,
 disk_name VARCHAR(100) NOT NULL UNIQUE, mime VARCHAR(80) NOT NULL,
 bytes BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(request_id) REFERENCES aelvo_customer_return_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_return_request_receipts (
 return_id BIGINT UNSIGNED PRIMARY KEY, request_id BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(return_id) REFERENCES aelvo_returns(id),
 FOREIGN KEY(request_id) REFERENCES aelvo_customer_return_requests(id), INDEX request_receipts(request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_return_request_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, request_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(20) NOT NULL, note TEXT NOT NULL,
 actor_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(request_id) REFERENCES aelvo_customer_return_requests(id),
 FOREIGN KEY(actor_id) REFERENCES aelvo_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_customer_bag (
 customer_id BIGINT UNSIGNED NOT NULL, variant_id BIGINT UNSIGNED NOT NULL,
 qty INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(customer_id,variant_id), FOREIGN KEY(customer_id) REFERENCES aelvo_customers(id),
 FOREIGN KEY(variant_id) REFERENCES aelvo_variants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_bag_merges (
 customer_id BIGINT UNSIGNED NOT NULL, session_hash CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(customer_id,session_hash), FOREIGN KEY(customer_id) REFERENCES aelvo_customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_review_photos (
 id CHAR(32) PRIMARY KEY, review_id BIGINT UNSIGNED NOT NULL,
 disk_name VARCHAR(100) NOT NULL UNIQUE, mime VARCHAR(80) NOT NULL,
 bytes BIGINT UNSIGNED NOT NULL, FOREIGN KEY(review_id) REFERENCES aelvo_reviews(id),
 INDEX review_photos(review_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
