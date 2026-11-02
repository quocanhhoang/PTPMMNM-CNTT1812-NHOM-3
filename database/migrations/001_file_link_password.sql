ALTER TABLE public.files
    ADD COLUMN IF NOT EXISTS link_password_hash TEXT;
