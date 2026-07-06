-- Lets staff author a document now and have it become visible to recipients
-- at a specific future date/time. NULL means "available immediately" (the
-- default, and the only behavior that existed before this migration).

ALTER TABLE documents ADD COLUMN available_at TEXT;
