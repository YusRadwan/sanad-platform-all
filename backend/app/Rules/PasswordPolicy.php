<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Single documented source of truth for what counts as an acceptable password.
 * Ported 1:1 from backend/app/Http/Requests/PasswordPolicy.php.
 *
 * THE POLICY (reasoning preserved from the original):
 *
 *  1. Minimum length 12. Length is the only property that reliably raises
 *     offline-cracking cost against bcrypt hashes (NIST SP 800-63B).
 *
 *  2. Maximum length 200. NOT a security limit — a DoS limit. bcrypt truncates
 *     at 72 bytes anyway, so accepting megabyte "passwords" only burns CPU.
 *
 *  3. At least one letter AND one digit. Deliberately WEAK complexity: heavy
 *     composition rules push users toward "Password1!" and are discouraged by
 *     SP 800-63B. This floor exists only to stop "121212121212" /
 *     "aaaaaaaaaaaa", while leaving long passphrases perfectly usable.
 *
 *  4. No character bans and no whitespace trimming. Every printable character,
 *     including spaces and non-Latin scripts, is allowed.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ WHY THIS IS A CUSTOM RULE AND NOT Illuminate\Validation\Rules\Password.  │
 * │                                                                          │
 * │ Laravel's `Password::min(12)->letters()->numbers()` enforces the same    │
 * │ THREE conditions, but reports them as up to three SEPARATE, framework-   │
 * │ translated messages. The API contract here is one exact Arabic sentence  │
 * │ — describe() — emitted as the single error for the field, whichever      │
 * │ part failed. That string is user-facing, is asserted by the frontend's   │
 * │ signup and PlatformSettings flows, and is listed as mandatory-exact.     │
 * │ A native rule cannot produce it without a full lang-file override that   │
 * │ would then leak into every other `min`/`letters` usage in the app.       │
 * │                                                                          │
 * │ Everything else IS native: this implements ValidationRule, so it drops   │
 * │ into any rules array, any Form Request, and Validator::make().           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NOTE FOR A LATER STAGE: backend/'s TeacherService::create() used a weaker
 * `required|min:8` and TeacherService::update() reset passwords with NO
 * validation at all. Teacher CRUD is out of Stage 1's scope, but when it is
 * built it must reuse THIS rule. See the stage report.
 */
final class PasswordPolicy implements ValidationRule
{
    public const MIN_LENGTH = 12;

    public const MAX_LENGTH = 200;

    /**
     * The length half of the policy expressed as plain rule strings, so a
     * caller that wants `required|string|min|max` semantics (e.g. to reject a
     * non-string before this rule runs) stays consistent with the constants.
     *
     * @return array<int, string|self>
     */
    public static function rules(): array
    {
        return ['required', 'string', new self()];
    }

    /**
     * Human-readable policy text, reused verbatim in every error message so the
     * user is told the whole rule instead of discovering it one rejection at a
     * time. EXACT CONTRACT STRING — do not paraphrase.
     */
    public static function describe(): string
    {
        return 'يجب أن تتكون كلمة المرور من ' . self::MIN_LENGTH
            . ' حرفاً على الأقل (وبحد أقصى ' . self::MAX_LENGTH
            . ' حرفاً) وأن تحتوي على حرف ورقم على الأقل';
    }

    /** True when $value satisfies the whole policy. Used by the Action layer. */
    public static function passes(mixed $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }

        // mb_strlen, not strlen: a 12-character Arabic passphrase must not be
        // rejected for being "too short" because strlen() counts bytes.
        $length = mb_strlen($value);

        return $length >= self::MIN_LENGTH
            && $length <= self::MAX_LENGTH
            && preg_match('/\p{L}/u', $value) === 1
            && preg_match('/\p{Nd}/u', $value) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            // Matches the legacy enforce()'s first branch exactly.
            $fail('كلمة المرور مطلوبة');

            return;
        }

        if (!self::passes($value)) {
            $fail(self::describe());
        }
    }
}
