<?php

declare(strict_types=1);

/**
 * Compares the SEEDED REFERENCE DATA between backend/ (sanad_platform_test)
 * and backend-v2/ (sanad_platform_v2).
 *
 * READ-ONLY against the legacy database — SELECTs only.
 *
 * This matters more than it looks. Several of these values are joined on by
 * STRING, not by id:
 *   - teachers.grades / teachers.subjects store NAME arrays
 *   - students.governorate stores a NAME
 * so a single renamed or mistyped entry silently orphans live rows rather than
 * raising an error. Comparing the actual sets is the only way to be sure.
 *
 * Usage: php tests/Manual/seed_value_diff.php
 */

$pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

/** @return array<int,string> */
function names(PDO $pdo, string $db, string $sql): array
{
    $stmt = $pdo->query(str_replace('{db}', "`{$db}`", $sql));
    $out = array_column($stmt->fetchAll(), 'name');
    sort($out, SORT_STRING);

    return $out;
}

$checks = [
    'grades (flat catalog, curriculum_id IS NULL)'
        => 'SELECT name FROM {db}.grades WHERE curriculum_id IS NULL',
    'subjects'
        => 'SELECT name FROM {db}.subjects',
    'governorates (Egypt)'
        => "SELECT g.name FROM {db}.governorates g JOIN {db}.curricula c ON c.id = g.curriculum_id WHERE c.name = 'مصر'",
    'governorates (Jordan)'
        => "SELECT g.name FROM {db}.governorates g JOIN {db}.curricula c ON c.id = g.curriculum_id WHERE c.name = 'الأردن'",
];

$failures = 0;

foreach ($checks as $label => $sql) {
    $old = names($pdo, 'sanad_platform_test', $sql);
    $new = names($pdo, 'sanad_platform_v2', $sql);

    $missing = array_diff($old, $new);
    $extra = array_diff($new, $old);

    if (!$missing && !$extra) {
        printf("  OK    %-46s %d values, identical\n", $label, count($old));
        continue;
    }

    $failures++;
    printf("  DIFF  %s\n", $label);
    printf("        legacy=%d new=%d\n", count($old), count($new));
    if ($missing) {
        printf("        missing from backend-v2: %s\n", implode(' | ', $missing));
    }
    if ($extra) {
        printf("        extra in backend-v2:     %s\n", implode(' | ', $extra));
    }
}

// The curricula rows carry more than a name — the phone/currency facts are the
// whole point of the table, and phone_digits is the documented trap.
echo "\n── curricula field-level comparison ──\n";
$sql = 'SELECT name, phone_code, phone_digits, currency_code, currency_symbol, is_active
        FROM {db}.curricula ORDER BY name';

$old = $pdo->query(str_replace('{db}', '`sanad_platform_test`', $sql))->fetchAll();
$new = $pdo->query(str_replace('{db}', '`sanad_platform_v2`', $sql))->fetchAll();

foreach ($old as $i => $row) {
    $newRow = $new[$i] ?? null;
    if ($newRow === null) {
        $failures++;
        echo "  DIFF  missing curriculum: {$row['name']}\n";
        continue;
    }
    if ($row == $newRow) {
        printf(
            "  OK    %-8s phone_code=%-5s phone_digits=%-3s currency=%s %s\n",
            $row['name'],
            $row['phone_code'],
            $row['phone_digits'],
            $row['currency_code'],
            $row['currency_symbol']
        );
    } else {
        $failures++;
        echo "  DIFF  {$row['name']}\n";
        echo '        legacy: ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        echo '        new:    ' . json_encode($newRow, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "\n══ RESULT ══\n";
echo $failures === 0
    ? "  all seeded reference data is identical\n"
    : "  {$failures} difference(s)\n";

exit($failures === 0 ? 0 : 1);
