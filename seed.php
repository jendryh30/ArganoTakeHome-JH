<?php

require __DIR__ . '/lib/bootstrap.php';

$dbPath = __DIR__ . '/db.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

$pdo->exec("
    INSERT INTO staff (email, name) VALUES
        ('avery@argano.example', 'Avery Ortega')
");

$stmt = $pdo->prepare('
    INSERT INTO documents (title, body, created_by)
    VALUES (?, ?, 1)
');
$stmt->execute([
    'Q1 Kickoff Brief',
    "Welcome aboard.\n\nThis brief outlines the Q1 engagement scope, the working cadence, and where to find the shared assets. Replace this seed copy with whatever your project actually needs.",
]);
$docId = (int) $pdo->lastInsertId();

$token = random_token();
$stmt = $pdo->prepare('
    INSERT INTO shares (document_id, token, recipient_email)
    VALUES (?, ?, ?)
');
$stmt->execute([$docId, $token, 'recipient@example.com']);

echo "Seeded db.sqlite.\n";
echo "Admin:        http://localhost:8000/admin.php\n";
echo "Sample share: http://localhost:8000/view.php?token={$token}\n";
