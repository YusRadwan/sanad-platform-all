<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The double-submit CSRF contract the FIXED frontend already implements:
 * an UNENCRYPTED `sanad_csrf` cookie, echoed back as an `X-CSRF-Token` header.
 *
 * These tests register a throwaway POST route inside the real `api` middleware
 * group, because Stage 0 has no mutating endpoint of its own yet — but the
 * CSRF plumbing is foundational config and must be proven now, not in Stage 1.
 */
final class CsrfContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A stand-in mutating endpoint, inside the real api group.
        Route::middleware('api')->post('/api/v1/_csrf_probe', fn () => response()->json(['ok' => true]));
    }

    /**
     * Laravel's VerifyCsrfToken SHORT-CIRCUITS ITSELF UNDER TESTS:
     *
     *   if ($this->isReading($request) || $this->runningUnitTests() || ...)
     *
     * and runningUnitTests() is simply `$this['env'] === 'testing'`. Every
     * mutating request in a test therefore sails through with NO token, which
     * means a naive test of the CSRF contract asserts nothing at all — it
     * returns 200 whether the middleware is correct, misconfigured, or absent.
     * (Confirmed the hard way: these two tests returned 200 before this
     * helper existed.)
     *
     * Flipping the container's `env` away from 'testing' for the duration of a
     * request is what makes the middleware genuinely run. Without this, the
     * single highest-risk piece of Stage 0 would be effectively untested.
     */
    private function withCsrfEnforcementOn(callable $fn): mixed
    {
        $original = $this->app['env'];
        $this->app['env'] = 'local';

        try {
            return $fn();
        } finally {
            $this->app['env'] = $original;
        }
    }

    public function test_config_targets_the_legacy_cookie_and_header_names(): void
    {
        $this->assertSame('sanad_csrf', config('session.csrf_cookie'));
        $this->assertSame('X-CSRF-Token', config('session.csrf_header'));
    }

    public function test_session_cookie_matches_the_legacy_contract(): void
    {
        $this->assertSame('sanad_session', config('session.cookie'));
        // 120 minutes == the legacy 7200 seconds.
        $this->assertSame(120, config('session.lifetime'));
        $this->assertSame(7200, config('session.lifetime') * 60);
        $this->assertSame('lax', strtolower((string) config('session.same_site')));
        $this->assertTrue(config('session.http_only'));
    }

    /**
     * `sanad_csrf` must be exempt from encryption, or the frontend would read
     * ciphertext out of document.cookie and every mutating request would 419.
     */
    public function test_csrf_cookie_is_exempt_from_encryption(): void
    {
        $except = (new \ReflectionClass(\App\Http\Middleware\EncryptCookies::class))
            ->getDefaultProperties()['except'];

        $this->assertContains('sanad_csrf', $except);
        $this->assertNotContains(
            'sanad_session',
            $except,
            'The SESSION cookie must stay encrypted — only the CSRF cookie is exempt.'
        );
    }

    /**
     * A GET must not be blocked by CSRF, and it must hand the client a token
     * to use for later mutations. This is the whole reason /health is usable
     * as a pipeline smoke test.
     */
    public function test_get_is_not_blocked_and_issues_the_csrf_cookie(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);

        $cookie = collect($response->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === 'sanad_csrf');

        $this->assertNotNull($cookie, 'GET must issue the sanad_csrf cookie.');
        $this->assertFalse(
            $cookie->isHttpOnly(),
            'The double-submit cookie MUST be script-readable — that is the entire mechanism.'
        );
    }

    /**
     * backend/'s CsrfMiddleware threw ForbiddenException (403) on a bad token.
     * Laravel natively answers 419; App\Exceptions\Handler maps it back to 403.
     */
    public function test_post_without_a_token_is_rejected_as_403_not_419(): void
    {
        $response = $this->withCsrfEnforcementOn(
            fn () => $this->postJson('/api/v1/_csrf_probe', [])
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotSame(419, $response->getStatusCode());
        $response->assertJson(['success' => false, 'message' => 'Forbidden']);
    }

    /** A wrong token must be rejected just as firmly as a missing one. */
    public function test_post_with_a_wrong_token_is_rejected(): void
    {
        $this->startSession();

        $response = $this->withCsrfEnforcementOn(
            fn () => $this->withHeaders(['X-CSRF-Token' => 'not-the-real-token'])
                ->postJson('/api/v1/_csrf_probe', [])
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * The token is accepted from the `X-CSRF-Token` header — NOT Laravel's
     * default `X-XSRF-TOKEN`.
     */
    public function test_post_with_a_valid_x_csrf_token_header_is_accepted(): void
    {
        $this->startSession();
        $token = session()->token();

        $response = $this->withCsrfEnforcementOn(
            fn () => $this->withHeaders(['X-CSRF-Token' => $token])
                ->postJson('/api/v1/_csrf_probe', [])
        );

        $response->assertStatus(200);
    }

    public function test_the_default_laravel_header_is_not_accepted(): void
    {
        $this->startSession();
        $token = session()->token();

        // Same VALID token, wrong header name: the application has exactly one
        // CSRF contract and must not silently support a second.
        $response = $this->withCsrfEnforcementOn(
            fn () => $this->withHeaders(['X-XSRF-TOKEN' => $token])
                ->postJson('/api/v1/_csrf_probe', [])
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * The pre-declared auth-bootstrap exemptions must actually bypass the
     * check, not merely sit in a config array.
     */
    public function test_excepted_paths_bypass_the_csrf_check(): void
    {
        Route::middleware('api')->post('/api/v1/auth/login', fn () => response()->json(['ok' => true]));

        $response = $this->withCsrfEnforcementOn(
            fn () => $this->postJson('/api/v1/auth/login', [])
        );

        $response->assertStatus(200);
    }

    /**
     * Declared in Stage 0 so Stage 1 can add the routes without reopening the
     * CSRF plumbing. backend/'s CsrfMiddleware exempted exactly these two.
     */
    public function test_auth_bootstrap_routes_are_pre_excepted(): void
    {
        $except = (array) config('session.csrf_except');

        $this->assertContains('api/v1/auth/login', $except);
        $this->assertContains('api/v1/auth/register', $except);
    }
}
