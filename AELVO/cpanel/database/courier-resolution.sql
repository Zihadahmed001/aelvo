-- Additive schema 31: documentary review of an existing uncertain Steadfast attempt.
-- Never infer payment, fulfillment, stock return, or permission to book twice.
CREATE TABLE IF NOT EXISTS aelvo_courier_resolution_cases (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shipment_id BIGINT UNSIGNED NOT NULL,
 outcome VARCHAR(15) NOT NULL,
 consignment_id VARCHAR(60) NULL,
 tracking_code VARCHAR(100) NULL,
 evidence_id CHAR(32) NOT NULL,
 proposal_notes VARCHAR(1500) NOT NULL,
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
 INDEX pending_shipment (shipment_id,state,id),
 INDEX recent_resolutions (state,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
