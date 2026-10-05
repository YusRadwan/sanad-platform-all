<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The `enabled` flag inside a `socialLinks[]` / `supportNumbers[]` entry.
 *
 * Port of backend/SettingsService::requireBool(). Accepts, EXACTLY:
 *
 *   true  <-  true,  'true',  1, '1'
 *   false <-  false, 'false', 0, '0'
 *
 * and rejects everything else, including 'yes', 'on', '' and null. Laravel's
 * built-in `boolean` rule accepts a nearly identical set but coerces silently
 * and reports a generic English-shaped failure; this rule exists to carry
 * backend/'s exact Arabic message and, more importantly, to expose
 * normalise() so the Action has ONE definition of the coercion instead of
 * re-deriving it after validation.
 *
 * ┌── WHY THIS IS NOT App\Rules\BoolishString. ──────────────────────────────┐
 * │ It normalises to a REAL PHP boolean, which is stored as a JSON boolean   │
 * │ and read by the frontend as one (`link.enabled` drives a checkbox        │
 * │ directly, no string comparison anywhere). The four                       │
 * │ `settings.*_section_enabled` toggles go the other way — see that class.  │
 * │ The wire types genuinely differ; this is inherited, not invented.        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * IMPLICIT, for the same measured reason as App\Rules\BoolishString: without
 * the flag Laravel skips this rule on a blank string, so `enabled: ''` would
 * be stored — and `Boolish::normalise('')` answers `false`, silently turning a
 * malformed request into a "disabled" flag instead of the 422 backend/
 * (`requireBool('')`) returns.
 *
 * Implicit does NOT make it required. An ABSENT `enabled` is legitimate —
 * legacy reads it as `$entry['enabled'] ?? false` — and `sometimes` is what
 * keeps that case unvalidated so the Action can supply the default.
 */
final class Boolish implements ValidationRule
{
    public bool $implicit = true;

    /** backend/SettingsService::requireBool()'s literal message. */
    public const MESSAGE = 'القيمة يجب أن تكون true أو false';

    public static function accepts(mixed $value): bool
    {
        return \is_bool($value)
            || $value === 'true' || $value === 1 || $value === '1'
            || $value === 'false' || $value === 0 || $value === '0';
    }

    /** Call only after accepts(). */
    public static function normalise(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return $value === 'true' || $value === 1 || $value === '1';
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!self::accepts($value)) {
            $fail(self::MESSAGE);
        }
    }
}
