<?php

declare(strict_types=1);

/**
 * EMPIRICAL PDO type probe — the evidence behind config/database.php's
 * `options` array. Run it, do not reason about it from memory.
 *
 * backend/app/Core/Database/Connection.php sets PDO::ATTR_EMULATE_PREPARES =>
 * false, and backend/app/Services/CurriculumService.php::present() then casts
 * `phone_digits` back to (int) and `is_active` back to (bool) by hand. The
 * question this script answers is: does Laravel's Eloquent/query-builder
 * connection, out of the box, hand back native PHP int/float for TINYINT and
 * DECIMAL — or strings, which would silently break the frontend's strict
 * TypeScript types (frontend/src/types/curriculum.ts declares
 * `phone_digits: number` and `is_active: boolean`)?
 *
 * It probes four connection shapes against a throwaway table so the result is
 * attributable to a specific PDO attribute and not to guesswork:
 *   A. raw PDO, EMULATE_PREPARES not set (the PHP default = true)
 *   B. raw PDO, EMULATE_PREPARES = false          (what backend/ does)
 *   C. Laravel DB::select() with the framework's default connector options
 *   D. Laravel Eloquent-style query builder ->first()
 *
 * Usage: php tests/Manual/pdo_type_probe.php
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$cfg = config('database.connections.mysql');
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $cfg['host'],
    $cfg['port'],
    $cfg['database'],
    $cfg['charset']
);

echo "MySQL: " . DB::selectOne('SELECT VERSION() AS v')->v . "\n";
echo "Database: " . $cfg['database'] . "\n";
echo "PHP: " . PHP_VERSION . "\n\n";

DB::statement('DROP TABLE IF EXISTS _type_probe');
DB::statement('
    CREATE TABLE _type_probe (
        id CHAR(36) NOT NULL PRIMARY KEY,
        flag_tinyint1 TINYINT(1) NOT NULL DEFAULT 1,
        digits_tinyint_unsigned TINYINT UNSIGNED NOT NULL,
        money_decimal DECIMAL(10,2) NOT NULL,
        pct_decimal DECIMAL(5,2) NULL,
        count_int INT NOT NULL,
        maybe_null_decimal DECIMAL(6,2) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');
DB::table('_type_probe')->insert([
    'id' => '11111111-1111-4111-8111-111111111111',
    'flag_tinyint1' => 1,
    'digits_tinyint_unsigned' => 11,
    'money_decimal' => 199.50,
    'pct_decimal' => 70.00,
    'count_int' => 42,
    'maybe_null_decimal' => null,
]);

$sql = 'SELECT flag_tinyint1, digits_tinyint_unsigned, money_decimal, pct_decimal, count_int, maybe_null_decimal FROM _type_probe WHERE id = ?';
$id = '11111111-1111-4111-8111-111111111111';

/** Renders a value as "phpType(literal)". */
$describe = static function (mixed $v): string {
    return get_debug_type($v) . '(' . var_export($v, true) . ')';
};

$report = static function (string $label, array $row) use ($describe): void {
    echo "── {$label}\n";
    foreach ($row as $col => $val) {
        printf("     %-26s %s\n", $col, $describe($val));
    }
    echo "\n";
};

// A. raw PDO, emulated prepares (PHP's default when the attribute is unset)
$a = new PDO($dsn, $cfg['username'], $cfg['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$st = $a->prepare($sql);
$st->execute([$id]);
$report('A. raw PDO, ATTR_EMULATE_PREPARES unset (PHP default: true)', $st->fetch());

// B. raw PDO, native prepares — exactly what backend/ configures
$b = new PDO($dsn, $cfg['username'], $cfg['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$st = $b->prepare($sql);
$st->execute([$id]);
$report('B. raw PDO, ATTR_EMULATE_PREPARES = false (backend/ Connection.php)', $st->fetch());

// C. Laravel DB::select through the framework's own connector
$report('C. Laravel DB::selectOne()', (array) DB::selectOne($sql, [$id]));

// D. Laravel query builder (the shape an Eloquent model will use)
$report('D. Laravel query builder ->first()', (array) DB::table('_type_probe')->where('id', $id)->first());

// What the live connection actually has set, so the result above is attributable.
$pdo = DB::connection()->getPdo();
echo "── Effective PDO attributes on Laravel's connection\n";
foreach ([
    'ATTR_EMULATE_PREPARES' => PDO::ATTR_EMULATE_PREPARES,
    'ATTR_STRINGIFY_FETCHES' => PDO::ATTR_STRINGIFY_FETCHES,
    'ATTR_CASE' => PDO::ATTR_CASE,
    'ATTR_ORACLE_NULLS' => PDO::ATTR_ORACLE_NULLS,
] as $name => $attr) {
    try {
        printf("     %-24s %s\n", $name, var_export($pdo->getAttribute($attr), true));
    } catch (Throwable $e) {
        printf("     %-24s <unsupported by driver>\n", $name);
    }
}

echo "\n── JSON encoding of the Laravel row (what the frontend would receive)\n";
echo '     ' . json_encode(
    DB::table('_type_probe')->where('id', $id)->first(),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . "\n";

DB::statement('DROP TABLE _type_probe');
echo "\nThrowaway table dropped.\n";
