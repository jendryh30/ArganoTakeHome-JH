<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

$stmt = db()->prepare('
    SELECT d.*, s.id AS share_id, s.recipient_email, s.access_code
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

// The link alone isn't enough — the recipient also needs the 6-digit access
// code given to them through a separate channel. No session is kept (this
// app has no session infrastructure), so the code is asked for on every
// visit; that's a deliberate simplification, not an oversight.
$codeError = null;
$verified  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = trim((string) ($_POST['code'] ?? ''));
    if (access_code_matches($doc['access_code'], $submitted)) {
        $verified = true;
    } else {
        $codeError = 'That code doesn’t match. Double-check it and try again.';
        audit_log_anonymous('share_code_failed', 'share', (int) $doc['share_id']);
    }
}

render_header($doc['title']);

if (!$verified) {
    ?>
    <div class="centered-message">
        <h1><?= h($doc['title']) ?></h1>
        <p>Enter the 6-digit access code you were given to view this document.</p>
        <?php if ($codeError !== null): ?>
            <div class="banner banner-error"><?= h($codeError) ?></div>
        <?php endif ?>
        <form method="post" action="?token=<?= h($token) ?>" class="form-field">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <input
                type="text"
                name="code"
                inputmode="numeric"
                pattern="[0-9]{6}"
                maxlength="6"
                placeholder="123456"
                autocomplete="one-time-code"
                required
                autofocus
            >
            <button type="submit" class="btn">Continue</button>
        </form>
    </div>
    <?php
    render_footer();
    exit;
}

audit_log_anonymous('share_code_verified', 'share', (int) $doc['share_id']);

if (!doc_is_available($doc)) {
    ?>
    <div class="centered-message">
        <h1>Not available yet</h1>
        <p>
        &ldquo;<?= h($doc['title']) ?>&rdquo; becomes available on
        <span class="local-time" data-utc="<?= h(iso_utc($doc['available_at'])) ?>"><?= h(format_display_datetime($doc['available_at'])) ?></span>.
    </p>
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
