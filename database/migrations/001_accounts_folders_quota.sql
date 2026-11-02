ALTER TABLE users
    ADD COLUMN IF NOT EXISTS password_hash TEXT,
    ADD COLUMN IF NOT EXISTS storage_quota_bytes BIGINT NOT NULL DEFAULT 10737418240;

ALTER TABLE users ALTER COLUMN storage_quota_bytes SET DEFAULT 10737418240;
UPDATE users SET storage_quota_bytes = storage_limit WHERE storage_limit IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS users_email_case_insensitive_idx ON users (lower(email));

CREATE TABLE IF NOT EXISTS folders (
    id BIGSERIAL PRIMARY KEY,
    owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    parent_id BIGINT REFERENCES folders(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT folders_name_not_blank CHECK (length(trim(name)) > 0)
);

ALTER TABLE folders
    ADD COLUMN IF NOT EXISTS parent_id BIGINT REFERENCES folders(id) ON DELETE CASCADE,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ALTER COLUMN name TYPE VARCHAR(255);

CREATE INDEX IF NOT EXISTS folders_owner_parent_idx ON folders(owner_id, parent_id);

ALTER TABLE files
    ADD COLUMN IF NOT EXISTS folder_id BIGINT REFERENCES folders(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS files_owner_folder_idx ON files(owner_id, folder_id);
