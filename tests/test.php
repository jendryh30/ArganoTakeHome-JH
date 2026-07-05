<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE d.title = ?
        LIMIT 1
    ');
    $stmt->execute(['Q1 Kickoff Brief']);
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
});

// --- Feature: document scheduler ----------------------------------------

test('a document created with no schedule is available now', function () {
    $doc = db()->query("SELECT available_at FROM documents WHERE title = 'Q1 Kickoff Brief'")->fetch();
    assert_true($doc['available_at'] === null, 'expected available_at to be NULL for an unscheduled document');
    assert_true(doc_is_available($doc), 'a document with no schedule should be available');
});

test('a document scheduled in the future is not yet available', function () {
    $doc = db()->query("SELECT available_at FROM documents WHERE title = 'Vendor Security Addendum'")->fetch();
    assert_true($doc['available_at'] !== null, 'expected the seeded document to have a schedule');
    assert_true(
        $doc['available_at'] > date('Y-m-d H:i:s'),
        'expected the seeded schedule to be in the future relative to now'
    );
    assert_true(!doc_is_available($doc), 'a document scheduled in the future should not be available yet');
});

test('a document becomes available once its schedule time has passed', function () {
    $doc = ['available_at' => '2000-01-01 00:00:00'];
    assert_true(doc_is_available($doc), 'a schedule in the past should count as available');
});

test('parse_available_at rejects a past date/time', function () {
    $result = parse_available_at('2000-01-01T00:00');
    assert_true($result['value'] === null, 'a past schedule should not produce a value');
    assert_true(
        $result['error'] === 'Availability date/time must be in the future.',
        'unexpected error: ' . var_export($result['error'], true)
    );
});

test('parse_available_at rejects the current moment (not strictly future)', function () {
    $now = '2026-01-01 12:00:00';
    $result = parse_available_at('2026-01-01T12:00', $now);
    assert_true($result['value'] === null, 'a schedule equal to now should be rejected');
    assert_true($result['error'] !== null, 'expected an error for a non-future schedule');
});

test('parse_available_at rejects a past time on the same day', function () {
    $now = '2026-01-01 14:00:00';
    $result = parse_available_at('2026-01-01T09:00', $now); // same day, earlier time
    assert_true($result['value'] === null, 'a past time on the same day should not produce a value');
    assert_true($result['error'] !== null, 'expected an error for a same-day-but-past time');
});

test('parse_available_at accepts a future time on the same day', function () {
    $now = '2026-01-01 09:00:00';
    $result = parse_available_at('2026-01-01T14:00', $now); // same day, later time
    assert_true($result['error'] === null, 'a later time on the same day should not error');
    assert_true($result['value'] === '2026-01-01 14:00:00', 'unexpected value: ' . var_export($result['value'], true));
});

test('parse_available_at accepts a future date/time', function () {
    $now = '2026-01-01 12:00:00';
    $result = parse_available_at('2026-01-02T09:00', $now);
    assert_true($result['error'] === null, 'a future schedule should not error');
    assert_true($result['value'] === '2026-01-02 09:00:00', 'unexpected value: ' . var_export($result['value'], true));
});

test('parse_available_at leaves blank input as "available immediately"', function () {
    $result = parse_available_at('');
    assert_true($result['value'] === null && $result['error'] === null, 'blank input should be valid and mean no schedule');
});

test('parse_available_at rejects unparseable input', function () {
    $result = parse_available_at('not a date');
    assert_true($result['value'] === null, 'unparseable input should not produce a value');
    assert_true($result['error'] !== null, 'expected an error for unparseable input');
});

test('doc_status labels match availability', function () {
    $available = doc_status(['available_at' => null]);
    assert_true($available['label'] === 'Available', 'expected "Available" label for an unscheduled doc');
    assert_true($available['class'] === 'status-live', 'expected the live status class');

    $scheduled = doc_status(['available_at' => '2099-01-01 00:00:00']);
    assert_true(
        str_starts_with($scheduled['label'], 'Not available yet'),
        'expected a "Not available yet" label for a future-scheduled doc'
    );
    assert_true($scheduled['class'] === 'status-scheduled', 'expected the scheduled status class');
});

// --- Feature: document search + sort -------------------------------------

test('search matches a document by title substring, case-insensitively', function () {
    $stmt = db()->prepare('SELECT title FROM documents WHERE title LIKE :q');
    $stmt->execute([':q' => '%KICKOFF%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) === 1, 'expected exactly one title match for "KICKOFF"');
    assert_true($rows[0]['title'] === 'Q1 Kickoff Brief', 'unexpected match: ' . var_export($rows[0], true));
});

test('search matches a document by its numeric id', function () {
    $doc = db()->query("SELECT id FROM documents WHERE title = 'Q1 Kickoff Brief'")->fetch();
    $stmt = db()->prepare('SELECT id FROM documents WHERE CAST(id AS TEXT) LIKE :q');
    $stmt->execute([':q' => '%' . $doc['id'] . '%']);
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    assert_true(in_array((int) $doc['id'], $ids, true), 'expected the id search to include the matching document');
});

test('search matches a document by creator name', function () {
    $stmt = db()->prepare('
        SELECT d.title FROM documents d JOIN staff s ON s.id = d.created_by
        WHERE s.name LIKE :q
    ');
    $stmt->execute([':q' => '%avery%']);
    assert_true(count($stmt->fetchAll()) >= 1, 'expected at least one document created by Avery');
});

test('search matches a document by a substring of its created date', function () {
    $doc = db()->query("SELECT created_at FROM documents WHERE title = 'Q1 Kickoff Brief'")->fetch();
    $yearMonth = substr($doc['created_at'], 0, 7); // e.g. "2026-07"
    $stmt = db()->prepare('SELECT title FROM documents WHERE SUBSTR(created_at, 1, 10) LIKE :q');
    $stmt->execute([':q' => '%' . $yearMonth . '%']);
    $titles = array_column($stmt->fetchAll(), 'title');
    assert_true(in_array('Q1 Kickoff Brief', $titles, true), 'expected the date search to include the matching document');
});

test('date search does not false-match on time-of-day digits absent from the date', function () {
    // A date with no '9' anywhere, but a time-of-day full of them. Under the
    // old (buggy) full-timestamp LIKE match, searching "9" would still hit
    // this row through its time — that's the exact bug being regressed here.
    $stmt = db()->prepare("
        INSERT INTO documents (title, body, created_by, created_at)
        VALUES ('Time Digit Probe', 'body', 1, '2026-07-05 19:19:19')
    ");
    $stmt->execute();
    $probeId = (int) db()->lastInsertId();

    try {
        $stmt = db()->prepare('SELECT title FROM documents WHERE SUBSTR(created_at, 1, 10) LIKE :q');
        $stmt->execute([':q' => '%9%']);
        $titles = array_column($stmt->fetchAll(), 'title');
        assert_true(
            !in_array('Time Digit Probe', $titles, true),
            'searching "9" should not match a row via its time-of-day when the date itself has no 9'
        );
    } finally {
        db()->prepare('DELETE FROM documents WHERE id = ?')->execute([$probeId]);
    }
});

test('doc_sort_column only allows whitelisted columns, defaulting to "created"', function () {
    assert_true(doc_sort_column('title') === 'title', 'a whitelisted column should pass through');
    assert_true(doc_sort_column('status') === 'status', 'status should be a whitelisted column');
    assert_true(doc_sort_column('id; DROP TABLE documents') === 'created', 'an unrecognized/malicious column should fall back to "created"');
});

test('doc_sort_direction only allows asc/desc, defaulting to desc', function () {
    assert_true(doc_sort_direction('asc') === 'asc');
    assert_true(doc_sort_direction('ASC') === 'asc', 'should be case-insensitive');
    assert_true(doc_sort_direction('desc') === 'desc');
    assert_true(doc_sort_direction('sideways') === 'desc', 'anything unrecognized should default to desc');
});

test('sorting by title works in both directions and mirrors each other', function () {
    $asc  = db()->query('SELECT title FROM documents ORDER BY title COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_COLUMN);
    $desc = db()->query('SELECT title FROM documents ORDER BY title COLLATE NOCASE DESC')->fetchAll(PDO::FETCH_COLUMN);
    assert_true(count($asc) >= 2, 'expected at least two documents to meaningfully test sort order');
    assert_true($asc === array_reverse($desc), 'ascending and descending title order should mirror each other');
});

test('sorting by status (available_at) puts unscheduled docs before future-scheduled ones ascending', function () {
    $rows = db()->query("
        SELECT title, available_at FROM documents ORDER BY available_at ASC
    ")->fetchAll();
    $firstRow = $rows[0];
    assert_true($firstRow['available_at'] === null, 'the first row ascending should be an unscheduled (immediately available) document');
});

test('doc_sort_link generates the opposite direction for the active column, and asc for an inactive one', function () {
    $activeLink = doc_sort_link('title', 'Title', 'title', 'asc', '');
    assert_true(str_contains($activeLink, 'dir=desc'), 'clicking the already-ascending active column should flip to desc');
    assert_true(str_contains($activeLink, '↑'), 'the active ascending column should show an up arrow');

    $inactiveLink = doc_sort_link('creator', 'Creator', 'title', 'asc', '');
    assert_true(str_contains($inactiveLink, 'dir=asc'), 'clicking an inactive column should default to asc');
    assert_true(!str_contains($inactiveLink, '↑') && !str_contains($inactiveLink, '↓'), 'an inactive column should show no arrow');
});

test('doc_sort_link preserves the current search term across sort clicks', function () {
    $link = doc_sort_link('id', 'ID', 'created', 'desc', 'kickoff');
    assert_true(str_contains($link, 'q=kickoff'), 'the search term should be preserved in the sort link URL');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
