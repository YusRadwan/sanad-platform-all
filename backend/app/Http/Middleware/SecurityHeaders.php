<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emits the baseline security headers on EVERY response — success AND error
 * alike. Ported from backend/app/Http/Middleware/SecurityHeadersMiddleware.php.
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │ THIS MIDDLEWARE MUST BE REGISTERED **GLOBALLY**, NOT IN THE `api`    │
 * │ ROUTE GROUP. See bootstrap/app.php.                                  │
 * │                                                                      │
 * │ Route-group middleware only runs after a route has MATCHED, so a     │
 * │ request to an undefined path would produce a 404 with none of these  │
 * │ headers. That was measured, not assumed: with this class in the      │
 * │ group, tests/Feature/SecurityHeadersTest::                           │
 * │ test_all_five_headers_present_and_exact_on_a_404 failed with         │
 * │ "Header [X-Content-Type-Options] not present on response."           │
 * │                                                                      │
 * │ backend/ hit the identical problem — its hand-rolled router threw    │
 * │ NotFoundException before any middleware ran — and worked around it   │
 * │ with a static apply() its exception handler called by hand. Global   │
 * │ registration is the framework-native version of that fix: one code   │
 * │ path, nothing for a future maintainer to remember to call.           │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * Values, and why each one is safe for this application:
 *
 *   X-Content-Type-Options: nosniff
 *       Never let a browser re-sniff our JSON as HTML/JS — the classic
 *       JSON-as-script XSS vector.
 *
 *   X-Frame-Options: DENY
 *       This host serves API JSON only and should never be framed.
 *
 *   Referrer-Policy: strict-origin-when-cross-origin
 *       Do not leak full API paths cross-origin; they contain resource UUIDs.
 *
 *   Permissions-Policy: geolocation=(), microphone=(), camera=()
 *       The frontend has no camera/microphone/geolocation usage (verified: no
 *       getUserMedia / navigator.geolocation anywhere in frontend/src), so
 *       denying them outright breaks nothing.
 *
 *   Content-Security-Policy: default-src 'none'; frame-ancestors 'none';
 *                            base-uri 'none'; form-action 'none'
 *       The correct policy for an API that returns no HTML and loads no
 *       subresources. It has NO effect on axios/XHR consumers — CSP governs
 *       the DOCUMENT's origin, not the contents of a fetch result — so the
 *       frontend is unaffected. It only matters if a response were ever
 *       rendered as a page.
 *
 *   X-Powered-By is REMOVED so the host cannot be probed for a PHP version
 *   banner when expose_php is on.
 *
 * ┌── STAGE 9: THE ONE NARROW EXCEPTION — A BinaryFileResponse KEEPS ITS ────┐
 * │ OWN HEADERS.                                                             │
 * │                                                                          │
 * │ The file routes (GET /files/{category}/{id} and                          │
 * │ GET /content-items/{id}/file) set a STRICTER CSP than the baseline:      │
 * │                                                                          │
 * │     default-src 'none'; sandbox                                          │
 * │                                                                          │
 * │ `sandbox` is the token that matters for a served file — it drops the     │
 * │ document into a unique opaque origin, disabling scripts, forms, plugins  │
 * │ and same-origin access even if the body somehow renders. The baseline    │
 * │ policy above has no `sandbox` directive, so overwriting the route's      │
 * │ header with it would REMOVE a control, on precisely the two endpoints    │
 * │ that emit non-JSON bodies and therefore need it most.                    │
 * │                                                                          │
 * │ The exception is scoped to BinaryFileResponse — not to "any header       │
 * │ already present" — so an ordinary JSON route still cannot opt out of the │
 * │ baseline by setting a laxer value. Nothing else in the application       │
 * │ produces a BinaryFileResponse.                                           │
 * │                                                                          │
 * │ Guarded from both sides: PublicFileServingTest and                       │
 * │ ContentItemDownloadTest assert the file routes carry `sandbox`, and      │
 * │ SecurityHeadersTest asserts every JSON route still carries the baseline. │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class SecurityHeaders
{
    /** @var array<string, string> */
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
    ];

    /**
     * Strict-Transport-Security: only ever emitted on a request the server
     * itself sees as HTTPS ($request->isSecure()). Sending it over plain HTTP
     * is not just pointless — the header only takes effect once a browser has
     * already received it over a trusted HTTPS connection — it would also be
     * actively wrong in local dev (php artisan serve over http://) and in any
     * setup where TLS terminates at a load balancer that forwards plain HTTP,
     * unless X-Forwarded-Proto is trusted (Laravel's TrustProxies handles
     * that already; isSecure() honours it).
     *
     * max-age=31536000 (1 year) + includeSubDomains is the standard baseline.
     * preload is deliberately NOT set here: submission to the HSTS preload
     * list is a one-way, hard-to-reverse operation on the real domain and
     * must be a conscious deploy-time decision, not a default baked into
     * application code.
     */
    private const HSTS_VALUE = 'max-age=31536000; includeSubDomains';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $isFile = $response instanceof BinaryFileResponse;

        foreach (self::HEADERS as $name => $value) {
            // See the class banner: a file response's own, stricter header
            // wins. Every other response takes the baseline unconditionally.
            if ($isFile && $response->headers->has($name)) {
                continue;
            }

            $response->headers->set($name, $value);
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', self::HSTS_VALUE);
        }

        $response->headers->remove('X-Powered-By');
        // header_remove() covers the case where PHP itself (expose_php=On)
        // emits the banner outside Symfony's header bag.
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
