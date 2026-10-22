-- Customer-owned cancellation requests, not automatic cancellation approvals.
-- Real order status and stock/payment effects remain with the existing Orders service.
CREATE TABLE IF NOT EXISTS aelvo_order_cancellation_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL UNIQUE,
 customer_id BIGINT UNSIGNED NOT NULL,
 reason TEXT NOT NULL,
 request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state ENUM('Pending','Declined') NOT NULL DEFAULT 'Pending',
 staff_note TEXT NOT NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 reviewed_at DATETIME NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (order_id) REFERENCES aelvo_orders(id),
 FOREIGN KEY (customer_id) REFERENCES aelvo_customers(id),
 FOREIGN KEY (reviewed_by) REFERENCES aelvo_users(id),
 INDEX cancellation_work_queue(state,id),
 INDEX cancellation_customer(customer_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_order_cancellation_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(24) NOT NULL,
 actor_customer_id BIGINT UNSIGNED NULL,
 actor_staff_id BIGINT UNSIGNED NULL,
 note TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (request_id) REFERENCES aelvo_order_cancellation_requests(id),
 FOREIGN KEY (actor_customer_id) REFERENCES aelvo_customers(id),
 FOREIGN KEY (actor_staff_id) REFERENCES aelvo_users(id),
 INDEX cancellation_history(request_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
