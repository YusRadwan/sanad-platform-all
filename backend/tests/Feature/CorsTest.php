<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CORS must reflect the LITERAL calling Origin (never "*"), allow credentials,
 * and permit the exact header set frontend/src/services/api.ts sends.
 *
 * phpunit.xml sets CORS_ALLOWED_ORIGINS to two origins for these tests.
 */
final class CorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowed_origin_is_reflected_literally_not_wildcarded(): void
    {
        $response = $this->getJson('/api/v1/health', [
            'Origin' => 'http://localhost:5173',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_second_allowed_origin_is_also_reflected(): void
    {
        $this->getJson('/api/v1/health', ['Origin' => 'https://app.sanad.test'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.sanad.test');
    }

    public function test_credentials_are_allowed(): void
    {
        $this->getJson('/api/v1/health', ['Origin' => 'http://localhost:5173'])
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    /**
     * An origin outside the allow-list must never be REFLECTED back.
     *
     * ┌──────────────────────────────────────────────────────────────────┐
     * │ A SUBTLETY FOUND BY LIVE TESTING, NOT BY THIS SUITE.             │
     * │                                                                  │
     * │ Laravel's HandleCors delegates to asm89/stack-cors, whose        │
     * │ configureAllowedOrigin() has a special case:                     │
     * │                                                                  │
     * │   } elseif ($this->isSingleOriginAllowed()) {                    │
     * │       setHeader($response, 'Access-Control-Allow-Origin',        │
     * │                 array_values($allowedOrigins)[0]);               │
     * │                                                                  │
     * │ i.e. when EXACTLY ONE origin is configured (and no patterns), it │
     * │ emits that origin STATICALLY on every response, without checking │
     * │ the caller's Origin at all. Against the dev .env — which lists   │
     * │ only http://localhost:5173 — a request from evil.example.com     │
     * │ therefore comes back carrying                                    │
     * │ `Access-Control-Allow-Origin: http://localhost:5173`.            │
     * │                                                                  │
     * │ THIS IS NOT A VULNERABILITY: the browser compares that value     │
     * │ against its OWN origin and blocks the response when they differ. │
     * │ The header is static, not reflective, so no attacker origin is   │
     * │ ever echoed. It does differ from backend/'s CorsMiddleware,      │
     * │ which only ever emitted a MATCHED origin — noted in the report.  │
     * │                                                                  │
     * │ This test pins the property that actually matters and holds in   │
     * │ both configurations: the attacker's own origin is never echoed.  │
     * │ phpunit.xml configures two origins, which also exercises the     │
     * │ multi-origin branch where the header is omitted entirely.        │
     * └──────────────────────────────────────────────────────────────────┘
     */
    public function test_disallowed_origin_is_never_reflected_back(): void
    {
        $response = $this->getJson('/api/v1/health', [
            'Origin' => 'https://evil.example.com',
        ]);

        $allowOrigin = $response->headers->get('Access-Control-Allow-Origin');

        $this->assertNotSame(
            'https://evil.example.com',
            $allowOrigin,
            'An origin outside the allow-list must NEVER be reflected back.'
        );
        $this->assertNotSame('*', $allowOrigin, 'Credentialed CORS must never use a wildcard.');

        // With the two-origin test config, stack-cors omits the header outright.
        $this->assertNull($allowOrigin);
    }

    /**
     * Documents the single-origin branch described above, so the behaviour is
     * a known, asserted property rather than a surprise in production.
     */
    public function test_single_origin_config_emits_a_static_never_reflective_header(): void
    {
        config()->set('cors.allowed_origins', ['http://localhost:5173']);

        $response = $this->getJson('/api/v1/health', [
            'Origin' => 'https://evil.example.com',
        ]);

        $allowOrigin = $response->headers->get('Access-Control-Allow-Origin');

        // The configured origin, NOT the caller's — the browser will reject it.
        $this->assertSame('http://localhost:5173', $allowOrigin);
        $this->assertNotSame('https://evil.example.com', $allowOrigin);
    }

    /**
     * The preflight is where the header allow-list actually matters: a browser
     * refuses to send the real request if a non-safelisted header it intends
     * to use is missing here. `X-CSRF-Token` is exactly such a header.
     */
    public function test_preflight_allows_every_header_the_frontend_sends(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/health', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,accept,api-version,x-csrf-token,x-requested-with',
        ]);

        $this->assertSame(204, $response->getStatusCode());

        $allowed = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));

        foreach (['content-type', 'accept', 'api-version', 'x-csrf-token', 'x-requested-with'] as $header) {
            $this->assertStringContainsString(
                $header,
                $allowed,
                "CORS must allow the '{$header}' request header."
            );
        }
    }

    public function test_preflight_allows_the_six_methods(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/health', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'DELETE',
        ]);

        $allowed = strtoupper((string) $response->headers->get('Access-Control-Allow-Methods'));

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $this->assertStringContainsString($method, $allowed);
        }
    }
}
