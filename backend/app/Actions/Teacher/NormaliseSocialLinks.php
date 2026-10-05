<?php

declare(strict_types=1);

namespace App\Actions\Teacher;

use App\Support\SafeUrl;
use Illuminate\Validation\ValidationException;

/**
 * Turns AddTeacher.tsx's `socialLinks: [{platform, url, enabled?, order?}]`
 * into complete rows for `teacher_social_links`.
 *
 * Port of backend/TeacherService::normalizeSocialLinks(). Its behaviour was
 * READ FROM THE CODE, not from its own comment, because the two disagree — see
 * the boxed note below.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ TWO DIFFERENT FAILURE MODES, AND THE DISTINCTION IS THE WHOLE POINT.    │
 * │                                                                          │
 * │  A) INCOMPLETE ENTRY -> SILENTLY DROPPED, request still succeeds.        │
 * │     · not an object                                                      │
 * │     · empty/absent `platform` (it is the row's identity)                 │
 * │     · empty/absent `url`                                                 │
 * │     The admin form renders a row per platform whether or not the admin   │
 * │     filled it in, so a half-empty entry is NORMAL input, not an error.   │
 * │     Storing it would put a dead icon on the public profile; rejecting    │
 * │     the whole save would make the form unusable.                         │
 * │                                                                          │
 * │  B) UNSAFE URL -> HARD 422, the whole request fails.                     │
 * │     `javascript:`, `data:`, `//evil.example`, control characters, …      │
 * │     These end up in an <a href> on the PUBLIC profile page, so accepting │
 * │     one is stored XSS. Dropping it silently would be almost as bad: the  │
 * │     admin would believe the link saved, and nobody would learn that      │
 * │     hostile input reached the server.                                    │
 * │                                                                          │
 * │ ⚠ THE LEGACY COMMENT SAYS "an entry with no usable url is dropped",      │
 * │ WHICH READS AS IF (B) WERE ALSO A DROP. IT IS NOT.                       │
 * │ backend/'s call is `UrlValidator::nullable($link['url'] ?? null, …)`,    │
 * │ and that method returns null ONLY for null/'' — for anything else it     │
 * │ delegates to `required()`, which THROWS ValidationException. So an       │
 * │ empty url is dropped and a hostile url is a 422. Verified by reading     │
 * │ backend/app/Http/Requests/UrlValidator.php, and reproduced exactly       │
 * │ here rather than "tidied" into one uniform rule.                         │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `order` DEFAULTS TO THE ENTRY'S OWN INDEX, so a payload that omits the field
 * still round-trips in the order the admin arranged — array_values() first, so
 * the index is positional even if the client sent an object-shaped array.
 */
final class NormaliseSocialLinks
{
    /** The exact sentence backend/UrlValidator::required() raised. */
    public const UNSAFE_URL_MESSAGE =
        'الرابط غير صالح: يُسمح فقط بروابط تبدأ بـ http:// أو https:// أو بمسار داخلي يبدأ بـ /';

    /**
     * @param  mixed $links Raw request input — deliberately `mixed`, because
     *                      the Form Request only guarantees "array or absent"
     *                      and a nested entry can be anything at all.
     * @return list<array{platform: string, url: string, enabled: bool, order_index: int}>
     *
     * @throws ValidationException on an unsafe (as opposed to empty) url.
     */
    public function __invoke(mixed $links): array
    {
        if (!is_array($links)) {
            return [];
        }

        $rows = [];

        foreach (array_values($links) as $index => $link) {
            if (!is_array($link)) {
                continue;
            }

            $platform = trim((string) ($link['platform'] ?? ''));

            if ($platform === '') {
                continue;
            }

            $url = $link['url'] ?? null;

            // (A) — absent or empty url: drop the entry, keep the request.
            if ($url === null || (is_string($url) && trim($url) === '')) {
                continue;
            }

            // (B) — present but unsafe: fail the whole request.
            if (!SafeUrl::isSafe($url)) {
                throw ValidationException::withMessages([
                    'socialLinks.url' => [self::UNSAFE_URL_MESSAGE],
                ]);
            }

            $rows[] = [
                'platform' => $platform,
                'url' => trim((string) $url),
                // `?? true`: a platform the admin left switched on sends no
                // `enabled` key at all in some form versions.
                'enabled' => (bool) ($link['enabled'] ?? true),
                'order_index' => (int) ($link['order'] ?? $index),
            ];
        }

        return $rows;
    }
}
