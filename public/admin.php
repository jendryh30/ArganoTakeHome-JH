<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title           = trim((string) ($_POST['title'] ?? ''));
    $body            = trim((string) ($_POST['body']  ?? ''));
    $availableAtRaw  = trim((string) ($_POST['available_at'] ?? ''));

    $parsedAvailableAt = parse_available_at($availableAtRaw);
    $availableAt       = $parsedAvailableAt['value'];
    if ($parsedAvailableAt['error'] !== null) {
        $error = $parsedAvailableAt['error'];
    }

    if ($title === '' || $body === '') {
        $error = 'Title and body are both required.';
    } elseif ($error === null) {
        $stmt = db()->prepare('
            INSERT INTO documents (title, body, created_by, available_at)
            VALUES (:title, :body, :created_by, :available_at)
        ');
        $stmt->execute([
            ':title'        => $title,
            ':body'         => $body,
            ':created_by'   => $staff['id'],
            ':available_at' => $availableAt,
        ]);
        $docId = (int) db()->lastInsertId();

        audit_log('create', 'document', $docId, [
            'title'        => $title,
            'available_at' => $availableAt,
        ]);

        header('Location: /admin.php?created=' . $docId);
        exit;
    }
}

$docs = db()->query('
    SELECT d.*, s.name AS creator_name
    FROM documents d
    JOIN staff s ON s.id = d.created_by
    ORDER BY d.created_at DESC
')->fetchAll();

$now = date('Y-m-d H:i:s');

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">
        Document #<?= (int) $_GET['created'] ?> was created.
    </div>
<?php endif ?>

<?php if ($error !== null): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="available_at">Available from (optional)</label>
            <input type="datetime-local" id="available_at" name="available_at" min="<?= h(date('Y-m-d\TH:i')) ?>">
            <p class="field-hint">Leave blank to make it visible to recipients immediately. Must be in the future.</p>
            <p class="field-hint field-hint-error" id="available_at_warning" hidden>That time has already passed — pick a later one.</p>
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<script>
(function () {
    var input = document.getElementById('available_at');
    var warning = document.getElementById('available_at_warning');
    if (!input) return;

    function nowLocalValue() {
        var d = new Date();
        d.setSeconds(0, 0);
        var tzOffsetMs = d.getTimezoneOffset() * 60000;
        return new Date(d.getTime() - tzOffsetMs).toISOString().slice(0, 16);
    }

    // Keep the picker's floor moving forward instead of freezing at page-load time.
    function refreshMin() {
        input.min = nowLocalValue();
    }
    refreshMin();
    setInterval(refreshMin, 30000);

    // The native picker can't visually grey out individual past hours within
    // an otherwise-valid day (browsers only enforce `min` at the day level),
    // so give live feedback the moment a past time is picked instead of
    // waiting for blur/submit.
    function isPast() {
        return input.value !== '' && input.value < nowLocalValue();
    }

    function updateWarning() {
        var past = isPast();
        input.classList.toggle('input-invalid', past);
        if (warning) warning.hidden = !past;
    }

    // If a past moment ever ends up in the field (typed by hand, or the
    // clock caught up to a previously-valid pick), snap it back to now.
    // This is a UX nicety only — parse_available_at() on the server is the
    // actual enforcement and runs regardless of what the browser allowed.
    function clampToNow() {
        if (isPast()) {
            input.value = nowLocalValue();
        }
        updateWarning();
    }

    input.addEventListener('input', updateWarning);
    input.addEventListener('change', clampToNow);
    input.addEventListener('blur', clampToNow);
})();
</script>

<section class="card">
    <h2 class="card-title">Documents</h2>
    <?php if ($docs === []): ?>
        <p class="empty">No documents yet.</p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <?php $status = doc_status($d, $now); ?>
                    <tr>
                        <td class="id">#<?= (int) $d['id'] ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td><span class="status-badge <?= h($status['class']) ?>"><?= h($status['label']) ?></span></td>
                        <td>
                            <a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">
                                Create share →
                            </a>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>
