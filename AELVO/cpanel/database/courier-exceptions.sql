-- Schema 32: evidence-only courier cancellation, failed delivery and return-to-sender exceptions.
-- No provider cancel API is invoked; evidence review does not modify order/stock/money.
CREATE TABLE IF NOT EXISTS aelvo_courier_exception_cases (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shipment_id BIGINT UNSIGNED NOT NULL,
 exception_type VARCHAR(40) NOT NULL,
 portal_reference VARCHAR(120) NOT NULL,
 notes VARCHAR(1500) NOT NULL,
 evidence_id CHAR(32) NOT NULL,
 request_key CHAR(64) NOT NULL UNIQUE,
 request_hash CHAR(64) NOT NULL,
 proposal_actor_id BIGINT UNSIGNED NOT NULL,
 state VARCHAR(20) NOT NULL DEFAULT 'Pending',
 review_actor_id BIGINT UNSIGNED NULL,
 review_notes VARCHAR(1500) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reviewed_at DATETIME NULL,
 FOREIGN KEY (shipment_id) REFERENCES aelvo_courier_shipments(id),
 FOREIGN KEY (evidence_id) REFERENCES aelvo_media(id),
 FOREIGN KEY (proposal_actor_id) REFERENCES aelvo_users(id),
 FOREIGN KEY (review_actor_id) REFERENCES aelvo_users(id),
 INDEX pending_by_shipment (shipment_id,state,id),
 INDEX recent_review (state,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Independent documentary clearance is possible only after provider later confirms full delivery.
-- A clearance records evidence; it does not initiate refund, payout, cancellation, or stock movement.
CREATE TABLE IF NOT EXISTS aelvo_courier_exception_clearances (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 case_id BIGINT UNSIGNED NOT NULL,
 portal_reference VARCHAR(120) NOT NULL,
 notes VARCHAR(1500) NOT NULL,
 evidence_id CHAR(32) NOT NULL,
 request_key CHAR(64) NOT NULL UNIQUE,
 request_hash CHAR(64) NOT NULL,
 proposal_actor_id BIGINT UNSIGNED NOT NULL,
 state VARCHAR(20) NOT NULL DEFAULT 'Pending',
 review_actor_id BIGINT UNSIGNED NULL,
 review_notes VARCHAR(1500) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reviewed_at DATETIME NULL,
 FOREIGN KEY (case_id) REFERENCES aelvo_courier_exception_cases(id),
 FOREIGN KEY (evidence_id) REFERENCES aelvo_media(id),
 FOREIGN KEY (proposal_actor_id) REFERENCES aelvo_users(id),
 FOREIGN KEY (review_actor_id) REFERENCES aelvo_users(id),
 INDEX clearance_queue (case_id,state,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
