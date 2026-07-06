<?php

const APP_NAME = 'Argano Dispatch';

function render_header(string $title, ?array $staff = null, string $mainClass = 'container'): void {
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="nav">
    <div class="nav-inner">
        <a href="/admin.php" class="brand">
            <span class="brand-mark">A</span>
            <?= h(APP_NAME) ?>
        </a>
        <?php if ($staff): ?>
            <span class="nav-user"><strong><?= h($staff['name']) ?></strong> · <?= h($staff['email']) ?></span>
        <?php endif ?>
    </div>
</nav>
<main class="<?= h($mainClass) ?>">
    <?php
}

function render_footer(): void {
    ?>
<script>
// Every timestamp is rendered server-side in UTC (data-utc holds the
// unambiguous ISO value) with a UTC-formatted fallback as the element's
// initial text, for no-JS clients. Here we upgrade each one to whatever
// timezone the viewer's own browser is set to — so a document created or
// scheduled by staff in one timezone still displays correctly for a
// recipient (or another staff member) opening the page anywhere else.
// Locale is pinned to en-US so the MM/DD/YYYY HH:MM shape stays consistent;
// only the underlying moment shown shifts to the viewer's local clock.
(function () {
    var elements = document.querySelectorAll('[data-utc]');
    for (var i = 0; i < elements.length; i++) {
        var el = elements[i];
        var raw = el.getAttribute('data-utc');
        if (!raw) continue;
        var d = new Date(raw);
        if (isNaN(d.getTime())) continue;
        el.textContent = d.toLocaleString('en-US', {
            month: '2-digit',
            day: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
        }).replace(',', '');
    }
})();
</script>
</main>
</body>
</html>
    <?php
}
