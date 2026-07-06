<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title           = trim((string) ($_POST['title'] ?? ''));
    $body            = trim((string) ($_POST['body']  ?? ''));

    // available_at_utc is populated by JS from the datetime-local picker,
    // converted to a UTC ISO string using the browser's own timezone — this
    // is what makes scheduling correct for staff anywhere, not just whoever
    // the server happens to assume. available_at (raw, no timezone) is only
    // a fallback for the rare case JS didn't run; see the form's <script>.
    $availableAtUtc = trim((string) ($_POST['available_at_utc'] ?? ''));
    $availableAtRaw = $availableAtUtc !== ''
        ? $availableAtUtc
        : trim((string) ($_POST['available_at'] ?? ''));

    $parsedAvailableAt = parse_available_at($availableAtRaw);
    $availableAt       = $parsedAvailableAt['value'];
    if ($parsedAvailableAt['error'] !== null) {
        $error = $parsedAvailableAt['error'];
    }

    if ($title === '' || $body === '') {
        $error = 'Title and body are both required.';
    } elseif ($error === null && title_is_taken($title)) {
        $error = 'A document with this title already exists — titles must be unique.';
    } elseif ($error === null) {
        try {
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
        } catch (PDOException $e) {
            // Backstop for the rare race: two submissions for the same title
            // land here at nearly the same moment and both pass the
            // title_is_taken() check above before either commits. The UNIQUE
            // index (see migrations/20260710_unique_document_titles.sql)
            // catches it here instead of surfacing a raw SQL error.
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                $error = 'A document with this title already exists — titles must be unique.';
            } else {
                throw $e;
            }
        }
    }
}

// --- Search + sort --------------------------------------------------------
// Matches by row ID, title, creator name, or created date — all as a plain
// substring, so e.g. "2" matches doc #2 or any 2026 date, and "avery"
// matches the creator regardless of case. Date matching is scoped to just
// the YYYY-MM-DD portion, not the full timestamp — see fetch_admin_documents().

$q    = trim((string) ($_GET['q'] ?? ''));
$sort = doc_sort_column((string) ($_GET['sort'] ?? 'created'));
$dir  = doc_sort_direction((string) ($_GET['dir'] ?? 'desc'));

$docs = fetch_admin_documents($q, $sort, $dir);

// All titles regardless of the current search filter/sort, lowercased once
// here — used only for the live "this title is taken" warning below. The
// actual enforcement is server-side (title_is_taken() + the UNIQUE index);
// this is a UX nicety only, same as the scheduling field's live warning.
$allTitlesLower = array_map('mb_strtolower', db()->query('SELECT title FROM documents')->fetchAll(PDO::FETCH_COLUMN));

$now = date('Y-m-d H:i:s');

render_header('Admin', $staff, 'container container-wide');
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
    <form method="post" id="new-document-form">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
            <p class="field-hint field-hint-error" id="title_warning" hidden>A document with this title already exists — titles must be unique.</p>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="available_at">Available from (optional)</label>
            <input type="datetime-local" id="available_at" name="available_at" min="<?= h(date('Y-m-d\TH:i')) ?>">
            <input type="hidden" id="available_at_utc" name="available_at_utc">
            <p class="field-hint">Leave blank to make it visible to recipients immediately. Must be in the future.</p>
            <p class="field-hint field-hint-error" id="available_at_warning" hidden>That time has already passed — pick a later one.</p>
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<script>
(function () {
    var form = document.getElementById('new-document-form');
    var input = document.getElementById('available_at');
    var utcInput = document.getElementById('available_at_utc');
    var warning = document.getElementById('available_at_warning');
    if (!input) return;

    // Converts the picker's local wall-clock value to a UTC ISO string using
    // the browser's own timezone, so scheduling is correct no matter where
    // the staff member creating the document actually is. Sent alongside the
    // raw field as available_at_utc; the server prefers it when present.
    if (form) {
        form.addEventListener('submit', function () {
            if (input.value === '') {
                utcInput.value = '';
                return;
            }
            var d = new Date(input.value);
            utcInput.value = isNaN(d.getTime()) ? '' : d.toISOString();
        });
    }

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

<script>
(function () {
    // UX nicety only, mirroring the scheduling field's live warning above —
    // title_is_taken() on the server is the actual enforcement (backed by a
    // UNIQUE index) and runs regardless of what this catches.
    var existingTitlesLower = <?= json_encode($allTitlesLower, JSON_UNESCAPED_SLASHES) ?>;
    var input = document.getElementById('title');
    var warning = document.getElementById('title_warning');
    if (!input) return;

    function isTaken() {
        var value = input.value.trim().toLowerCase();
        return value !== '' && existingTitlesLower.indexOf(value) !== -1;
    }

    function updateWarning() {
        var taken = isTaken();
        input.classList.toggle('input-invalid', taken);
        if (warning) warning.hidden = !taken;
    }

    input.addEventListener('input', updateWarning);
    input.addEventListener('blur', updateWarning);
})();
</script>

<section class="card">
    <h2 class="card-title">Documents</h2>

    <form method="get" class="search-bar" id="search-form">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <input type="hidden" name="dir" value="<?= h($dir) ?>">
        <input
            type="text"
            name="q"
            value="<?= h($q) ?>"
            placeholder="Search by ID, title, creator, or date"
            class="search-input"
            id="search-input"
            autocomplete="off"
        >
        <?php if ($q !== ''): ?>
            <a href="/admin.php" class="back-link">Clear</a>
        <?php endif ?>
    </form>

    <?php if ($docs === []): ?>
        <p class="empty" id="empty-state"><?= $q !== '' ? 'No documents match your search.' : 'No documents yet.' ?></p>
    <?php else: ?>
        <table class="data" id="documents-table">
            <thead>
                <tr>
                    <th><?= doc_sort_link('id', 'ID', $sort, $dir, $q) ?></th>
                    <th><?= doc_sort_link('title', 'Title', $sort, $dir, $q) ?></th>
                    <th><?= doc_sort_link('creator', 'Creator', $sort, $dir, $q) ?></th>
                    <th><?= doc_sort_link('created', 'Created', $sort, $dir, $q) ?></th>
                    <th><?= doc_sort_link('status', 'Status', $sort, $dir, $q) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <?php $status = doc_status($d, $now); ?>
                    <?php
                        // Same fields the server search matches on (id, title,
                        // creator, date-only), lowercased once here so the
                        // live client-side filter below can just do a plain
                        // substring check with no per-keystroke DOM parsing.
                        $searchBlob = strtolower(
                            $d['id'] . ' ' . $d['title'] . ' ' . $d['creator_name'] . ' ' . substr($d['created_at'], 0, 10)
                        );
                    ?>
                    <tr data-search="<?= h($searchBlob) ?>">
                        <td class="id">#<?= (int) $d['id'] ?></td>
                        <td class="title-cell"><?= h($d['title']) ?></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td class="date-cell">
                            <span class="local-time" data-utc="<?= h(iso_utc($d['created_at'])) ?>"><?= h(format_display_datetime($d['created_at'])) ?></span>
                        </td>
                        <td class="status-cell">
                            <span class="status-badge <?= h($status['class']) ?>">
                                <?php if ($status['class'] === 'status-scheduled'): ?>
                                    Not available yet · <span class="local-time" data-utc="<?= h($status['available_at_utc']) ?>"><?= h(format_display_datetime($d['available_at'])) ?></span>
                                <?php else: ?>
                                    <?= h($status['label']) ?>
                                <?php endif ?>
                            </span>
                        </td>
                        <td class="row-actions">
                            <?php if ($status['class'] === 'status-scheduled'): ?>
                                <span class="btn-link-disabled" title="Available once the document goes live">
                                    Create share →
                                </span>
                            <?php else: ?>
                                <a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">
                                    Create share →
                                </a>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
        <p class="empty" id="live-empty-state" hidden>No documents match your search.</p>
    <?php endif ?>
</section>

<script>
(function () {
    var input = document.getElementById('search-input');
    var table = document.getElementById('documents-table');
    if (!input || !table) return; // nothing to filter (zero documents server-side)

    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
    var liveEmptyState = document.getElementById('live-empty-state');

    function applyFilter() {
        var term = input.value.trim().toLowerCase();
        var visibleCount = 0;

        rows.forEach(function (row) {
            var matches = term === '' || (row.dataset.search || '').indexOf(term) !== -1;
            row.hidden = !matches;
            if (matches) visibleCount++;
        });

        table.hidden = visibleCount === 0;
        if (liveEmptyState) liveEmptyState.hidden = visibleCount !== 0;
    }

    // Filter instantly as you type — no need to click Search or reload.
    // Submitting the form still works too (useful for a shareable/bookmarkable
    // URL, or if JS is unavailable), it just re-runs the same search server-side.
    input.addEventListener('input', applyFilter);
})();
</script>

<?php render_footer(); ?>
