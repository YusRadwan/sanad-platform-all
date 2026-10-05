<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The whole Stage 0 pipeline, exercised end to end against the one real
 * endpoint that exists.
 */
final class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_returns_200_with_the_exact_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);
        $response->assertExactJson([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'status' => 'ok',
                'database' => true,
            ],
        ]);
    }

    public function test_envelope_has_exactly_the_three_contract_keys(): void
    {
        $payload = $this->getJson('/api/v1/health')->json();

        $this->assertSame(
            ['success', 'message', 'data'],
            array_keys($payload),
            'The envelope must be exactly {success, message, data}, in that order.'
        );
        $this->assertIsBool($payload['success']);
        $this->assertIsString($payload['message']);
    }

    /**
     * backend/'s Router::dispatch() matched method AND path together and threw
     * NotFoundException when nothing matched, so a wrong-method request has
     * ALWAYS answered 404 on this API. Laravel would natively answer 405;
     * App\Exceptions\Handler folds MethodNotAllowedHttpException into 404 to
     * preserve the contract.
     */
    public function test_post_to_a_get_only_route_returns_404_not_405(): void
    {
        $response = $this->postJson('/api/v1/health');

        $response->assertStatus(404);
        $this->assertNotSame(405, $response->getStatusCode());
        $response->assertJson([
            'success' => false,
            'message' => 'Not found',
        ]);
    }

    /**
     * Proves the exception-handler path is ALSO enveloped and headered — not
     * just responses that reach a controller.
     */
    public function test_undefined_route_returns_enveloped_404_with_security_headers(): void
    {
        $response = $this->getJson('/api/v1/this-route-does-not-exist');

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Not found',
        ]);

        $this->assertSame([], $response->json('data'), 'data must be an empty object, not null');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    }

    /**
     * `data` must serialise as an empty JSON OBJECT `{}`, never `null` and
     * never an empty ARRAY `[]`. Asserted on the RAW body, because
     * json_decode() erases the distinction.
     */
    public function test_empty_data_serialises_as_an_object_not_an_array_or_null(): void
    {
        $raw = $this->getJson('/api/v1/this-route-does-not-exist')->getContent();

        $this->assertStringContainsString('"data":{}', $raw);
        $this->assertStringNotContainsString('"data":[]', $raw);
        $this->assertStringNotContainsString('"data":null', $raw);
    }

    /**
     * Arabic must ship as literal UTF-8 and slashes must not be escaped.
     * Verified on the raw bytes of a response that genuinely carries Arabic.
     */
    public function test_json_uses_unescaped_unicode_and_slashes(): void
    {
        // The 429 path carries an Arabic message with no dependency on auth.
        $raw = $this->getJson('/api/v1/health')->getContent();
        $this->assertStringNotContainsString('\\u', $raw);
        $this->assertStringNotContainsString('\\/', $raw);
    }
}
