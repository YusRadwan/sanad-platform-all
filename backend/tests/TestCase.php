<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;

abstract class TestCase extends BaseTestCase
{
    use \Tests\Concerns\RegistersGateProbe;

    /**
     * The permanent Gate::before anchor is registered for EVERY test, not only
     * the probe suite — see Tests\Concerns\RegistersGateProbe for why, and
     * Tests\Support\GateProbe for what it replaced (a probe that had migrated
     * across three real policies in five stages and had run out of skeletons).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerGateProbe();
    }

    /**
     * Creates the dedicated test database if it does not exist.
     *
     * `php artisan migrate` can create tables but not the database that holds
     * them, and there is no `mysql` CLI on the target machines — so the same
     * bare-PDO approach database/create_database.php uses is applied here,
     * once per suite run.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $name = env('DB_DATABASE', 'sanad_platform_v2_test');

        // Hard stop: nothing in this suite may ever create, migrate, refresh
        // or otherwise touch the legacy application's database.
        if (in_array($name, ['sanad_platform_test', 'sanad_platform'], true)) {
            throw new \RuntimeException(
                "REFUSING to run tests against '{$name}' — that database belongs to backend/."
            );
        }

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306')),
            (string) env('DB_USERNAME', 'root'),
            (string) env('DB_PASSWORD', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            str_replace('`', '', (string) $name)
        ));
    }
}
