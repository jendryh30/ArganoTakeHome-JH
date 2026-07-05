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

/** Cryptographically-random hex token, defaults to 32 chars (16 bytes). */
function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
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
 */
function doc_sort_link(string $column, string $label, string $activeSort, string $activeDir, string $q): string {
    $nextDir = ($activeSort === $column && $activeDir === 'asc') ? 'desc' : 'asc';
    $arrow   = $activeSort === $column ? ($activeDir === 'asc' ? ' ↑' : ' ↓') : '';
    $url     = '/admin.php?' . http_build_query(['q' => $q, 'sort' => $column, 'dir' => $nextDir]);
    return '<a href="' . h($url) . '" class="sort-link">' . h($label) . $arrow . '</a>';
}

/** HTML-escape helper for templates. */
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
