<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A database outage must surface as a clean 503 ENVELOPE — never a raw Laravel
 * exception page, and never a message containing the DSN, database name, user
 * or host (all of which a PDOException message happily includes).
 *
 * Simulated by pointing the connection at bogus credentials at runtime, which
 * is the closest reproducible analogue of the server being unreachable.
 *
 * Deliberately does NOT use RefreshDatabase: this test breaks the connection on
 * purpose, and a transactional rollback afterwards would fail.
 */
final class DatabaseOutageTest extends TestCase
{
    private function breakTheDatabaseConnection(): void
    {
        DB::purge('mysql');

        config()->set('database.connections.mysql.host', '127.0.0.1');
        config()->set('database.connections.mysql.port', '59999'); // nothing listens here
        config()->set('database.connections.mysql.username', 'definitely_not_a_user');
        config()->set('database.connections.mysql.password', 'definitely_not_the_password');
        config()->set('database.connections.mysql.database', 'definitely_not_a_database');

        DB::purge('mysql');
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        parent::tearDown();
    }

    /**
     * /health catches its own failure and reports it structurally — that is
     * the entire job of a readiness probe.
     */
    public function test_health_reports_503_with_the_envelope_when_the_database_is_down(): void
    {
        $this->breakTheDatabaseConnection();

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(503);
        $response->assertExactJson([
            'success' => false,
            'message' => 'الخدمة غير متاحة حالياً',
            'data' => [
                'status' => 'degraded',
                'database' => false,
            ],
        ]);
    }

    public function test_the_503_still_carries_the_security_headers(): void
    {
        $this->breakTheDatabaseConnection();

        $response = $this->getJson('/api/v1/health');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    /**
     * The failure message must never leak connection details.
     */
    public function test_the_503_body_leaks_no_connection_details(): void
    {
        $this->breakTheDatabaseConnection();

        $body = $this->getJson('/api/v1/health')->getContent();

        foreach ([
            'definitely_not_a_user',
            'definitely_not_the_password',
            'definitely_not_a_database',
            'SQLSTATE',
            'PDOException',
            '59999',
        ] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $body,
                "The 503 body must not disclose '{$secret}'."
            );
        }
    }

    /**
     * An UNCAUGHT database failure (i.e. any endpoint that is not the probe)
     * must also become a 503 envelope, via the handler's isDatabaseOutage()
     * branch — not a 500 and not an HTML error page.
     */
    public function test_an_uncaught_database_failure_becomes_a_503_envelope(): void
    {
        \Illuminate\Support\Facades\Route::middleware('api')
            ->get('/api/v1/_db_probe', fn () => DB::select('SELECT 1'));

        $this->breakTheDatabaseConnection();

        $response = $this->getJson('/api/v1/_db_probe');

        $response->assertStatus(503);
        $response->assertJson([
            'success' => false,
            'message' => 'الخدمة غير متاحة حالياً',
        ]);
        $this->assertStringNotContainsString('<!DOCTYPE', $response->getContent());
    }
}
