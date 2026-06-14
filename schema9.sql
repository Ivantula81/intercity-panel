-- v10: чаты — отметка прочтения входящих
ALTER TABLE inbox ADD COLUMN IF NOT EXISTS is_read TINYINT NOT NULL DEFAULT 0;
ALTER TABLE inbox ADD KEY IF NOT EXISTS idx_unread (is_read);
