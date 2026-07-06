<?php

declare(strict_types=1);

/**
 * Dead-simple migration runner. Applies any *.sql file in $migrationsDir
 * that isn't already recorded in schema_migrations, in filename order.
 * Filenames should sort in the order they should apply (e.g. a date or
 * zero-padded sequence prefix).
 */
function run_migrations(PDO $pdo, string $migrationsDir): void {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS schema_migrations (
            filename    TEXT NOT NULL PRIMARY KEY,
            applied_at  TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )
    ');

    $applied = array_flip(
        $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)
    );

    $files = glob(rtrim($migrationsDir, '/') . '/*.sql') ?: [];
    sort($files, SORT_STRING);

    foreach ($files as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            continue;
        }

        $sql = file_get_contents($file);
        if (trim((string) $sql) === '') {
            // Leftover empty file from an earlier experiment — nothing to apply.
            continue;
        }

        $pdo->exec($sql);

        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (:filename)');
        $stmt->execute([':filename' => $name]);
    }
}
