-- Authenticated-account-only support updates, separate from private staff notes.
-- An anonymous ticket does not gain account ownership through phone or email matches.
CREATE TABLE IF NOT EXISTS aelvo_support_public_updates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(20) NOT NULL,
 body TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (ticket_id) REFERENCES aelvo_support_tickets(id),
 FOREIGN KEY (actor_id) REFERENCES aelvo_users(id),
 INDEX customer_updates(ticket_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
