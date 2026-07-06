-- Adds a short numeric "access code" to shares: a 6-digit code handed to the
-- recipient through a separate channel from the link itself (read aloud on a
-- call, texted separately, given in person). Knowing the share link alone is
-- not enough to view the document — the recipient also needs this code.
--
-- This sits alongside the existing token, it does not replace it. The link
-- format and the token column are unchanged.

ALTER TABLE shares ADD COLUMN access_code TEXT;

-- Backfill any shares that already existed before this migration ran, so
-- every row has a code going forward. New shares always get one from
-- application code (see public/share.php); this only matters for rows
-- inserted before this migration existed.
UPDATE shares
SET access_code = printf('%06d', ABS(RANDOM()) % 1000000)
WHERE access_code IS NULL;
