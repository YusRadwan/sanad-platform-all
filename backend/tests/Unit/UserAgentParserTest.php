<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\UserAgentParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS TEST EXISTS FOR ONE REASON: TO FAIL IF SOMEBODY REORDERS THE       ║
 * ║ `match(true)` ARMS IN App\Support\UserAgentParser.                       ║
 * ║                                                                          ║
 * ║ Those arms are OVERLAPPING substring tests, not disjoint cases. Every    ║
 * ║ Chromium browser's UA contains 'Chrome/' AND 'Safari/'; Edge's contains  ║
 * ║ both plus 'Edg/'; Opera's contains both plus 'OPR/'. Reordering them is  ║
 * ║ a refactor that compiles, passes every type check, breaks nothing        ║
 * ║ structurally — and silently relabels every Edge session as Chrome and    ║
 * ║ every Chrome session as Safari in the admin's security-audit table.      ║
 * ║                                                                          ║
 * ║ ELEVEN REAL user-agent strings, copied from actual browsers, not         ║
 * ║ synthesised. A synthetic 'Edge/1.0' would not contain 'Chrome/' and so   ║
 * ║ would pass under ANY branch order — which would make the test look       ║
 * ║ thorough while proving nothing at all.                                   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Extends PHPUnit's TestCase, not Tests\TestCase: these are three pure static
 * functions over a string. Booting Laravel and refreshing a MySQL database to
 * exercise them would make the suite slower by orders of magnitude and would
 * test the framework instead of the branch order.
 */
final class UserAgentParserTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function agents(): array
    {
        return [
            // ── The three that PROVE the precedence chain ─────────────────
            //
            // Note what each of these contains: Edge's string has 'Chrome/'
            // AND 'Safari/' in it, Opera's has both too. If 'Chrome/' were
            // tested first, the first two rows would both say 'Chrome'.
            'Edge on Windows 11' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.2210.91',
                'Edge', 'Windows', UserAgentParser::DEVICE_DESKTOP,
            ],
            'Opera on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36 OPR/105.0.0.0',
                'Opera', 'Windows', UserAgentParser::DEVICE_DESKTOP,
            ],
            'Chrome on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Chrome', 'Windows', UserAgentParser::DEVICE_DESKTOP,
            ],

            // ── Mobile / tablet: the device-type precedence ───────────────
            //
            // The Android phone contains BOTH 'Mobile' and 'Android'; the
            // iPad contains BOTH 'iPad' and 'Mobile'. The tablet arm must be
            // first or the iPad is reported as a phone.
            'Chrome on Android phone' => [
                'Mozilla/5.0 (Linux; Android 13; SM-S901B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.6045.163 Mobile Safari/537.36',
                'Chrome', 'Android', UserAgentParser::DEVICE_MOBILE,
            ],
            'Safari on iPhone' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
                'Safari', 'iOS', UserAgentParser::DEVICE_MOBILE,
            ],
            // The iPad UA says "like Mac OS X" — the iOS arm MUST precede the
            // macOS arm or every iPhone and iPad is reported as a Mac.
            'Safari on iPad' => [
                'Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
                'Safari', 'iOS', UserAgentParser::DEVICE_TABLET,
            ],
            // Android tablet: contains 'Tablet' AND 'Android'. Tablet wins.
            'Firefox on Android tablet' => [
                'Mozilla/5.0 (Android 13; Tablet; rv:121.0) Gecko/121.0 Firefox/121.0',
                'Firefox', 'Android', UserAgentParser::DEVICE_TABLET,
            ],

            // ── Desktop non-Chromium ─────────────────────────────────────
            'Firefox on Linux' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
                'Firefox', 'Linux', UserAgentParser::DEVICE_DESKTOP,
            ],
            // Real desktop Safari: contains 'Safari/' and NOT 'Chrome/'.
            'Safari on macOS' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
                'Safari', 'macOS', UserAgentParser::DEVICE_DESKTOP,
            ],

            // ── The two edge cases, and their answers are NOT symmetric ───
            //
            // curl: browser IS classified ('curl'), os is NOT (no branch
            // matches 'curl/7.88.1'), and deviceType falls to حاسوب because
            // the empty-string arm is third and the string is not empty.
            'curl' => [
                'curl/8.4.0',
                'curl', UserAgentParser::UNKNOWN, UserAgentParser::DEVICE_DESKTOP,
            ],
            // The empty string is the ONLY input that answers غير معروف on all
            // three — this is the row that pins the third `match` arm.
            'empty string' => [
                '',
                UserAgentParser::UNKNOWN, UserAgentParser::UNKNOWN, UserAgentParser::UNKNOWN,
            ],
        ];
    }

    #[DataProvider('agents')]
    public function test_each_real_user_agent_is_classified_exactly(
        string $ua,
        string $browser,
        string $os,
        string $deviceType,
    ): void {
        self::assertSame($browser, UserAgentParser::browser($ua), "browser() for: {$ua}");
        self::assertSame($os, UserAgentParser::os($ua), "os() for: {$ua}");
        self::assertSame($deviceType, UserAgentParser::deviceType($ua), "deviceType() for: {$ua}");
    }

    /**
     * THE NEGATIVE CONTROL for this whole file.
     *
     * The data provider above would still pass if `browser()` were rewritten
     * to return a constant per input by some other mechanism. What actually
     * matters is that the ORDER is doing the work — so this test states the
     * precedence claim directly: three strings that each satisfy MULTIPLE arms
     * simultaneously must resolve to the EARLIER arm, and the assertion names
     * the arm that would win if the order were wrong.
     */
    public function test_an_agent_matching_several_arms_resolves_to_the_earliest(): void
    {
        $edge = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0';

        // Sanity: the fixture really does satisfy all three arms.
        self::assertStringContainsString('Edg/', $edge);
        self::assertStringContainsString('Chrome/', $edge);
        self::assertStringContainsString('Safari/', $edge);

        self::assertSame('Edge', UserAgentParser::browser($edge), 'Chrome/ or Safari/ won — the arms were reordered.');

        $ipad = 'Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';

        self::assertStringContainsString('iPad', $ipad);
        self::assertStringContainsString('Mobile', $ipad);
        self::assertStringContainsString('Mac OS X', $ipad);

        self::assertSame(UserAgentParser::DEVICE_TABLET, UserAgentParser::deviceType($ipad), 'Mobile won — tablet arm moved.');
        self::assertSame('iOS', UserAgentParser::os($ipad), 'macOS won — the iOS arm moved below Mac OS X.');

        $android = 'Mozilla/5.0 (Linux; Android 13; SM-S901B) AppleWebKit/537.36 Chrome/119.0.0.0 Mobile Safari/537.36';

        self::assertStringContainsString('Android', $android);
        self::assertStringContainsString('Linux', $android);

        self::assertSame('Android', UserAgentParser::os($android), 'Linux won — the Android arm moved below it.');
    }

    /**
     * The three classifiers never return an empty string, which is what makes
     * `trim("{deviceType} · {os}")` in LoginActivityResource always produce a
     * two-sided label. Stated as a test so a future "return '' when unknown"
     * change is caught here rather than as a stray middle dot in the UI.
     */
    public function test_no_classifier_ever_returns_an_empty_string(): void
    {
        foreach (self::agents() as $label => [$ua]) {
            self::assertNotSame('', UserAgentParser::browser($ua), $label);
            self::assertNotSame('', UserAgentParser::os($ua), $label);
            self::assertNotSame('', UserAgentParser::deviceType($ua), $label);
        }
    }
}
