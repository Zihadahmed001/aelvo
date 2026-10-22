-- Schema 24: one immutable remote-booking attempt per order; never retry an uncertain request.
-- Shipment rows represent courier intent, NOT delivery, payment, or courier remittance.
CREATE TABLE IF NOT EXISTS aelvo_courier_shipments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL UNIQUE,
 provider VARCHAR(30) NOT NULL DEFAULT 'steadfast',
 invoice VARCHAR(40) NOT NULL UNIQUE,
 cod_minor BIGINT UNSIGNED NOT NULL,
 recipient_name VARCHAR(120) NOT NULL,
 recipient_phone VARCHAR(20) NOT NULL,
 recipient_address VARCHAR(250) NOT NULL,
 booking_state VARCHAR(30) NOT NULL DEFAULT 'Prepared',
 consignment_id VARCHAR(60) NULL UNIQUE,
 tracking_code VARCHAR(100) NULL,
 remote_status VARCHAR(80) NOT NULL DEFAULT '',
 last_error VARCHAR(200) NOT NULL DEFAULT '',
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES aelvo_orders(id),
 FOREIGN KEY(created_by) REFERENCES aelvo_users(id),
 INDEX courier_queue(booking_state,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_courier_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shipment_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(30) NOT NULL,
 remote_status VARCHAR(80) NOT NULL DEFAULT '',
 detail VARCHAR(200) NOT NULL DEFAULT '',
 actor_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(shipment_id) REFERENCES aelvo_courier_shipments(id),
 FOREIGN KEY(actor_id) REFERENCES aelvo_users(id),
 INDEX shipment_timeline(shipment_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Courier collection is NOT cash held by AELVO. A human verifies documentary evidence.
CREATE TABLE IF NOT EXISTS aelvo_courier_collections (
 shipment_id BIGINT UNSIGNED PRIMARY KEY,
 collected_minor BIGINT UNSIGNED NOT NULL,
 reference VARCHAR(120) NOT NULL UNIQUE,
 document_id CHAR(32) NOT NULL,
 request_key CHAR(64) NOT NULL UNIQUE,
 request_hash CHAR(64) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(shipment_id) REFERENCES aelvo_courier_shipments(id),
 FOREIGN KEY(document_id) REFERENCES aelvo_media(id),
 FOREIGN KEY(actor_id) REFERENCES aelvo_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Each independently verified bank/wallet credit is posted once to a real AELVO account.
-- Courier fee is withheld from collection; it must never be credited as bank cash.
CREATE TABLE IF NOT EXISTS aelvo_courier_remittances (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shipment_id BIGINT UNSIGNED NOT NULL,
 account_id BIGINT UNSIGNED NOT NULL,
 finance_event_id CHAR(32) NOT NULL UNIQUE,
 remitted_minor BIGINT UNSIGNED NOT NULL,
 fee_minor BIGINT UNSIGNED NOT NULL,
 reference VARCHAR(120) NOT NULL UNIQUE,
 document_id CHAR(32) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(shipment_id) REFERENCES aelvo_courier_shipments(id),
 FOREIGN KEY(account_id) REFERENCES aelvo_accounts(id),
 FOREIGN KEY(finance_event_id) REFERENCES aelvo_finance_events(id),
 FOREIGN KEY(document_id) REFERENCES aelvo_media(id),
 FOREIGN KEY(actor_id) REFERENCES aelvo_users(id),
 INDEX courier_remittance(shipment_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
