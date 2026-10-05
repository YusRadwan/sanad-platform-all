<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * THE SILENT-BREAKAGE GUARD.
 *
 * backend/app/Core/Database/Connection.php disables PDO::ATTR_EMULATE_PREPARES.
 * If Laravel's connection returned different PHP types than the raw driver,
 * a model's JSON shape could change silently, because nothing in PHP would
 * error.
 *
 * These tests pin the observed behaviour so a future config change, framework
 * upgrade or driver swap fails HERE instead of in production.
 */
final class PdoTypeCastingTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE_ID = '11111111-1111-4111-8111-111111111111';

    private function makeProbeTable(): void
    {
        DB::statement('DROP TABLE IF EXISTS _type_probe');
        DB::statement('
            CREATE TABLE _type_probe (
                id CHAR(36) NOT NULL PRIMARY KEY,
                flag_tinyint1 TINYINT(1) NOT NULL DEFAULT 1,
                digits_tinyint_unsigned TINYINT UNSIGNED NOT NULL,
                money_decimal DECIMAL(10,2) NOT NULL,
                count_int INT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
        DB::table('_type_probe')->insert([
            'id' => self::PROBE_ID,
            'flag_tinyint1' => 1,
            'digits_tinyint_unsigned' => 11,
            'money_decimal' => 199.50,
            'count_int' => 42,
        ]);
    }

    /**
     * Laravel's MySQL connector must have native prepares on, matching
     * backend/'s Connection.php. config/database.php restates both attributes
     * explicitly so a framework default flip cannot change the wire shape.
     */
    public function test_connection_has_native_prepares_and_no_stringify(): void
    {
        $pdo = DB::connection()->getPdo();

        $this->assertSame(
            0,
            (int) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
            'ATTR_EMULATE_PREPARES must be false, matching backend/Connection.php.'
        );
        $this->assertFalse(
            $pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES),
            'ATTR_STRINGIFY_FETCHES must be false or every column would come back as a string.'
        );
    }

    /**
     * The RAW driver behaviour, with no Eloquent casting involved.
     *
     * Observed on MySQL 8.4.3 / PHP 8.3.30 / mysqlnd:
     *   TINYINT(1)        -> int
     *   TINYINT UNSIGNED  -> int
     *   INT               -> int
     *   DECIMAL(10,2)     -> STRING   <-- the one that is not a number
     */
    public function test_raw_driver_types_are_int_for_integers_and_string_for_decimal(): void
    {
        $this->makeProbeTable();

        $row = (array) DB::table('_type_probe')->where('id', self::PROBE_ID)->first();

        $this->assertIsInt($row['flag_tinyint1'], 'TINYINT(1) must come back as int');
        $this->assertIsInt($row['digits_tinyint_unsigned'], 'TINYINT UNSIGNED must come back as int');
        $this->assertIsInt($row['count_int'], 'INT must come back as int');

        // DOCUMENTED, NOT DESIRED: DECIMAL is a string on this driver, because
        // a DECIMAL cannot survive a float round-trip without precision loss.
        // This is IDENTICAL to backend/'s behaviour, which is why backend/'s
        // service layer casts money fields explicitly before serialising.
        // Any model exposing a money column must declare a 'decimal:N' cast.
        $this->assertIsString($row['money_decimal'], 'DECIMAL comes back as a string — same as backend/');
        $this->assertSame('199.50', $row['money_decimal']);

        DB::statement('DROP TABLE _type_probe');
    }

    /**
     * A raw TINYINT(1) read is an int 1/0, NOT a bool — so a model that omits
     * the 'boolean' cast would ship `"is_active": 1` and break a strict
     * TypeScript consumer expecting `boolean`.
     */
    public function test_tinyint1_is_int_not_bool_without_a_cast(): void
    {
        $this->makeProbeTable();

        $raw = (array) DB::table('_type_probe')->where('id', self::PROBE_ID)->first();

        $this->assertIsInt($raw['flag_tinyint1']);
        $this->assertNotSame(true, $raw['flag_tinyint1']);

        DB::statement('DROP TABLE _type_probe');
    }

}
