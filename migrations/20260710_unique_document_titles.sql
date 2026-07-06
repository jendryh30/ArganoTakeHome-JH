-- Document titles must be unique, case-insensitively, so each document has
-- one unambiguous human-readable name (no "Q1 Report" next to "q1 report").
--
-- This is a backstop, not the primary UX: admin.php checks for a taken title
-- before inserting and shows a friendly error (the same pattern used for
-- scheduling validation). This index exists to guarantee the constraint
-- actually holds even under a race (two submissions for the same title at
-- once), which the app-level check-then-insert can't fully rule out on its
-- own.
CREATE UNIQUE INDEX idx_documents_title_nocase ON documents (title COLLATE NOCASE);
