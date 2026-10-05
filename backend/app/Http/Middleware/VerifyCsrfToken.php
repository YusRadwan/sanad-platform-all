<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │ THE HIGHEST-RISK ADAPTATION IN THIS STAGE. READ BEFORE EDITING.      │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * The frontend is FIXED and cannot be changed. frontend/src/services/api.ts
 * implements a plain double-submit CSRF contract:
 *
 *     function getCookie(name) {
 *       const match = document.cookie.match(...);   // <- plain document.cookie
 *       return match ? decodeURIComponent(match[1]) : '';
 *     }
 *     ...
 *     const csrfToken = <meta csrf-token> || window.__csrf_token__
 *                       || getCookie('sanad_csrf');
 *     config.headers['X-CSRF-Token'] = csrfToken;
 *
 * i.e. it reads an UNENCRYPTED cookie named `sanad_csrf` with a JavaScript
 * regex and echoes it back in a header named `X-CSRF-Token`.
 *
 * Laravel's stock mechanism is fundamentally different in BOTH halves:
 *   - it issues an ENCRYPTED cookie named `XSRF-TOKEN`
 *     (EncryptCookies encrypts it on the way out, decrypts on the way in)
 *   - it reads the header `X-XSRF-TOKEN`, not `X-CSRF-Token`
 *
 * Left as-is, every mutating request from the real frontend would 419: the
 * header name would not be looked at, and even if it were, the value read
 * from an encrypted cookie is ciphertext that can never hash_equals the
 * session token.
 *
 * THREE CHANGES MAKE LARAVEL SPEAK THE EXISTING CONTRACT:
 *
 *   1. getTokenFromRequest() reads config('session.csrf_header')
 *      ('X-CSRF-Token') instead of the hard-coded X-XSRF-TOKEN.
 *
 *   2. newCookie() issues the token under config('session.csrf_cookie')
 *      ('sanad_csrf'), and — critically — with httpOnly = FALSE. A
 *      double-submit cookie MUST be script-readable; that is the entire
 *      mechanism. The SESSION cookie stays HttpOnly, which is what actually
 *      protects the session.
 *
 *   3. `sanad_csrf` is listed in App\Http\Middleware\EncryptCookies::$except
 *      so the value stays plaintext in both directions.
 *
 * WHY THIS IS STILL SAFE: double-submit's security rests on the same-origin
 * policy — a cross-origin attacker can cause a request to be SENT with the
 * victim's cookies, but cannot READ the cookie to copy it into the header.
 * Making the CSRF cookie readable to first-party JS does not change that.
 * (It would matter under XSS, but under XSS the session is already lost.)
 *
 * EXEMPT ROUTES come from config('session.csrf_except') and are declared in
 * Stage 0 even though the routes do not exist yet: login and register are the
 * unauthenticated bootstrap endpoints — a client cannot possibly hold a token
 * before it has a session. Stage 1 adds those routes without reopening this
 * file. backend/'s CsrfMiddleware exempted exactly these two.
 *
 * DIFFERENCE FROM backend/ WORTH KNOWING: the legacy middleware issued the
 * cookie by calling setcookie() on EVERY request, including GETs. Laravel's
 * base class already adds the cookie to every response that passes through
 * (addCookieToResponse), so the behaviour is preserved without the manual
 * call — and unlike setcookie(), it participates properly in the response
 * object, so tests can assert it.
 */
final class VerifyCsrfToken extends Middleware
{
    /**
     * URIs excluded from CSRF verification.
     *
     * Populated from config so the list lives next to the other session/CSRF
     * settings rather than being buried in a class property.
     *
     * @var array<int, string>
     */
    protected $except = [];

    public function __construct(Application $app, Encrypter $encrypter)
    {
        parent::__construct($app, $encrypter);

        $this->except = (array) config('session.csrf_except', []);
    }

    /**
     * Read the submitted token from the header the FIXED frontend actually
     * sends, instead of Laravel's default X-XSRF-TOKEN.
     *
     * The `_token` form field and the parent's other sources are kept, so a
     * non-XHR form post still works — only the header name changes.
     */
    public function getTokenFromRequest($request): ?string
    {
        $header = (string) config('session.csrf_header', 'X-CSRF-Token');

        $token = $request->input('_token') ?: $request->header($header);

        // Deliberately NOT falling back to X-XSRF-TOKEN + decrypt: this
        // application has exactly one CSRF contract, and silently accepting a
        // second one would double the surface for no consumer.
        return is_string($token) ? $token : null;
    }

    /**
     * Issue the token as a PLAINTEXT, SCRIPT-READABLE cookie under the legacy
     * name, so frontend/src/services/api.ts can read it with document.cookie.
     *
     * Everything except the name and the httpOnly flag follows the session
     * cookie's own configuration, so secure/samesite/domain stay consistent
     * between the two.
     */
    protected function newCookie($request, $config): Cookie
    {
        return new Cookie(
            (string) config('session.csrf_cookie', 'sanad_csrf'),
            $request->session()->token(),
            0,                                  // session cookie: expires with the browser
            $config['path'],
            $config['domain'],
            $config['secure'],
            false,                              // httpOnly = false — REQUIRED for double-submit
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false
        );
    }

    /**
     * Laravel throws TokenMismatchException (419) on failure. The API contract
     * in backend/ answered 403 for a bad CSRF token
     * (CsrfMiddleware threw ForbiddenException).
     *
     * The mapping from TokenMismatchException to a 403 envelope lives in
     * App\Exceptions\Handler, next to every other exception mapping, rather
     * than being thrown differently here — so there is one table to read when
     * asking "what status does X produce?".
     */
    public function handle($request, Closure $next): Response
    {
        return parent::handle($request, $next);
    }
}
