<?php

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/migrate.php';

$dbPath = __DIR__ . '/db.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
run_migrations($pdo, __DIR__ . '/migrations');

$pdo->exec("
    INSERT INTO staff (email, name) VALUES
        ('avery@argano.example', 'Avery Ortega')
");

// A document that's live right away (available_at left NULL).
$stmt = $pdo->prepare('
    INSERT INTO documents (title, body, created_by)
    VALUES (?, ?, 1)
');
$stmt->execute([
    'Q1 Kickoff Brief',
    "Welcome aboard.\n\nThis brief outlines the Q1 engagement scope, the working cadence, and where to find the shared assets. Replace this seed copy with whatever your project actually needs.",
]);
$liveDocId = (int) $pdo->lastInsertId();

$token = random_token();
$stmt = $pdo->prepare('
    INSERT INTO shares (document_id, token, recipient_email)
    VALUES (?, ?, ?)
');
$stmt->execute([$liveDocId, $token, 'recipient@example.com']);

// A second document scheduled for the future, so the admin list shows both
// statuses out of the box ("Available" vs "Not available yet").
$futureAt = date('Y-m-d H:i:s', strtotime('+3 days'));
$stmt = $pdo->prepare('
    INSERT INTO documents (title, body, created_by, available_at)
    VALUES (?, ?, 1, ?)
');
$stmt->execute([
    'Vendor Security Addendum',
    "This addendum goes live once the vendor review completes. Don't share the link before then.",
    $futureAt,
]);
$scheduledDocId = (int) $pdo->lastInsertId();

$scheduledToken = random_token();
$stmt = $pdo->prepare('
    INSERT INTO shares (document_id, token, recipient_email)
    VALUES (?, ?, ?)
');
$stmt->execute([$scheduledDocId, $scheduledToken, 'recipient@example.com']);

echo "Seeded db.sqlite.\n";
echo "Admin:            http://localhost:8000/admin.php\n";
echo "Live share:       http://localhost:8000/view.php?token={$token}\n";
echo "Scheduled share:  http://localhost:8000/view.php?token={$scheduledToken} (available {$futureAt})\n";
