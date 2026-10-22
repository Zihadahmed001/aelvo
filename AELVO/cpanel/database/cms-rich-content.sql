-- Only images referenced by an explicitly published CMS revision are public.
-- Updating a draft alone never changes this allowlist.
CREATE TABLE IF NOT EXISTS aelvo_cms_published_media (
 page_id BIGINT UNSIGNED NOT NULL,
 media_id CHAR(32) NOT NULL,
 PRIMARY KEY (page_id, media_id),
 KEY cms_media_lookup (media_id),
 CONSTRAINT fk_cms_media_page FOREIGN KEY (page_id) REFERENCES aelvo_cms_pages(id) ON DELETE RESTRICT,
 CONSTRAINT fk_cms_media_file FOREIGN KEY (media_id) REFERENCES aelvo_media(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
