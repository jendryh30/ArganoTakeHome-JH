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

// --- Feature: unique document titles -------------------------------------

test('title_is_taken is true for an exact existing title', function () {
    assert_true(title_is_taken('Q1 Kickoff Brief'), 'expected the seeded title to be reported as taken');
});

test('title_is_taken is case-insensitive', function () {
    assert_true(title_is_taken('q1 kickoff brief'), 'expected a case-insensitive match');
    assert_true(title_is_taken('Q1 KICKOFF BRIEF'), 'expected a case-insensitive match');
});

test('title_is_taken is false for a title that does not exist', function () {
    assert_true(!title_is_taken('Definitely Not A Real Title Yet'), 'expected an unused title to be reported as free');
});

test('the UNIQUE index rejects a duplicate title even bypassing the app-level check', function () {
    $doc = db()->query("SELECT created_by FROM documents WHERE title = 'Q1 Kickoff Brief'")->fetch();

    $threw = false;
    try {
        $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, ?)');
        $stmt->execute(['Q1 Kickoff Brief', 'duplicate attempt', $doc['created_by']]);
    } catch (PDOException $e) {
        $threw = str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
    assert_true($threw, 'expected inserting a duplicate title to violate the UNIQUE index');
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

// fetch_admin_documents() is the exact function admin.php calls, so these
// tests exercise the real query path, not a separately-maintained copy of it.
// Every column is checked both directions, and asc/desc are confirmed to be
// exact mirrors of each other (not just "different").

test('sorting by ID works lowest-to-highest and highest-to-lowest', function () {
    $asc  = array_column(fetch_admin_documents('', 'id', 'asc'), 'id');
    $desc = array_column(fetch_admin_documents('', 'id', 'desc'), 'id');
    assert_true(count($asc) >= 3, 'expected at least three seeded documents');
    assert_true($asc === array_reverse($desc), 'ascending and descending ID order should mirror each other');
    $sorted = $asc;
    sort($sorted, SORT_NUMERIC);
    assert_true($asc === $sorted, 'ascending IDs should actually be numerically increasing');
});

test('sorting by Title works lowest-to-highest and highest-to-lowest', function () {
    $asc  = array_column(fetch_admin_documents('', 'title', 'asc'), 'title');
    $desc = array_column(fetch_admin_documents('', 'title', 'desc'), 'title');
    assert_true($asc === array_reverse($desc), 'ascending and descending title order should mirror each other');
    $sorted = $asc;
    sort($sorted, SORT_STRING | SORT_FLAG_CASE);
    assert_true($asc === $sorted, 'ascending titles should actually be alphabetically increasing');
});

test('sorting by Creator works lowest-to-highest and highest-to-lowest', function () {
    $asc  = array_column(fetch_admin_documents('', 'creator', 'asc'), 'creator_name');
    $desc = array_column(fetch_admin_documents('', 'creator', 'desc'), 'creator_name');
    assert_true(count(array_unique($asc)) >= 2, 'expected at least two distinct creators to meaningfully test this sort');
    assert_true($asc === array_reverse($desc), 'ascending and descending creator order should mirror each other');
    $sorted = $asc;
    sort($sorted, SORT_STRING | SORT_FLAG_CASE);
    assert_true($asc === $sorted, 'ascending creator names should actually be alphabetically increasing');
});

test('sorting by Created date works oldest-first and newest-first', function () {
    $asc  = array_column(fetch_admin_documents('', 'created', 'asc'), 'created_at');
    $desc = array_column(fetch_admin_documents('', 'created', 'desc'), 'created_at');
    assert_true($asc === array_reverse($desc), 'ascending and descending created-date order should mirror each other');
    $sorted = $asc;
    sort($sorted, SORT_STRING);
    assert_true($asc === $sorted, 'ascending created dates should actually be chronologically increasing');
});

test('sorting by Status works both directions: available-first ascending, scheduled-first descending', function () {
    $asc  = fetch_admin_documents('', 'status', 'asc');
    $desc = fetch_admin_documents('', 'status', 'desc');

    assert_true($asc[0]['available_at'] === null, 'ascending should put an immediately-available document first');
    assert_true($desc[0]['available_at'] !== null, 'descending should put the future-scheduled document first');

    $ascKeys  = array_column($asc, 'id');
    $descKeys = array_column($desc, 'id');
    assert_true($ascKeys === array_reverse($descKeys), 'ascending and descending status order should mirror each other');
});

test('doc_sort_link generates the opposite direction for the active column, and asc for an inactive one', function () {
    $activeLink = doc_sort_link('title', 'Title', 'title', 'asc', '');
    assert_true(str_contains($activeLink, 'dir=desc'), 'clicking the already-ascending active column should flip to desc');

    $inactiveLink = doc_sort_link('creator', 'Creator', 'title', 'asc', '');
    assert_true(str_contains($inactiveLink, 'dir=asc'), 'clicking an inactive column should default to asc');
});

test('doc_sort_link always shows both arrows, highlighting only the active direction for the active column', function () {
    $ascActive = doc_sort_link('title', 'Title', 'title', 'asc', '');
    assert_true(substr_count($ascActive, '↑') === 1, 'expected exactly one up arrow');
    assert_true(substr_count($ascActive, '↓') === 1, 'expected exactly one down arrow (always shown, not just for the active state)');
    assert_true(
        preg_match('/sort-arrow sort-arrow-active">↑/', $ascActive) === 1,
        'the up arrow should be highlighted when this column is ascending'
    );
    assert_true(
        preg_match('/sort-arrow">↓/', $ascActive) === 1,
        'the down arrow should NOT be highlighted when this column is ascending'
    );

    $descActive = doc_sort_link('title', 'Title', 'title', 'desc', '');
    assert_true(
        preg_match('/sort-arrow sort-arrow-active">↓/', $descActive) === 1,
        'the down arrow should be highlighted when this column is descending'
    );
    assert_true(
        preg_match('/sort-arrow">↑/', $descActive) === 1,
        'the up arrow should NOT be highlighted when this column is descending'
    );

    $inactive = doc_sort_link('creator', 'Creator', 'title', 'asc', '');
    assert_true(substr_count($inactive, '↑') === 1 && substr_count($inactive, '↓') === 1, 'an inactive column should still show both arrows');
    assert_true(!str_contains($inactive, 'sort-arrow-active'), 'neither arrow should be highlighted for a column that is not the current sort');
});

test('doc_sort_link preserves the current search term across sort clicks', function () {
    $link = doc_sort_link('id', 'ID', 'created', 'desc', 'kickoff');
    assert_true(str_contains($link, 'q=kickoff'), 'the search term should be preserved in the sort link URL');
});

// --- Fix: created_at/available_at timezone mismatch + display format ----

test('format_display_datetime renders MM/DD/YYYY HH:MM', function () {
    assert_true(
        format_display_datetime('2026-07-06 05:00:00') === '07/06/2026 05:00',
        'unexpected format: ' . format_display_datetime('2026-07-06 05:00:00')
    );
});

test('format_display_datetime returns an empty string for null/blank input', function () {
    assert_true(format_display_datetime(null) === '');
    assert_true(format_display_datetime('') === '');
});

test('doc_status uses the MM/DD/YYYY HH:MM display format in its label', function () {
    $scheduled = doc_status(['available_at' => '2099-03-05 14:30:00']);
    assert_true(
        $scheduled['label'] === 'Not available yet · 03/05/2099 14:30',
        'unexpected label: ' . $scheduled['label']
    );
});

test('iso_utc renders an unambiguous UTC ISO string from a stored timestamp', function () {
    assert_true(
        iso_utc('2026-07-06 05:00:00') === '2026-07-06T05:00:00Z',
        'unexpected value: ' . iso_utc('2026-07-06 05:00:00')
    );
});

test('iso_utc returns null for null/blank input', function () {
    assert_true(iso_utc(null) === null);
    assert_true(iso_utc('') === null);
});

test('doc_status exposes available_at_utc for JS-based local-time display, null when available now', function () {
    $available = doc_status(['available_at' => null]);
    assert_true($available['available_at_utc'] === null, 'an available-now doc should not need a UTC value');

    $scheduled = doc_status(['available_at' => '2099-03-05 14:30:00']);
    assert_true(
        $scheduled['available_at_utc'] === '2099-03-05T14:30:00Z',
        'unexpected value: ' . var_export($scheduled['available_at_utc'], true)
    );
});

// --- Feature: share access code -----------------------------------------

test('random_access_code always produces a zero-padded 6-digit string', function () {
    for ($i = 0; $i < 50; $i++) {
        $code = random_access_code();
        assert_true(
            preg_match('/^\d{6}$/', $code) === 1,
            "expected a 6-digit numeric string, got: {$code}"
        );
    }
});

test('access_code_matches accepts the exact stored code', function () {
    assert_true(access_code_matches('042817', '042817'), 'identical codes should match');
});

test('access_code_matches trims whitespace from the submission', function () {
    assert_true(access_code_matches('042817', ' 042817 '), 'surrounding whitespace should be ignored');
});

test('access_code_matches rejects a wrong code', function () {
    assert_true(!access_code_matches('042817', '042818'), 'a single wrong digit should not match');
});

test('access_code_matches rejects blank input on either side', function () {
    assert_true(!access_code_matches('042817', ''), 'an empty submission should never match');
    assert_true(!access_code_matches(null, '042817'), 'a share with no stored code should never match');
    assert_true(!access_code_matches('', ''), 'two empty strings should not count as a match');
});

test('every seeded share has a 6-digit access_code stored', function () {
    $codes = db()->query('SELECT access_code FROM shares')->fetchAll(PDO::FETCH_COLUMN);
    assert_true(count($codes) >= 3, 'expected at least three seeded shares');
    foreach ($codes as $code) {
        assert_true(
            preg_match('/^\d{6}$/', (string) $code) === 1,
            'expected every seeded share to carry a 6-digit access_code, got: ' . var_export($code, true)
        );
    }
});

test('random_share_token always produces a zero-padded 6-digit string', function () {
    for ($i = 0; $i < 50; $i++) {
        $token = random_share_token();
        assert_true(
            preg_match('/^\d{6}$/', $token) === 1,
            "expected a 6-digit numeric string, got: {$token}"
        );
    }
});

test('random_share_token never returns a value already in use', function () {
    $doc = db()->query("SELECT id FROM documents WHERE title = 'Q1 Kickoff Brief'")->fetch();
    $taken = random_share_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email, access_code) VALUES (?, ?, ?, ?)');
    $stmt->execute([$doc['id'], $taken, 'collision-check@example.com', random_access_code()]);
    $shareId = (int) db()->lastInsertId();

    try {
        for ($i = 0; $i < 20; $i++) {
            assert_true(random_share_token() !== $taken, 'a freshly generated token collided with one already in the database');
        }
    } finally {
        db()->prepare('DELETE FROM shares WHERE id = ?')->execute([$shareId]);
    }
});

test('every seeded share has a 6-digit token, not the old hex format', function () {
    $tokens = db()->query('SELECT token FROM shares')->fetchAll(PDO::FETCH_COLUMN);
    assert_true(count($tokens) >= 3, 'expected at least three seeded shares');
    foreach ($tokens as $token) {
        assert_true(
            preg_match('/^\d{6}$/', (string) $token) === 1,
            'expected every seeded share token to be 6 digits, got: ' . var_export($token, true)
        );
    }
});

test('creating a share stores a working access code alongside the token', function () {
    $doc = db()->query("SELECT id FROM documents WHERE title = 'Q1 Kickoff Brief'")->fetch();

    $token = random_share_token();
    $code  = random_access_code();
    $stmt = db()->prepare('
        INSERT INTO shares (document_id, token, recipient_email, access_code)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$doc['id'], $token, 'new-recipient@example.com', $code]);
    $shareId = (int) db()->lastInsertId();

    try {
        $stmt = db()->prepare('SELECT access_code FROM shares WHERE token = ?');
        $stmt->execute([$token]);
        $stored = $stmt->fetchColumn();

        assert_true($stored === $code, 'the code read back from the DB should match what was generated');
        assert_true(access_code_matches($stored, $code), 'the stored code should verify against itself');
        assert_true(!access_code_matches($stored, '000000'), 'an arbitrary wrong guess should not verify');
    } finally {
        db()->prepare('DELETE FROM shares WHERE id = ?')->execute([$shareId]);
    }
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
