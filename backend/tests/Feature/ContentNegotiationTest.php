<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * THE VENDOR MEDIA-TYPE QUIRK.
 *
 * backend/app/Core/Request.php::parseBody() treats a Content-Type containing
 * `application/vnd.sanad` as JSON:
 *
 *     if (str_contains($contentType, 'application/json')
 *         || str_contains($contentType, 'application/vnd.sanad')) { ...decode... }
 *
 * The question this file answers empirically: does Laravel do the same, or
 * would a request with that content type silently arrive with an EMPTY body?
 * A silently-empty body is the worst possible failure mode — it surfaces as a
 * confusing 422 "field required" on a request that plainly contained the field.
 *
 * WHAT THE FIXED FRONTEND ACTUALLY SENDS (frontend/src/services/api.ts, read
 * directly):
 *     headers: {
 *       'Content-Type': 'application/json',                 <- STANDARD
 *       'Accept':       'application/vnd.sanad.v1+json',    <- vendor type
 *       'API-Version':  apiVersion,
 *     }
 * So the vendor media type is only ever an ACCEPT header, never a
 * Content-Type. The risk is therefore theoretical for this client — but it is
 * cheap to pin, and a future client (or a hand-rolled curl in a runbook) could
 * send it as a Content-Type.
 */
final class ContentNegotiationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->post(
            '/api/v1/_echo',
            fn (\Illuminate\Http\Request $r) => response()->json(['received' => $r->all()])
        );
    }

    /**
     * Laravel's Request::isJson() matches on '+json' as well as '/json', so
     * the vendor media type IS recognised and the body IS decoded. No
     * normalising middleware is required — verified, not assumed.
     */
    public function test_vendor_content_type_body_is_parsed_not_silently_empty(): void
    {
        $this->startSession();

        $response = $this->call(
            'POST',
            '/api/v1/_echo',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/vnd.sanad.v1+json',
                'HTTP_ACCEPT' => 'application/vnd.sanad.v1+json',
                'HTTP_X_CSRF_TOKEN' => session()->token(),
            ],
            json_encode(['field' => 'قيمة'])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['field' => 'قيمة'],
            $response->json('data.received'),
            'A vnd.sanad.v1+json body must be decoded, not silently dropped.'
        );
    }

    /** The standard content type the frontend actually sends. */
    public function test_standard_json_content_type_body_is_parsed(): void
    {
        $this->startSession();

        $response = $this->call(
            'POST',
            '/api/v1/_echo',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/vnd.sanad.v1+json',
                'HTTP_X_CSRF_TOKEN' => session()->token(),
            ],
            json_encode(['field' => 'value'])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['field' => 'value'], $response->json('data.received'));
    }

    /**
     * The vendor ACCEPT header — what the frontend really sends on every
     * request — must make Laravel answer with JSON, not an HTML error page.
     */
    public function test_vendor_accept_header_yields_json_errors_not_html(): void
    {
        $response = $this->get('/api/v1/nope', [
            'Accept' => 'application/vnd.sanad.v1+json',
        ]);

        $response->assertStatus(404);
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $response->assertJson(['success' => false]);
    }

    /**
     * Even a plain browser Accept header must not escape the envelope — the
     * app is API-only and has no HTML error pages.
     */
    public function test_browser_accept_header_still_yields_the_json_envelope(): void
    {
        $response = $this->get('/api/v1/nope', [
            'Accept' => 'text/html,application/xhtml+xml',
        ]);

        $response->assertStatus(404);
        $response->assertJson(['success' => false, 'message' => 'Not found']);
        $this->assertStringNotContainsString('<!DOCTYPE', $response->getContent());
    }
}
