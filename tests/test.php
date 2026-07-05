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

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
