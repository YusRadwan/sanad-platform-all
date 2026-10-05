<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE VERIFICATION ROUTES MUST NOT EXIST OUTSIDE THE TEST ENVIRONMENT.     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * routes/testing.php mounts unauthenticated-by-design and policy-probing
 * endpoints. They are harmless in a suite and unacceptable anywhere else, and
 * "it is behind an if" is a claim, not a proof — the kind that survives a
 * refactor by looking correct.
 *
 * So this test does not read the guard, it MEASURES THE OUTPUT: it shells out
 * to a real `php artisan route:list` in a NON-testing environment and asserts
 * `_verify` appears nowhere in the compiled table. That is the same command an
 * operator would run, answering the same question they would ask.
 */
final class VerificationRouteIsolationTest extends TestCase
{
    public function test_the_verification_routes_exist_in_the_testing_environment(): void
    {
        // The control. Without this, the absence asserted below could equally
        // mean the file is broken and registers nothing anywhere.
        $this->assertNotNull(
            Route::getRoutes()->getByName('_verify.courses.manage'),
            'routes/testing.php did not register under APP_ENV=testing'
        );

        $this->assertSame('testing', $this->app->environment());
    }

    public function test_route_list_outside_the_testing_environment_contains_no_verification_route(): void
    {
        $output = $this->artisanRouteList('local');

        $this->assertStringNotContainsString('_verify', $output, <<<'MSG'

            LEAK: a `_verify/*` route is present in the route table of a
            non-testing environment. routes/testing.php must be registered only
            from inside the `if ($app->environment('testing'))` guard in
            bootstrap/app.php's `then:` callback.
            MSG);

        // Sanity: the command really did produce a route table, so the
        // assertion above is about absence and not about an empty string.
        $this->assertStringContainsString('api/v1/health', $output);
    }

    public function test_route_list_in_production_contains_no_verification_route(): void
    {
        $output = $this->artisanRouteList('production');

        $this->assertStringNotContainsString('_verify', $output);
        $this->assertStringContainsString('api/v1/health', $output);
    }

    /**
     * A REAL child process, not Artisan::call().
     *
     * Artisan::call() would run inside THIS container, whose environment is
     * already 'testing' and whose routes are already registered — it could
     * never observe the other branch of the guard. Only a fresh process boots
     * the application again with a different APP_ENV.
     */
    private function artisanRouteList(string $environment): string
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'route:list', '--no-ansi'],
            base_path(),
            // APP_ENV is what bootstrap/app.php's guard reads. DB_DATABASE is
            // pinned to the v2 test database as a belt-and-braces measure: the
            // command touches no tables, but a child process must never be
            // able to inherit its way onto another database.
            ['APP_ENV' => $environment, 'DB_DATABASE' => 'sanad_platform_v2_test'],
        );

        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertTrue(
            $process->isSuccessful(),
            "artisan route:list --env={$environment} failed:\n{$output}"
        );

        return $output;
    }
}
