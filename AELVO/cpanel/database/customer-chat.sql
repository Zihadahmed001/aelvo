-- Website chat only. External WhatsApp/Messenger channels are not enabled by this schema.
-- A chat's guest identity never proves customer/order ownership.
CREATE TABLE IF NOT EXISTS aelvo_chat_threads (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 channel VARCHAR(20) NOT NULL DEFAULT 'website',
 customer_id BIGINT UNSIGNED NULL,
 guest_key_hash CHAR(64) NULL,
 mode VARCHAR(12) NOT NULL DEFAULT 'Human',
 status VARCHAR(12) NOT NULL DEFAULT 'Open',
 ai_opt_in TINYINT UNSIGNED NOT NULL DEFAULT 0,
 assigned_to BIGINT UNSIGNED NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 generation_token CHAR(32) NULL,
 generation_started_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (customer_id) REFERENCES aelvo_customers(id),
 FOREIGN KEY (assigned_to) REFERENCES aelvo_users(id),
 INDEX (channel,status,updated_at),
 INDEX (customer_id,id),
 INDEX (guest_key_hash,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_chat_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 thread_id BIGINT UNSIGNED NOT NULL,
 sender VARCHAR(12) NOT NULL,
 actor_id BIGINT UNSIGNED NULL,
 client_key CHAR(64) NULL,
 body TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (thread_id) REFERENCES aelvo_chat_threads(id),
 FOREIGN KEY (actor_id) REFERENCES aelvo_users(id),
 UNIQUE KEY replay_guard (thread_id,client_key),
 INDEX thread_timeline(thread_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_chat_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 thread_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NULL,
 event VARCHAR(40) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(thread_id) REFERENCES aelvo_chat_threads(id),
 FOREIGN KEY(actor_id) REFERENCES aelvo_users(id),
 INDEX(thread_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
