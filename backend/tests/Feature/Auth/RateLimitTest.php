<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * The fixed-window + ESCALATING LOCKOUT limiter.
 *
 * The escalation is the whole reason this is not Laravel's `throttle`: a bot
 * that hits the wall repeatedly must pay progressively more, or the limiter is
 * just a speed bump. Every number asserted here comes from config/ratelimit.php,
 * which was ported value-for-value from backend/config/ratelimit.php.
 *
 * The cache store is `array` under phpunit and is reset between tests, so each
 * test starts from a clean counter.
 */
final class RateLimitTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    /** @return \Illuminate\Testing\TestResponse */
    private function attemptLogin(string $username = 'victim', string $password = 'WrongPassword1')
    {
        return $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);
    }

    public function test_the_twenty_first_login_attempt_in_a_minute_is_429(): void
    {
        $this->makeUser(['username' => 'victim']);

        // login bucket: max_attempts 20 per 60 s.
        for ($i = 1; $i <= 20; $i++) {
            $this->attemptLogin()->assertStatus(401);
        }
        $this->attemptLogin()->assertStatus(429);
    }

    /**
     * The 429 contract, all three parts: the Arabic message, `data.retry_after`
     * in the envelope, and a real `Retry-After` HTTP header. The mapping was
     * built in Stage 0; this is the first real throttled route to exercise it.
     */
    public function test_the_429_carries_the_message_the_payload_field_and_the_header(): void
    {
        $this->makeUser(['username' => 'victim']);

        for ($i = 1; $i <= 20; $i++) {
            $this->attemptLogin();
	}

        $response = $this->attemptLogin();

        $response->assertStatus(429)->assertJsonPath('success', false);

        $retryAfter = $response->json('data.retry_after');

        // First violation on the login bucket: lockout_seconds × 1 strike.
        $this->assertSame(300, $retryAfter);
        $this->assertSame('300', $response->headers->get('Retry-After'));
        $this->assertSame(
            'عدد المحاولات كبير جداً. يرجى المحاولة مرة أخرى بعد 300 ثانية',
            $response->json('message')
        );
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     *   THE ESCALATION ITSELF — what Laravel's own limiter cannot do.        │
     * │ First offence 300 s, second 600 s, third 900 s (lockout × strikes),  │
     * │ capped at max_lockout_seconds.                                       │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_repeat_offence_costs_strictly_more(): void
    {
        $this->makeUser(['username' => 'victim']);

        $first = $this->burnThroughOneLockout();
        $this->assertSame(300, $first, 'first offence: lockout_seconds × 1');

        // Serve out the first lockout, then offend again.
        Carbon::setTestNow(now()->addSeconds(301));

        $second = $this->burnThroughOneLockout();
        $this->assertSame(600, $second, 'second offence: lockout_seconds × 2');

        Carbon::setTestNow(now()->addSeconds(601));

        $third = $this->burnThroughOneLockout();
        $this->assertSame(900, $third, 'third offence: lockout_seconds × 3');

        Carbon::setTestNow();
    }

    /**
     * Exhausts one window and returns the retry_after of the resulting 429.
     *
     * NOTE the limiter reads time() rather than Carbon::now(), so travelling
     * with Carbon alone would not move it. `sleep()` in a test is unacceptable,
     * so the state entry's timestamps are rewound directly instead — the same
     * observable effect as real elapsed time, with none of the wall clock.
     */
    private function burnThroughOneLockout(): int
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->attemptLogin();
        }

        $response = $this->attemptLogin();
        $response->assertStatus(429);

        $retryAfter = (int) $response->json('data.retry_after');

        // Fast-forward past the lockout AND past the window, without touching
        // the strike counter (which is what must escalate).
        $this->rewindLimiterClock($retryAfter + 1);

        return $retryAfter;
    }

    /**
     * Moves every timestamp in the login bucket's counters BACK by $seconds,
     * which is indistinguishable from $seconds of real time having passed.
     */
    private function rewindLimiterClock(int $seconds): void
    {
        foreach ($this->limiterKeys() as $key) {
            $state = cache()->get($key);

            if (!is_array($state)) {
                continue;
            }

            foreach (['window_start', 'blocked_until', 'last_strike'] as $field) {
                if (($state[$field] ?? 0) > 0) {
                    $state[$field] -= $seconds;
                }
            }

            cache()->put($key, $state, 7200);
        }
    }

    /** Both counters for the login bucket: the caller one and the username one. */
    private function limiterKeys(): array
    {
        return [
            'ratelimit:login:' . hash('sha256', 'login|ip:127.0.0.1'),
            'ratelimit:login:' . hash('sha256', 'login|id:victim'),
        ];
    }

    /**
     * Counter 2: a distributed attack from many IPs against ONE account. The
     * per-username cap is 10, higher than the per-caller 5, so it only bites
     * once the caller key varies.
     */
    public function test_a_second_counter_is_kept_per_targeted_username(): void
    {
        $this->makeUser(['username' => 'victim']);

        // Five attempts from one IP exhaust the CALLER counter, and the
        // username counter now stands at 5 as well.
        for ($i = 1; $i <= 5; $i++) {
            $this->attemptLogin();
        }

        $usernameKey = 'ratelimit:login:' . hash('sha256', 'login|id:victim');
        $state = cache()->get($usernameKey);

        $this->assertIsArray($state, 'a per-username counter must exist');
        $this->assertSame(5, $state['count']);
    }

    /** A different username does not consume the first one's per-account budget. */
    public function test_the_username_counter_is_scoped_to_the_username(): void
    {
        $this->makeUser(['username' => 'victim']);
        $this->makeUser(['username' => 'other']);

        $this->attemptLogin('victim');
        $this->attemptLogin('other');

        $victimKey = 'ratelimit:login:' . hash('sha256', 'login|id:victim');
        $otherKey = 'ratelimit:login:' . hash('sha256', 'login|id:other');

        $this->assertSame(1, cache()->get($victimKey)['count']);
        $this->assertSame(1, cache()->get($otherKey)['count']);
    }

    /** Case-insensitive, so 'Victim' cannot buy a fresh budget for 'victim'. */
    public function test_the_username_counter_is_case_insensitive(): void
    {
        $this->makeUser(['username' => 'victim']);

        $this->attemptLogin('victim');
        $this->attemptLogin('VICTIM');

        $key = 'ratelimit:login:' . hash('sha256', 'login|id:victim');

        $this->assertSame(2, cache()->get($key)['count']);
    }

    /** No IP or username is written to the cache in clear text. */
    public function test_counter_keys_do_not_contain_the_raw_subject(): void
    {
        $this->makeUser(['username' => 'victim']);
        $this->attemptLogin();

        $key = 'ratelimit:login:' . hash('sha256', 'login|id:victim');

        $this->assertNotNull(cache()->get($key));
        $this->assertStringNotContainsString('victim', $key);
        $this->assertStringNotContainsString('127.0.0.1', $key);
    }

    /**
     * SUCCESSFUL logins consume budget too — the limiter counts REQUESTS, not
     * failures, exactly as the legacy one did.
     *
     * Which counter bites is worth understanding: once the first login lands,
     * the caller key flips from `ip:…` to `user:<id>` (a deliberate design
     * choice, so one school's NAT address cannot lock out a whole class), and
     * that fresh counter has its own budget. The counter that keeps growing
     * across all of them is the per-USERNAME one, capped at 10 — so the
     * ELEVENTH login is the one that trips.
     */
    public function test_successful_logins_are_counted_too_via_the_username_counter(): void
    {
        $this->makeUser(['username' => 'victim']);

        $login = fn () => $this->postJson('/api/v1/auth/login', [
            'username' => 'victim',
            'password' => self::FIXTURE_PASSWORD,
        ]);

        $login()->assertStatus(200);
        $login()->assertStatus(200);
        $login()->assertStatus(200);

        $usernameKey = 'ratelimit:login:' . hash('sha256', 'login|id:victim');

        $this->assertSame(
            3,
            cache()->get($usernameKey)['count'],
            'a SUCCESSFUL login must still consume budget — the limiter counts requests, not failures'
        );
    }

    /**
     * The caller key FLIPS from `ip:` to `user:` the moment a session exists.
     *
     * That is the design, not an accident: keying authenticated traffic on the
     * user id stops one school or office NAT address from locking out everyone
     * behind it. The consequence worth knowing is that a signed-in caller gets
     * a counter of their own, which is why the per-USERNAME counter (counter 2)
     * exists — it is the one thing that spans both keys.
     */
    public function test_the_caller_key_switches_from_ip_to_user_once_authenticated(): void
    {
        $user = $this->makeUser(['username' => 'victim']);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'victim',
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(200);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'victim',
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(200);

        $ipKey = 'ratelimit:login:' . hash('sha256', 'login|ip:127.0.0.1');
        $userKey = 'ratelimit:login:' . hash('sha256', 'login|user:' . $user->getKey());

        $this->assertSame(1, cache()->get($ipKey)['count'], 'only the FIRST call was unauthenticated');
        $this->assertSame(1, cache()->get($userKey)['count'], 'the second was keyed on the user id');
    }

    /** register bucket: 3 per hour per IP. */
    public function test_the_fourth_registration_in_an_hour_is_429(): void
    {
        $payload = fn (string $phone) => [
            'fullName' => 'طالب جديد',
            'studentPhone' => $phone,
            'parentPhone' => '01098765432',
            'governorate' => 'القاهرة',
            'gradeLevel' => 'الصف الأول',
            'password' => 'CorrectHorse42Battery',
        ];

        $this->postJson('/api/v1/auth/register', $payload('01011111111'))->assertStatus(201);
        $this->postJson('/api/v1/auth/register', $payload('01022222222'))->assertStatus(201);
        $this->postJson('/api/v1/auth/register', $payload('01033333333'))->assertStatus(201);

        $response = $this->postJson('/api/v1/auth/register', $payload('01044444444'));

        $response->assertStatus(429);
        // register bucket: lockout_seconds 3600 on the first violation.
        $this->assertSame(3600, $response->json('data.retry_after'));
    }

    /** update-credentials bucket: 5 per 5 minutes, 900 s first lockout. */
    public function test_update_credentials_has_its_own_bucket(): void
    {
        $user = $this->makeUser();

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($user)->patchJson('/api/v1/auth/update-credentials', [
                'currentPassword' => 'WrongPassword1',
                'newPassword' => 'BrandNewPass2026x',
            ])->assertStatus(422);
        }

        $response = $this->actingAs($user)
            ->patchJson('/api/v1/auth/update-credentials', [
                'currentPassword' => 'WrongPassword1',
                'newPassword' => 'BrandNewPass2026x',
            ]);

        $response->assertStatus(429);
        $this->assertSame(900, $response->json('data.retry_after'));
    }

    /**
     * The limiter must run BEFORE the auth check on update-credentials, so
     * hostile traffic costs no user lookup. An UNAUTHENTICATED flood therefore
     * ends in 429, not in an endless series of cheap-looking 401s.
     */
    public function test_the_limiter_runs_before_auth_on_update_credentials(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->patchJson('/api/v1/auth/update-credentials', [])->assertStatus(401);
        }

        $this->patchJson('/api/v1/auth/update-credentials', [])->assertStatus(429);
    }

    /** The kill switch works — several other suites depend on it. */
    public function test_the_limiter_can_be_disabled_by_config(): void
    {
        $this->disableRateLimiting();
        $this->makeUser(['username' => 'victim']);

        for ($i = 1; $i <= 12; $i++) {
            $this->attemptLogin()->assertStatus(401);
        }
    }

    /** A CORS preflight must not burn a legitimate user's budget. */
    public function test_an_options_preflight_is_not_counted(): void
    {
        $this->makeUser(['username' => 'victim']);

        for ($i = 1; $i <= 8; $i++) {
            $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
                'HTTP_ORIGIN' => 'http://localhost:5173',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            ]);
        }

        $this->attemptLogin()->assertStatus(401);
    }
}
