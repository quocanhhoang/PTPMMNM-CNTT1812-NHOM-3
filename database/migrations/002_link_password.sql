ALTER TABLE files
    ADD COLUMN IF NOT EXISTS link_password_hash TEXT;

CREATE INDEX IF NOT EXISTS files_active_public_links_idx
    ON files (owner_id, expires_at)
    WHERE access_type = 'public' AND is_link_active = TRUE;
