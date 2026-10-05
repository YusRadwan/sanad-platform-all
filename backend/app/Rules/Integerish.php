<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The `order` field inside a `socialLinks[]` / `supportNumbers[]` entry.
 *
 * Port of backend/SettingsService::requireInt(). Accepts a real PHP int, or a
 * string matching `/^-?\d+$/`. Nothing else — notably NOT a float, NOT '1.0',
 * NOT ' 1 ' (no trimming), and NOT '' .
 *
 * ┌── WHY NOT Laravel's `integer` RULE. ─────────────────────────────────────┐
 * │ `integer` is implemented with FILTER_VALIDATE_INT, which accepts leading │
 * │ and trailing whitespace, a leading '+', and hex/octal spellings on some  │
 * │ builds. This payload arrives as JSON from one known client that always   │
 * │ sends a real number, so the wider set buys nothing and the narrower one  │
 * │ matches backend/ exactly. The Arabic message is the other half: the      │
 * │ admin form renders `data.errors` verbatim.                               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NEGATIVE VALUES ARE ALLOWED. The regex admits a leading '-' and legacy does
 * not clamp, so `order: -1` is stored as -1. It is a display sort key with no
 * domain meaning beyond "smaller first"; rejecting negatives would be a new
 * rule.
 */
final class Integerish implements ValidationRule
{
    /**
     * IMPLICIT — same measured reason as App\Rules\BoolishString. Without it,
     * `order: ''` skips this rule and `Integerish::normalise('')` answers the
     * int 0, silently rewriting a malformed request into "first in the list"
     * instead of the 422 backend/'s requireInt('') raises.
     */
    public bool $implicit = true;

    /** backend/SettingsService::requireInt()'s literal message. */
    public const MESSAGE = 'القيمة يجب أن تكون رقماً صحيحاً';

    public static function accepts(mixed $value): bool
    {
        if (\is_int($value)) {
            return true;
        }

        return \is_string($value) && preg_match('/^-?\d+$/', $value) === 1;
    }

    /** Call only after accepts(). */
    public static function normalise(mixed $value): int
    {
        return (int) $value;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!self::accepts($value)) {
            $fail(self::MESSAGE);
        }
    }
}
