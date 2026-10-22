-- Distinct one-time email ownership proof. No schema modifications to existing user records.
CREATE TABLE IF NOT EXISTS aelvo_owner_email_challenges (
 owner_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 email VARCHAR(254) NOT NULL,
 window_start BIGINT NOT NULL DEFAULT 0,
 sends INT UNSIGNED NOT NULL DEFAULT 0,
 sent_at BIGINT NOT NULL DEFAULT 0,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 expires_at BIGINT NOT NULL DEFAULT 0,
 salt CHAR(32) NOT NULL,
 code_hash CHAR(64) NOT NULL,
 CONSTRAINT aelvo_email_owner_fk FOREIGN KEY (owner_id) REFERENCES aelvo_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
