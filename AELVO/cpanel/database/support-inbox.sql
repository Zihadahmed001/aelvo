-- Private, durable contact / help / bug reports. No phone-to-account ownership inference.
CREATE TABLE IF NOT EXISTS aelvo_support_tickets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 request_key CHAR(64) NOT NULL UNIQUE, request_hash CHAR(64) NOT NULL,
 customer_id BIGINT UNSIGNED NULL,
 topic VARCHAR(24) NOT NULL, customer_name VARCHAR(120) NOT NULL,
 email VARCHAR(254) NOT NULL DEFAULT '', phone VARCHAR(20) NOT NULL DEFAULT '',
 subject VARCHAR(180) NOT NULL, message TEXT NOT NULL,
 order_reference VARCHAR(40) NOT NULL DEFAULT '',
 status VARCHAR(20) NOT NULL DEFAULT 'Open', version INT UNSIGNED NOT NULL DEFAULT 1,
 assigned_to BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (customer_id) REFERENCES aelvo_customers(id),
 FOREIGN KEY (assigned_to) REFERENCES aelvo_users(id),
 INDEX support_queue(status,created_at,id),
 INDEX support_customer(customer_id,created_at,id),
 INDEX support_topic(topic,created_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_support_attachments (
 id CHAR(32) PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 disk_name VARCHAR(100) NOT NULL UNIQUE,
 mime VARCHAR(40) NOT NULL,
 bytes INT UNSIGNED NOT NULL,
 original_name VARCHAR(180) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (ticket_id) REFERENCES aelvo_support_tickets(id),
 INDEX ticket_attachment(ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_support_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NULL,
 status VARCHAR(20) NOT NULL,
 note TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (ticket_id) REFERENCES aelvo_support_tickets(id),
 FOREIGN KEY (actor_id) REFERENCES aelvo_users(id),
 INDEX ticket_events(ticket_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
