<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$token = (string) ($_GET['token'] ?? '');

$stmt = db()->prepare('
    SELECT d.*, s.recipient_email
    FROM shares s
    JOIN documents d ON d.id = s.document_id
    WHERE s.token = :token
');
$stmt->execute([':token' => $token]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    render_header('Not found');
    ?>
    <div class="centered-message">
        <h1>Share link not found</h1>
        <p>The link you used is invalid or has been removed.</p>
    </div>
    <?php
    render_footer();
    exit;
}

render_header($doc['title']);

if (!doc_is_available($doc)) {
    ?>
    <div class="centered-message">
        <h1>Not available yet</h1>
        <p>&ldquo;<?= h($doc['title']) ?>&rdquo; becomes available on <?= h($doc['available_at']) ?>.</p>
    </div>
    <?php
    render_footer();
    exit;
}
?>

<h1 class="page-title"><?= h($doc['title']) ?></h1>
<p class="meta">Shared with <?= h($doc['recipient_email']) ?></p>

<pre class="doc-body"><?= h($doc['body']) ?></pre>

<?php render_footer(); ?>
