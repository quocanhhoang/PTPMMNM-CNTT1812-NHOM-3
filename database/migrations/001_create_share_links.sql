CREATE TABLE IF NOT EXISTS share_links (
    id BIGSERIAL PRIMARY KEY,
    file_id INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
    token CHAR(64) NOT NULL UNIQUE,
    password_hash TEXT,
    download_limit INTEGER NOT NULL CHECK (download_limit > 0),
    download_count INTEGER NOT NULL DEFAULT 0 CHECK (download_count >= 0),
    expires_at TIMESTAMPTZ NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    failed_attempts INTEGER NOT NULL DEFAULT 0 CHECK (failed_attempts >= 0),
    locked_until TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS share_links_active_expiry_idx
    ON share_links (expires_at)
    WHERE is_active = TRUE;

CREATE INDEX IF NOT EXISTS share_links_file_id_idx
    ON share_links (file_id);
