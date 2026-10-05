<?php

declare(strict_types=1);

/**
 * Programmatic logical-schema diff: backend/ (sanad_platform_test) vs
 * backend-v2/ (sanad_platform_v2).
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │ READ-ONLY. This script opens sanad_platform_test with SELECT-only    │
 * │ queries against information_schema and NEVER issues a DDL/DML        │
 * │ statement against it. It is the only place in backend-v2/ that       │
 * │ touches the legacy database at all, and it exists purely to prove    │
 * │ equivalence.                                                         │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * The user's fidelity criterion is RELAXED from byte-exact DDL: what must
 * match is the logical schema — same columns with the same semantics, same
 * uniqueness/FK/CHECK guarantees, same referential actions. So this compares
 * NORMALISED FACTS, not `SHOW CREATE TABLE` text:
 *
 *   columns  -> name, data type, nullability, default, extra (auto/on-update)
 *   indexes  -> uniqueness + ORDERED column list (name-insensitive by default)
 *   FKs      -> referenced table/column + ON DELETE / ON UPDATE action
 *   checks   -> normalised expression text
 *
 * Index/constraint NAMES are compared separately and reported as INFO rather
 * than as a failure, except for names listed in $nameCritical — those are
 * parsed by application code and must match exactly.
 *
 * Usage: php tests/Manual/schema_diff.php
 */

$legacyDb = 'sanad_platform_test';
$newDb = 'sanad_platform_v2';

/** Index/constraint names that application code string-matches. MUST be identical. */
$nameCritical = [
    // backend/app/Repositories/EnrollmentCodeRepository.php:62 does
    // str_contains($e->getMessage(), 'uq_enrollment_codes_code')
    'uq_enrollment_codes_code',
];

$pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

/** @return array<string, array<string,string>> table => column => signature */
function columns(PDO $pdo, string $db): array
{
    $rows = $pdo->prepare('
        SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
        ORDER BY TABLE_NAME, COLUMN_NAME
    ');
    $rows->execute([$db]);

    $out = [];
    foreach ($rows as $r) {
        $default = $r['COLUMN_DEFAULT'];
        // MySQL renders the same default differently across contexts
        // ("CURRENT_TIMESTAMP" vs "current_timestamp()"); normalise.
        $default = $default === null ? 'NULL' : strtolower(trim((string) $default, "'"));
        $default = str_replace(['current_timestamp()', 'current_timestamp'], 'CURRENT_TIMESTAMP', $default);

        $out[$r['TABLE_NAME']][$r['COLUMN_NAME']] = sprintf(
            'type=%s null=%s default=%s extra=%s',
            strtolower($r['COLUMN_TYPE']),
            $r['IS_NULLABLE'],
            $default,
            strtolower(str_replace('DEFAULT_GENERATED', '', $r['EXTRA'])) ?: '-'
        );
    }

    return $out;
}

/** @return array<string, array<string,string>> table => "unique|cols" => index name */
function indexes(PDO $pdo, string $db): array
{
    $rows = $pdo->prepare('
        SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = ?
        ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
    ');
    $rows->execute([$db]);

    $byName = [];
    foreach ($rows as $r) {
        $key = $r['TABLE_NAME'] . '|' . $r['INDEX_NAME'];
        $byName[$key]['table'] = $r['TABLE_NAME'];
        $byName[$key]['name'] = $r['INDEX_NAME'];
        $byName[$key]['unique'] = $r['NON_UNIQUE'] == 0 ? 'UNIQUE' : 'INDEX';
        $byName[$key]['cols'][] = $r['COLUMN_NAME'];
    }

    $out = [];
    foreach ($byName as $i) {
        $sig = $i['unique'] . '(' . implode(',', $i['cols']) . ')';
        $out[$i['table']][$sig] = $i['name'];
    }

    return $out;
}

/** @return array<string, array<string,string>> table => "col->reftable.refcol" => "DELETE/UPDATE" */
function foreignKeys(PDO $pdo, string $db): array
{
    $rows = $pdo->prepare('
        SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
               r.DELETE_RULE, r.UPDATE_RULE, k.CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA
         AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY k.TABLE_NAME, k.COLUMN_NAME
    ');
    $rows->execute([$db]);

    $out = [];
    foreach ($rows as $r) {
        $key = sprintf('%s -> %s.%s', $r['COLUMN_NAME'], $r['REFERENCED_TABLE_NAME'], $r['REFERENCED_COLUMN_NAME']);
        $out[$r['TABLE_NAME']][$key] = sprintf('ON DELETE %s / ON UPDATE %s', $r['DELETE_RULE'], $r['UPDATE_RULE']);
    }

    return $out;
}

/** @return array<string, array<string,string>> table => normalised expression => constraint name */
function checks(PDO $pdo, string $db): array
{
    $rows = $pdo->prepare('
        SELECT t.TABLE_NAME, c.CONSTRAINT_NAME, c.CHECK_CLAUSE
        FROM information_schema.CHECK_CONSTRAINTS c
        JOIN information_schema.TABLE_CONSTRAINTS t
          ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA
         AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME
        WHERE c.CONSTRAINT_SCHEMA = ?
        ORDER BY t.TABLE_NAME, c.CONSTRAINT_NAME
    ');
    $rows->execute([$db]);

    $out = [];
    foreach ($rows as $r) {
        // Strip backticks and collapse whitespace so formatting differences
        // between the hand-written SQL and the migration do not read as a diff.
        $expr = preg_replace('/\s+/', ' ', str_replace('`', '', $r['CHECK_CLAUSE']));
        $out[$r['TABLE_NAME']][strtolower(trim($expr))] = $r['CONSTRAINT_NAME'];
    }

    return $out;
}

$problems = 0;
$infos = 0;

function fail(string $msg): void
{
    global $problems;
    $problems++;
    echo "  [DIFF] {$msg}\n";
}

function info(string $msg): void
{
    global $infos;
    $infos++;
    echo "  [name] {$msg}\n";
}

$oldCols = columns($pdo, $legacyDb);
$newCols = columns($pdo, $newDb);
$oldIdx = indexes($pdo, $legacyDb);
$newIdx = indexes($pdo, $newDb);
$oldFk = foreignKeys($pdo, $legacyDb);
$newFk = foreignKeys($pdo, $newDb);
$oldChk = checks($pdo, $legacyDb);
$newChk = checks($pdo, $newDb);

// backend-v2 legitimately has Laravel's own migrations table; backend/ has its
// own runner-state table. Neither is part of the domain schema.
$ignoreTables = ['migrations', 'schema_migrations'];

$oldTables = array_diff(array_keys($oldCols), $ignoreTables);
$newTables = array_diff(array_keys($newCols), $ignoreTables);

echo "══ TABLE INVENTORY ══\n";
echo '  legacy (' . $legacyDb . '): ' . count($oldTables) . " tables\n";
echo '  new    (' . $newDb . '): ' . count($newTables) . " tables\n";

$missing = array_diff($oldTables, $newTables);
$extra = array_diff($newTables, $oldTables);
if ($missing) {
    fail('tables MISSING from backend-v2: ' . implode(', ', $missing));
}
if ($extra) {
    fail('tables EXTRA in backend-v2: ' . implode(', ', $extra));
}
if (!$missing && !$extra) {
    echo "  identical table set\n";
}

$shared = array_intersect($oldTables, $newTables);
sort($shared);

foreach ($shared as $t) {
    $lines = [];

    // ── columns
    foreach ($oldCols[$t] as $col => $sig) {
        if (!isset($newCols[$t][$col])) {
            $lines[] = "column MISSING: {$col} ({$sig})";
        } elseif ($newCols[$t][$col] !== $sig) {
            $lines[] = "column {$col}\n           old: {$sig}\n           new: {$newCols[$t][$col]}";
        }
    }
    foreach (array_diff_key($newCols[$t], $oldCols[$t]) as $col => $sig) {
        $lines[] = "column EXTRA: {$col} ({$sig})";
    }

    // ── indexes (by shape, then names)
    foreach ($oldIdx[$t] ?? [] as $sig => $name) {
        if (!isset($newIdx[$t][$sig])) {
            $lines[] = "index MISSING: {$sig} (was `{$name}`)";
        }
    }
    foreach (array_diff_key($newIdx[$t] ?? [], $oldIdx[$t] ?? []) as $sig => $name) {
        $lines[] = "index EXTRA: {$sig} (`{$name}`)";
    }

    // ── foreign keys
    foreach ($oldFk[$t] ?? [] as $edge => $rule) {
        if (!isset($newFk[$t][$edge])) {
            $lines[] = "FK MISSING: {$edge} {$rule}";
        } elseif ($newFk[$t][$edge] !== $rule) {
            $lines[] = "FK RULE differs: {$edge}\n           old: {$rule}\n           new: {$newFk[$t][$edge]}";
        }
    }
    foreach (array_diff_key($newFk[$t] ?? [], $oldFk[$t] ?? []) as $edge => $rule) {
        $lines[] = "FK EXTRA: {$edge} {$rule}";
    }

    // ── checks
    foreach ($oldChk[$t] ?? [] as $expr => $name) {
        if (!isset($newChk[$t][$expr])) {
            $lines[] = "CHECK MISSING: `{$name}` => {$expr}";
        }
    }
    foreach (array_diff_key($newChk[$t] ?? [], $oldChk[$t] ?? []) as $expr => $name) {
        $lines[] = "CHECK EXTRA: `{$name}` => {$expr}";
    }

    if ($lines) {
        echo "\n══ {$t} ══\n";
        foreach ($lines as $l) {
            fail($l);
        }
    }
}

// ── Index/constraint NAME comparison (informational, except the critical list)
echo "\n══ INDEX / CONSTRAINT NAMES ══\n";
$nameDiffs = 0;
foreach ($shared as $t) {
    foreach ($oldIdx[$t] ?? [] as $sig => $oldName) {
        $newName = $newIdx[$t][$sig] ?? null;
        if ($newName !== null && $newName !== $oldName) {
            $nameDiffs++;
            info("{$t}: {$sig} named `{$oldName}` -> `{$newName}`");
        }
    }
}
foreach ($nameCritical as $critical) {
    $foundOld = false;
    $foundNew = false;
    foreach ($oldIdx as $t => $sigs) {
        if (in_array($critical, $sigs, true)) {
            $foundOld = true;
        }
    }
    foreach ($newIdx as $t => $sigs) {
        if (in_array($critical, $sigs, true)) {
            $foundNew = true;
        }
    }
    if ($foundOld && $foundNew) {
        echo "  [OK] name-critical index `{$critical}` present in BOTH databases\n";
    } else {
        fail("name-critical index `{$critical}` missing (legacy=" . var_export($foundOld, true) . ", new=" . var_export($foundNew, true) . ')');
    }
}
if ($nameDiffs === 0) {
    echo "  all index/constraint names identical\n";
}

echo "\n══ RESULT ══\n";
echo "  logical differences: {$problems}\n";
echo "  name-only differences (non-blocking): {$infos}\n";
exit($problems === 0 ? 0 : 1);
