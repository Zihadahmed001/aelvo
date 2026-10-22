-- Revoke stale staff sessions after role/status changes, including disable -> re-enable.
-- Owner has no staff revision and remains governed by existing owner protection.
CREATE TABLE IF NOT EXISTS aelvo_staff_session_revisions (
 user_id BIGINT UNSIGNED PRIMARY KEY,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 FOREIGN KEY (user_id) REFERENCES aelvo_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
