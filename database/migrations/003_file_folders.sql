CREATE TABLE IF NOT EXISTS public.folders (
    id SERIAL PRIMARY KEY,
    owner_id INTEGER NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
    name VARCHAR(80) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT folders_owner_name_unique UNIQUE (owner_id, name)
);

ALTER TABLE public.files
    ADD COLUMN IF NOT EXISTS folder_id INTEGER
    REFERENCES public.folders(id) ON DELETE SET NULL;

INSERT INTO public.folders (owner_id, name)
SELECT u.id, 'Tệp của tôi'
FROM public.users u
WHERE NOT EXISTS (
    SELECT 1
    FROM public.folders f
    WHERE f.owner_id = u.id AND f.name = 'Tệp của tôi'
);

UPDATE public.files f
SET folder_id = folders.id
FROM public.folders
WHERE folders.owner_id = f.owner_id
  AND folders.name = 'Tệp của tôi'
  AND f.folder_id IS NULL;
