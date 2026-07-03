-- Argano Dispatch — initial schema.
--
-- This is the baseline schema. Anything you add for the take-home should go
-- through a migration file, not by editing this file. (See README → Requirements.)

CREATE TABLE staff (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    email       TEXT    NOT NULL UNIQUE,
    name        TEXT    NOT NULL,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE documents (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL,
    body        TEXT    NOT NULL,
    created_by  INTEGER NOT NULL REFERENCES staff(id),
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE shares (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id      INTEGER NOT NULL REFERENCES documents(id),
    token            TEXT    NOT NULL UNIQUE,
    recipient_email  TEXT    NOT NULL,
    created_at       TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE audit_log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    staff_id     INTEGER REFERENCES staff(id),
    action       TEXT    NOT NULL,
    entity_type  TEXT,
    entity_id    INTEGER,
    details      TEXT,
    created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
);
