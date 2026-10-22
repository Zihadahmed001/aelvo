-- Additive Owner AI tables; apply after core.sql. Owner AI integration remains disabled.
CREATE TABLE IF NOT EXISTS aelvo_owner_ai_challenges (
 owner_id BIGINT UNSIGNED PRIMARY KEY, window_start BIGINT NOT NULL,
 sends INT NOT NULL, sent_at BIGINT NOT NULL, attempts INT NOT NULL,
 expires_at BIGINT NOT NULL, salt VARCHAR(64) NOT NULL, code_hash VARCHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_owner_ai_proposals (
 id CHAR(32) PRIMARY KEY, owner_id BIGINT UNSIGNED NOT NULL,
 grant_hash CHAR(64) NOT NULL, operation VARCHAR(80) NOT NULL,
 payload MEDIUMTEXT NOT NULL, base_version VARCHAR(128) NOT NULL,
 digest CHAR(64) NOT NULL, status VARCHAR(20) NOT NULL,
 expires_at BIGINT NOT NULL, result MEDIUMTEXT NULL,
 INDEX owner_expiry(owner_id,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS aelvo_owner_ai_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 owner_id BIGINT UNSIGNED NOT NULL,event VARCHAR(80) NOT NULL,
 proposal_id CHAR(32) NULL,created_at BIGINT NOT NULL,
 INDEX owner_time(owner_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
