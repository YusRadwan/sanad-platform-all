<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The baseline security headers must be present and EXACT on every response —
 * successes and errors alike.
 */
final class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> The exact expected header set. */
    private function expected(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        ];
    }

    public function test_all_five_headers_present_and_exact_on_a_200(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);

        foreach ($this->expected() as $name => $value) {
            $response->assertHeader($name, $value);
        }
    }

    public function test_all_five_headers_present_and_exact_on_a_404(): void
    {
        $response = $this->getJson('/api/v1/nope');

        $response->assertStatus(404);

        foreach ($this->expected() as $name => $value) {
            $response->assertHeader($name, $value);
        }
    }

    public function test_x_powered_by_is_removed(): void
    {
        $this->getJson('/api/v1/health')->assertHeaderMissing('X-Powered-By');
    }

    /**
     * REGRESSION GUARD.
     *
     * HandleCors SHORT-CIRCUITS a preflight — it builds the 204 and returns
     * without calling $next — so any middleware registered after it never sees
     * an OPTIONS request. With SecurityHeaders registered after HandleCors, a
     * live `curl -X OPTIONS` came back with none of these headers and a leaked
     * `X-Powered-By: PHP/8.3.30`. SecurityHeaders is therefore registered
     * OUTSIDE HandleCors (see bootstrap/app.php).
     *
     * This test failed before that reordering and passes after it.
     */
    public function test_cors_preflight_also_carries_the_security_headers(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/health', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertSame(204, $response->getStatusCode());

        foreach ($this->expected() as $name => $value) {
            $this->assertSame(
                $value,
                $response->headers->get($name),
                "The CORS preflight response is missing the {$name} header."
            );
        }

        $this->assertNull($response->headers->get('X-Powered-By'));
    }

    /** A 204 preflight must stay bodiless — it must not be JSON-enveloped. */
    public function test_cors_preflight_stays_bodiless(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/health', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertEmpty($response->getContent());
    }
}
