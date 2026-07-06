<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();

$docId = (int) ($_GET['doc'] ?? 0);
$stmt  = db()->prepare('SELECT * FROM documents WHERE id = :id');
$stmt->execute([':id' => $docId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    render_header('Not found', $staff);
    ?>
    <div class="banner banner-error">That document doesn't exist (or no longer does).</div>
    <p><a href="/admin.php" class="back-link">← back to admin</a></p>
    <?php
    render_footer();
    exit;
}

$error        = null;
$created_token = null;
$created_code  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));

    if ($email === '') {
        $error = 'Recipient email is required.';
    } else {
        $token = random_token();
        $code  = random_access_code();
        $stmt = db()->prepare('
            INSERT INTO shares (document_id, token, recipient_email, access_code)
            VALUES (:document_id, :token, :recipient_email, :access_code)
        ');
        $stmt->execute([
            ':document_id'     => $doc['id'],
            ':token'           => $token,
            ':recipient_email' => $email,
            ':access_code'     => $code,
        ]);
        $shareId = (int) db()->lastInsertId();

        audit_log('create', 'share', $shareId, [
            'document_id'     => $doc['id'],
            'recipient_email' => $email,
        ]);
        $created_token = $token;
        $created_code  = $code;
    }
}

render_header('Share · ' . $doc['title'], $staff);
?>

<a href="/admin.php" class="back-link">← back to admin</a>

<h1 class="page-title">Share &ldquo;<?= h($doc['title']) ?>&rdquo;</h1>
<p class="page-subtitle">Generate a one-time link for a recipient.</p>

<?php if ($error !== null): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<?php if ($created_token !== null): ?>
    <div class="banner banner-success">
        Share link ready:
        <code>http://<?= h($_SERVER['HTTP_HOST']) ?>/view.php?token=<?= h($created_token) ?></code>
    </div>
    <div class="banner banner-warn">
        Access code: <code><?= h($created_code) ?></code><br>
        Give this to the recipient a different way than the link itself
        (read it aloud on a call, text it separately). The link alone won't
        open the document — they'll need this code too.
    </div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">Create share link</h2>
    <form method="post">
        <div class="form-field">
            <label for="email">Recipient email</label>
            <input type="email" id="email" name="email" required>
        </div>
        <button type="submit" class="btn">Generate link</button>
    </form>
</section>

<?php render_footer(); ?>
