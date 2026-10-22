-- Additional CMS pages are revisioned separately from fixed hero/popup/settings content.
-- A draft slug is never visible to the public or part of the URL registry until publish.
CREATE TABLE IF NOT EXISTS aelvo_cms_pages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 draft MEDIUMTEXT NOT NULL,
 published MEDIUMTEXT NULL,
 published_slug VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 published_version INT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX cms_published_slug(published_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
