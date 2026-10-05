<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The four `settings.*_section_enabled` toggles.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS NORMALISES TO THE **STRING** 'true'/'false', NOT TO A PHP BOOLEAN.  ║
 * ║ CASTING TO A REAL BOOLEAN HERE BREAKS THE ROUND-TRIP.                   ║
 * ║                                                                          ║
 * ║ frontend/src/components/Admin/PlatformSettings.tsx builds its payload    ║
 * ║ as `social_section_enabled: socialSectionEnabled ? 'true' : 'false'`     ║
 * ║ and reads it back with `=== 'true'`. If this endpoint stored a JSON      ║
 * ║ boolean, the value would come back as `true` (unquoted), `=== 'true'`    ║
 * ║ would be FALSE, and every section toggle would appear OFF after the      ║
 * ║ page reloaded — while the save itself answered 200. A silent, one-way    ║
 * ║ data-shape break that no server-side test of the write path would see.   ║
 * ║                                                                          ║
 * ║ backend/SettingsService::cleanSettingsObject() says the same thing in a  ║
 * ║ comment and does the same coercion: a real bool IS accepted (an API      ║
 * ║ client might send one) and is normalised DOWN to the string form.        ║
 * ║                                                                          ║
 * ║ CONTRAST App\Rules\Boolish, which normalises UP to a real bool. The two  ║
 * ║ exist side by side on purpose: `enabled` inside a socialLinks entry is   ║
 * ║ consumed by the frontend as a JSON boolean, and these four section       ║
 * ║ toggles are consumed as strings. Same concept, two wire types, and       ║
 * ║ unifying them would break one caller or the other.                       ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * NOTE the accepted set is NARROWER than Boolish's: only a real bool or the
 * literal strings 'true'/'false'. Legacy does NOT accept 1/'1'/0/'0' here,
 * and widening it would be a new contract nobody asked for.
 */
final class BoolishString implements ValidationRule
{
    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ IMPLICIT, AND FINDING OUT WHY COST A FAILING TEST — THE SECOND TIME  ║
     * ║ IN THIS PROJECT (see App\Rules\SafeUrlValue for the first).          ║
     * ║                                                                      ║
     * ║ Laravel SKIPS every non-implicit rule when the value is a string     ║
     * ║ that trims to '' (Validator::presentOrRuleIsImplicit). So without    ║
     * ║ this flag, `social_section_enabled: ''` sailed past this rule        ║
     * ║ untouched and was STORED as the empty string — a value that is       ║
     * ║ neither 'true' nor 'false', which the UI's `=== 'true'` reads as     ║
     * ║ OFF and which no later write would ever correct.                     ║
     * ║                                                                      ║
     * ║ backend/ answers 422 there (`in_array('', ['true','false'], true)`   ║
     * ║ is false), so the blank string is a malformed request, not a value.  ║
     * ║                                                                      ║
     * ║ THE HOLE ONLY BECAME REACHABLE once TrimStrings was skipped for this ║
     * ║ route (open decision 10.8) — before that, '' was equally unreachable ║
     * ║ for a different reason. Two global middlewares masking one gap is    ║
     * ║ exactly why these rules must state their own contract.               ║
     * ║                                                                      ║
     * ║ Implicit is NOT `required`: `sometimes` still means an ABSENT key is ║
     * ║ not validated at all. Implicit only means "run me for the blank      ║
     * ║ string too", which is the case in dispute.                           ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public bool $implicit = true;

    /** backend/SettingsService's literal message for this failure. */
    public const MESSAGE = 'القيمة يجب أن تكون true أو false';

    public static function accepts(mixed $value): bool
    {
        return \is_bool($value) || $value === 'true' || $value === 'false';
    }

    /**
     * Coerces an ACCEPTED value to its canonical stored form.
     *
     * Call only after accepts() has passed — an unaccepted value would fall
     * through the is_bool() branch and be returned unchanged as a lie.
     */
    public static function normalise(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!self::accepts($value)) {
            $fail(self::MESSAGE);
        }
    }
}
