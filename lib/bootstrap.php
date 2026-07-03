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

/** HTML-escape helper for templates. */
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
