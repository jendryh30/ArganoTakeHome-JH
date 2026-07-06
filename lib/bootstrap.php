<?php

declare(strict_types=1);

date_default_timezone_set('America/Chicago');

const DB_PATH = __DIR__ . '/../db.sqlite';

/**
 * Lazily-built PDO singleton pointed at the local SQLite file.
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

/**
 * The "logged-in" staff member. There is no real auth in this exercise —
 * staff #1 is whoever the seed script created. Replace if you add real auth.
 */
function current_staff(): array {
    $row = db()
        ->query('SELECT * FROM staff WHERE id = 1')
        ->fetch();

    if (!$row) {
        throw new RuntimeException(
            'No staff row #1 found. Did the seed script run? (`php seed.php`)'
        );
    }
    return $row;
}

/**
 * Append a row to audit_log. `details` is JSON-encoded so callers can stash
 * arbitrary structured context (recipient email, prior value, etc.).
 */
function audit_log(
    string $action,
    string $entity_type,
    int $entity_id,
    array $details = []
): void {
    $staff = current_staff();
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (:staff_id, :action, :entity_type, :entity_id, :details)
    ');
    $stmt->execute([
        ':staff_id'    => $staff['id'],
        ':action'      => $action,
        ':entity_type' => $entity_type,
        ':entity_id'   => $entity_id,
        ':details'     => json_encode($details, JSON_UNESCAPED_SLASHES),
    ]);
}

/**
 * Same as audit_log(), but for actions taken by an unauthenticated recipient
 * on a share link (e.g. a wrong access code) rather than a signed-in staff
 * member. staff_id is left NULL — schema.sql already allows this
 * (`staff_id INTEGER REFERENCES staff(id)`, no NOT NULL), audit_log() just
 * never had a caller that needed it before there was any recipient-facing
 * action worth recording.
 */
function audit_log_anonymous(
    string $action,
    string $entity_type,
    int $entity_id,
    array $details = []
): void {
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (NULL, :action, :entity_type, :entity_id, :details)
    ');
    $stmt->execute([
        ':action'      => $action,
        ':entity_type' => $entity_type,
        ':entity_id'   => $entity_id,
        ':details'     => json_encode($details, JSON_UNESCAPED_SLASHES),
    ]);
}

/** Cryptographically-random hex token, defaults to 32 chars (16 bytes). */
function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

/**
 * Cryptographically-random 6-digit numeric access code, zero-padded (e.g.
 * "004821"). Meant to be handed to a recipient through a channel separate
 * from the share link itself (read aloud on a call, texted, given in
 * person) — the link alone shouldn't be enough to view the document.
 */
function random_access_code(): string {
    return sprintf('%06d', random_int(0, 999999));
}

/**
 * Timing-safe comparison of a submitted access code against the one stored
 * on a share. Trims the submission (users copy/paste these) but does not
 * relax anything else — a code is either the exact 6 digits or it isn't.
 */
function access_code_matches(?string $stored, string $submitted): bool {
    $stored = (string) $stored;
    $submitted = trim($submitted);
    if ($stored === '' || $submitted === '') {
        return false;
    }
    return hash_equals($stored, $submitted);
}

/** True if a document has no schedule, or its schedule has already passed. */
function doc_is_available(array $doc, ?string $now = null): bool {
    $now ??= date('Y-m-d H:i:s');
    return empty($doc['available_at']) || $doc['available_at'] <= $now;
}

/** @return array{label: string, class: string} Presentation info for the admin documents table. */
function doc_status(array $doc, ?string $now = null): array {
    if (doc_is_available($doc, $now)) {
        return ['label' => 'Available', 'class' => 'status-live'];
    }
    return [
        'label' => 'Not available yet · ' . $doc['available_at'],
        'class' => 'status-scheduled',
    ];
}

/**
 * Parses and validates the "available from" form field. Blank input is
 * valid (means "available immediately"). Anything that doesn't parse, or
 * that parses to a moment at-or-before now, is rejected — schedules must be
 * strictly in the future.
 *
 * @return array{value: ?string, error: ?string}
 */
function parse_available_at(string $raw, ?string $now = null): array {
    $raw = trim($raw);
    if ($raw === '') {
        return ['value' => null, 'error' => null];
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return ['value' => null, 'error' => 'That availability date/time could not be understood.'];
    }

    $value = date('Y-m-d H:i:s', $ts);
    $now ??= date('Y-m-d H:i:s');
    if ($value <= $now) {
        return ['value' => null, 'error' => 'Availability date/time must be in the future.'];
    }

    return ['value' => $value, 'error' => null];
}

/**
 * Whitelisted sortable columns for the admin documents table. Keys are the
 * `sort=` query values; values are the actual ORDER BY expression.
 *
 * `status` sorts by `d.available_at` directly rather than the rendered
 * label: NULL (available immediately) sorts before any future timestamp in
 * SQLite's default ASC ordering, so ascending groups "Available" documents
 * first, then upcoming schedules soonest-first; descending reverses that.
 * There's no third state to break ties on, so this is just the natural
 * ordering of the one column status is actually derived from.
 */
const DOC_SORT_COLUMNS = [
    'id'      => 'd.id',
    'title'   => 'd.title COLLATE NOCASE',
    'creator' => 's.name COLLATE NOCASE',
    'created' => 'd.created_at',
    'status'  => 'd.available_at',
];

/** Normalizes a requested sort column against the whitelist, defaulting to 'created'. */
function doc_sort_column(string $requested): string {
    return array_key_exists($requested, DOC_SORT_COLUMNS) ? $requested : 'created';
}

/** Normalizes a requested sort direction, defaulting to 'desc'. */
function doc_sort_direction(string $requested): string {
    return strtolower($requested) === 'asc' ? 'asc' : 'desc';
}

/**
 * Builds an <a> tag for a sortable column header: clicking it sorts by that
 * column, flipping direction if it's already the active sort, and always
 * preserving the current search term.
 *
 * Both the up and down arrows are always shown (so every sortable column
 * looks the same at rest), with whichever one matches the *active* sort
 * direction for *this* column highlighted via .sort-arrow-active. Neither
 * arrow is highlighted for a column that isn't the current sort.
 */
function doc_sort_link(string $column, string $label, string $activeSort, string $activeDir, string $q): string {
    $isActive = $activeSort === $column;
    $nextDir  = ($isActive && $activeDir === 'asc') ? 'desc' : 'asc';
    $url      = '/admin.php?' . http_build_query(['q' => $q, 'sort' => $column, 'dir' => $nextDir]);

    $upClass   = 'sort-arrow' . ($isActive && $activeDir === 'asc' ? ' sort-arrow-active' : '');
    $downClass = 'sort-arrow' . ($isActive && $activeDir === 'desc' ? ' sort-arrow-active' : '');

    return '<a href="' . h($url) . '" class="sort-link">'
        . h($label)
        . ' <span class="sort-arrows">'
        . '<span class="' . h($upClass) . '">↑</span>'
        . '<span class="' . h($downClass) . '">↓</span>'
        . '</span>'
        . '</a>';
}

/**
 * Runs the actual search+sort query behind the admin documents table.
 * Pulled out of admin.php so it's directly testable — same code path the
 * live page uses, not a copy of the SQL.
 *
 * @param string $q      Raw search term (already trimmed by the caller is fine either way).
 * @param string $sort    A *validated* column key from DOC_SORT_COLUMNS (see doc_sort_column()).
 * @param string $dir     'asc' or 'desc' (see doc_sort_direction()).
 * @return array<int, array<string, mixed>>
 */
function fetch_admin_documents(string $q, string $sort, string $dir): array {
    $q = trim($q);

    $sql = '
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
    ';
    $params = [];
    if ($q !== '') {
        $sql .= '
            WHERE CAST(d.id AS TEXT) LIKE :q
               OR d.title LIKE :q
               OR s.name LIKE :q
               OR SUBSTR(d.created_at, 1, 10) LIKE :q
        ';
        $params[':q'] = '%' . $q . '%';
    }
    // A secondary sort on d.id (same direction as the primary sort) breaks
    // ties deterministically. Without this, rows that share a sort value
    // (e.g. two documents both with available_at = NULL, or two by the same
    // creator) can come back in a different relative order between ASC and
    // DESC scans — SQLite makes no ordering guarantee among ties — so
    // clicking "sort ascending" then "sort descending" wouldn't actually
    // produce a mirrored list for tied rows.
    $orderDir = strtoupper(doc_sort_direction($dir));
    $sql .= ' ORDER BY ' . DOC_SORT_COLUMNS[doc_sort_column($sort)] . ' ' . $orderDir . ', d.id ' . $orderDir;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** HTML-escape helper for templates. */
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
