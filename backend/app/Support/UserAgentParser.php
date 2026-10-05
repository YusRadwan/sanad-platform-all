<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Classifies a raw `User-Agent` header into the three display strings the
 * admin's "سجل الدخول" table renders.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ BRANCH ORDER IS THE ENTIRE ALGORITHM. REORDERING IT SILENTLY CHANGES     ║
 * ║ ANSWERS WITHOUT FAILING ANY TYPE CHECK.                                  ║
 * ║                                                                          ║
 * ║ Every real browser's UA string lies about being some other browser, so   ║
 * ║ these are OVERLAPPING substring tests resolved by precedence, not        ║
 * ║ mutually exclusive cases:                                                ║
 * ║                                                                          ║
 * ║   Edge   ships "… Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0"          ║
 * ║   Opera  ships "… Chrome/119.0.0.0 Safari/537.36 OPR/105.0.0.0"          ║
 * ║   Chrome ships "… Safari/537.36" (the KHTML heritage)                    ║
 * ║                                                                          ║
 * ║ So Edg/ MUST precede OPR/, which MUST precede Chrome/, which MUST        ║
 * ║ precede Safari/. Sorting these branches alphabetically — or "tidying"    ║
 * ║ them into a lookup table keyed by token — reports every Edge session as  ║
 * ║ Chrome and every Chrome session as Safari, with no error anywhere.       ║
 * ║                                                                          ║
 * ║ Same for device type: an iPad's UA contains BOTH "iPad" and "Mobile",    ║
 * ║ and an Android tablet contains BOTH "Tablet" and "Android", so the       ║
 * ║ tablet branch must come first or every tablet is reported as a phone.    ║
 * ║                                                                          ║
 * ║ PINNED BY tests/Unit/UserAgentParserTest.php, which asserts the exact    ║
 * ║ classification of eleven REAL user-agent strings. That test exists for   ║
 * ║ exactly one purpose: to fail if anybody reorders these match arms.       ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * PORTED VERBATIM from backend/app/Services/AdminDashboardService.php
 * (loginActivity(), the three `match(true)` blocks). Nothing was added, removed
 * or reordered — including the fact that `os()` has no `curl` branch and
 * `browser()` has no `Windows`-style fallback beyond 'غير معروف'.
 *
 * WHY A SEPARATE CLASS AND NOT A PRIVATE METHOD ON THE ACTION. backend/ inlined
 * all three blocks inside an `array_map` closure, where the only way to test
 * them was through a database fixture, an HTTP request and a JSON assertion.
 * Here they are three pure static functions over a string, so the branch-order
 * regression test is a unit test with no database at all — which is the only
 * kind of test cheap enough to enumerate eleven agents.
 */
final class UserAgentParser
{
    /**
     * The single fallback literal, shared by all three classifiers.
     *
     * Arabic, and it reaches the screen verbatim: LoginActivity.tsx renders
     * these strings as-is with no translation layer.
     */
    public const UNKNOWN = 'غير معروف';

    public const DEVICE_TABLET = 'جهاز لوحي';

    public const DEVICE_MOBILE = 'هاتف محمول';

    public const DEVICE_DESKTOP = 'حاسوب';

    /** Order-sensitive. See the class docblock before touching anything here. */
    public static function browser(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'curl/') => 'curl',
            default => self::UNKNOWN,
        };
    }

    /**
     * Order-sensitive.
     *
     * NOTE the iOS branch tests BOTH 'iPhone' and 'iPad' — iOS Safari never
     * says "iOS" — and that it sits ABOVE 'Mac OS X', which every iOS UA also
     * contains ("… like Mac OS X …"). Swapping those two reports every iPhone
     * as a Mac.
     *
     * 'Android' precedes 'Linux' for the same reason: Android UAs say
     * "Linux; Android 13".
     */
    public static function os(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => self::UNKNOWN,
        };
    }

    /**
     * Order-sensitive.
     *
     * ┌── THE EMPTY-STRING BRANCH IS THIRD, NOT FIRST, AND THAT MATTERS. ────┐
     * │ backend/ places `$ua === ''` AFTER the two positive tests. It is     │
     * │ unreachable-by-accident there (an empty string contains neither      │
     * │ 'iPad' nor 'Mobile'), so moving it to the top would be behaviourally │
     * │ identical TODAY — but the position is preserved anyway, because the  │
     * │ point of this port is that the arms are byte-for-byte the legacy     │
     * │ ones and a reader can diff them without reasoning about reachability.│
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * Consequence worth stating out loud: a non-empty UA that matches nothing
     * (curl, a monitoring probe, a scripted client) is reported as **حاسوب**,
     * not as غير معروف. That is legacy behaviour and it is deliberate here —
     * `browser()` already reports 'curl' for those callers, so the device
     * column being "حاسوب" is the honest answer for a shell on a machine.
     */
    public static function deviceType(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'iPad') || str_contains($ua, 'Tablet') => self::DEVICE_TABLET,
            str_contains($ua, 'Mobile') || str_contains($ua, 'Android') => self::DEVICE_MOBILE,
            $ua === '' => self::UNKNOWN,
            default => self::DEVICE_DESKTOP,
        };
    }
}
