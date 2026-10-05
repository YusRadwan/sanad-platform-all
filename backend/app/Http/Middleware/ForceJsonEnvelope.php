<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Guarantees that EVERY response leaving the API group carries the
 * {success, message, data} envelope with the required JSON encoding flags.
 *
 * WHY THIS IS A GROUP-WIDE MIDDLEWARE AND NOT A TRAIT OR JsonResource::wrap()
 * ------------------------------------------------------------------
 * A controller trait only wraps what a controller returns. A great many API
 * responses never reach a controller at all:
 *   - 422 from FormRequest validation (thrown during binding)
 *   - 401 from the auth middleware
 *   - 403/419 from CSRF or authorization
 *   - 429 from the rate limiter
 *   - 404 from route-model binding, or from no route matching at all
 *   - 405 rewritten to 404 (see App\Exceptions\Handler)
 * All of those are produced by the framework, above or beside the controller.
 * A middleware sits outside every one of them, so there is exactly one place
 * that can promise the envelope — and no way for a future endpoint to forget
 * it.
 *
 * IDEMPOTENCE: a response that already has the envelope (because it came from
 * ApiResponse or the exception handler) is only re-encoded to fix the JSON
 * flags, never double-wrapped. isEnvelope() decides that.
 *
 * STREAMED/FILE RESPONSES pass through untouched — a download must not be
 * turned into JSON. Stage 1's file-serving endpoints depend on this.
 */
final class ForceJsonEnvelope
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // BinaryFileResponse / StreamedResponse: file downloads, never JSON.
        if (!$response instanceof JsonResponse) {
            return $this->wrapNonJson($response);
        }

        $payload = $response->getData(true);

        if ($this->isEnvelope($payload)) {
            // Already shaped correctly — but the flags may not be ours (e.g. a
            // JsonResponse built by the framework). Re-encode to guarantee
            // unescaped unicode and slashes, and to normalise an empty `data`.
            $payload['data'] = ApiResponse::normalise($payload['data']);
            $response->setEncodingOptions(ApiResponse::JSON_FLAGS);
            $response->setData($payload);

            return $response;
        }

        $wrapped = ApiResponse::make(
            $response->isSuccessful(),
            $this->messageFor($response->getStatusCode(), $payload),
            $this->dataFor($payload),
            $response->getStatusCode()
        );

        // Preserve headers set further down the stack (Retry-After, cookies,
        // CORS headers added by HandleCors, …). The envelope only rewrites the
        // BODY; it must never drop a header another layer decided to send.
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            if (strtolower($name) === 'content-type') {
                continue;
            }
            $wrapped->headers->set($name, $values);
        }
        foreach ($response->headers->getCookies() as $cookie) {
            $wrapped->headers->setCookie($cookie);
        }

        return $wrapped;
    }

    /**
     * Non-JSON responses (empty 204s, redirects, downloads).
     *
     * Only a genuinely EMPTY response body is converted — anything with real
     * content is a deliberate non-JSON payload and is left alone.
     */
    private function wrapNonJson(Response $response): Response
    {
        /*
         * ┌──────────────────────────────────────────────────────────────────┐
         * │ STAGE 9 — A FILE RESPONSE IS NEVER TOUCHED, AND THE CHECK HAD TO │
         * │ BE EXPLICIT. THIS WAS A LATENT BUG, NOT A NEW REQUIREMENT.       │
         * │                                                                  │
         * │ This class has claimed since Stage 0 that "STREAMED/FILE         │
         * │ RESPONSES pass through untouched". They did not.                 │
         * │ `BinaryFileResponse::getContent()` returns **false** — the body   │
         * │ is a file handle, not a string — and the emptiness test below     │
         * │ reads `getContent() === false` as "empty", so every download      │
         * │ would have been replaced by a `{"success":true,…}` envelope with  │
         * │ a null data key and NOT ONE BYTE of the file.                     │
         * │                                                                  │
         * │ Nothing produced a BinaryFileResponse before Stage 9, so the      │
         * │ claim was never exercised and the bug never fired. Guarded now by │
         * │ PublicFileServingTest::                                          │
         * │ test_a_served_file_body_is_raw_bytes_with_no_envelope.           │
         * └──────────────────────────────────────────────────────────────────┘
         */
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return $response;
        }

        /*
         * A 204 must stay bodiless. The case that matters in practice is the
         * CORS preflight: HandleCors answers OPTIONS with a bodiless 204, and
         * giving it a JSON body would be a protocol violation. (This
         * middleware is registered INSIDE HandleCors so it should never see
         * one — this is the belt to that braces.)
         */
        if ($response->getStatusCode() === 204 || $response->isRedirection()) {
            return $response;
        }

        $isPlainEmpty = $response->getContent() === '' || $response->getContent() === false;

        if (!$isPlainEmpty) {
            return $response;
        }

        $wrapped = ApiResponse::make(
            $response->isSuccessful(),
            $this->messageFor($response->getStatusCode(), []),
            null,
            $response->getStatusCode()
        );

        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            if (strtolower($name) === 'content-type') {
                continue;
            }
            $wrapped->headers->set($name, $values);
        }
        foreach ($response->headers->getCookies() as $cookie) {
            $wrapped->headers->setCookie($cookie);
        }

        return $wrapped;
    }

    /** A payload is already an envelope if it has all three keys. */
    private function isEnvelope(mixed $payload): bool
    {
        return is_array($payload)
            && array_key_exists('success', $payload)
            && array_key_exists('message', $payload)
            && array_key_exists('data', $payload);
    }

    /**
     * Best-effort message for a response that arrived without one. Prefers a
     * `message` the framework already produced over a generic status phrase.
     */
    private function messageFor(int $status, mixed $payload): string
    {
        if (is_array($payload) && isset($payload['message']) && is_string($payload['message'])) {
            return $payload['message'];
        }

        return match (true) {
            $status >= 500 => 'Internal server error',
            $status >= 400 => 'Error',
            default => 'OK',
        };
    }

    /** Strips a top-level `message` key so it does not appear twice. */
    private function dataFor(mixed $payload): mixed
    {
        if (is_array($payload) && isset($payload['message']) && count($payload) === 1) {
            return null;
        }

        return $payload;
    }
}
