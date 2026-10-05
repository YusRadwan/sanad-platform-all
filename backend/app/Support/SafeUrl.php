<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Allow-list check for any user-supplied URL that the frontend later renders
 * into an href / src / window.open target.
 *
 * Port of backend/app/Http/Requests/UrlValidator.php's `isSafe()` half. Only
 * the PREDICATE is ported here; the throw/return-null wrappers are not, because
 * in backend-v2 the two callers want different failure modes and each says so
 * at its own call site (see App\Actions\Teacher\NormaliseSocialLinks).
 *
 * ALLOWED (and nothing else):
 *   - absolute https://host/...
 *   - absolute http://host/...
 *   - internal app paths beginning with a SINGLE '/' (e.g. /courses/abc)
 *
 * REJECTED: javascript:, data:, vbscript:, file:, any other scheme,
 * protocol-relative '//evil.example', bare 'evil.example', anything containing
 * a control character (a "java\nscript:" style filter bypass), and anything
 * unparseable.
 *
 * WHY NOT Laravel's `url` / `active_url` VALIDATION RULE. `url` accepts EVERY
 * scheme PHP's filter knows, javascript: included on some builds, and its
 * scheme-restricting form (`url:http,https`) still admits protocol-relative
 * input in some versions. More importantly the rule cannot express the third
 * allowed shape — a bare internal path — which the admin form legitimately
 * submits. A hand-written predicate that states all three cases is both
 * stricter and readable; the frontend mirror is
 * frontend/src/utils/urlValidation.ts, defence in depth only.
 */
final class SafeUrl
{
    /** Schemes that may appear on an absolute URL. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /** TRUE if $value is a URL this application is willing to render as a link. */
    public static function isSafe(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $url = trim($value);

        if ($url === '') {
            return false;
        }

        // Control characters (NUL, TAB, CR, LF, …) are the classic way to
        // smuggle "java\tscript:alert(1)" past a naive scheme check — browsers
        // strip them BEFORE parsing, so reject outright instead of stripping.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        // Internal app path: exactly one leading slash. '//host' is
        // protocol-relative (inherits https: and goes off-site) and must not be
        // confused with an internal path, so it is rejected here.
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (!in_array(strtolower($parts['scheme']), self::ALLOWED_SCHEMES, true)) {
            return false;
        }

        return $parts['host'] !== '';
    }
}
