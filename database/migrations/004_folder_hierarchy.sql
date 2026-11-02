ALTER TABLE public.folders
    ADD COLUMN IF NOT EXISTS parent_id INTEGER
        REFERENCES public.folders(id) ON DELETE CASCADE,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ALTER COLUMN name TYPE VARCHAR(255);

CREATE INDEX IF NOT EXISTS folders_owner_parent_idx
    ON public.folders(owner_id, parent_id);
