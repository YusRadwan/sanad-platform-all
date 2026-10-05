<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Models\BroadcastNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * The `broadcast` rate-limit bucket — freeze-table rows 103-106.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ EVERY NUMBER HERE IS READ FROM config/ratelimit.php RATHER THAN TYPED.   ║
 * ║ A test that hard-codes 10 keeps passing when someone loosens the bucket   ║
 * ║ to 1000 — it would prove the loop runs, not that the limit is the limit.  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── WHY loginAs() AND NOT actingAs(). ─────────────────────────────────────┐
 * │ ThrottleWithLockout::callerKey() reads the AUTHENTICATED USER ID OUT OF  │
 * │ THE SESSION (no database access — the limiter runs ahead of `auth` on    │
 * │ the priority list, so it cannot rely on a resolved user). `actingAs()`   │
 * │ sets the user on the guard OBJECT and writes no session, so the key      │
 * │ would silently fall back to `ip:127.0.0.1` and BOTH admins in the        │
 * │ per-admin test below would share one counter — the test would fail for   │
 * │ a reason that has nothing to do with the application.                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class BroadcastRateLimitTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    /** @return array{max: int, window: int, lockout: int, maxLockout: int} */
    private function bucket(): array
    {
        return [
            'max' => (int) config('ratelimit.buckets.broadcast.max_attempts'),
            'window' => (int) config('ratelimit.buckets.broadcast.window_seconds'),
            'lockout' => (int) config('ratelimit.buckets.broadcast.lockout_seconds'),
            'maxLockout' => (int) config('ratelimit.buckets.broadcast.max_lockout_seconds'),
        ];
    }

    /** A real, session-backed login — with the limiter off so /auth/login's own bucket is not spent. */
    private function signIn(User $user): void
    {
        $this->disableRateLimiting();
        $this->loginAs($user);
        config(['ratelimit.enabled' => true]);
        $this->startNextRequest();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'title' => 'عنوان التنبيه',
            'body' => 'نص التنبيه',
            'audience' => 'all_teachers',
            'start_at' => '2026-01-01T09:00',
        ];
    }

    // ── THE NUMBERS ───────────────────────────────────────────────────────

    /**
     * The bucket is the one backend/config/ratelimit.php declares, value for
     * value — 10 per 5 minutes, 5-minute block escalating to 30.
     *
     * Asserted separately from the behaviour so a config drift is reported as
     * "the bucket changed" rather than as a confusing off-by-N in a loop.
     */
    public function test_the_bucket_matches_the_ported_configuration(): void
    {
        $this->assertSame(
            ['max' => 10, 'window' => 300, 'lockout' => 300, 'maxLockout' => 1800],
            $this->bucket()
        );
    }

    // ── THE LIMIT ITSELF ──────────────────────────────────────────────────

    /**
     * The 11th write in the window is a 429 — and, critically, the 10 before it
     * are NOT. A test that only asserted the 429 would also pass against a
     * limiter set to 1.
     */
    public function test_the_eleventh_write_in_the_window_is_429(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInstructor();

        $this->signIn($admin);

        $max = $this->bucket()['max'];

        for ($i = 1; $i <= $max; $i++) {
            $this->postJson('/api/v1/admin/broadcasts', $this->payload())
                // Request $i of $max — all of these must be INSIDE the budget.
                ->assertStatus(201);
        }

        $blocked = $this->postJson('/api/v1/admin/broadcasts', $this->payload());

        $blocked->assertStatus(429);
        $blocked->assertHeader('Retry-After');
        $this->assertNotNull($blocked->json('data.retry_after'));

        // The rejected request wrote nothing — the limiter runs before the
        // controller, so exactly `max` broadcasts exist.
        $this->assertSame($max, BroadcastNotification::query()->count());
    }

    /**
     * ALL FOUR write verbs share ONE bucket, rather than each having its own.
     * Ten POSTs must exhaust the budget for a subsequent PUT, PATCH and DELETE
     * too — otherwise the effective limit is 40 per window, not 10.
     */
    public function test_the_four_write_verbs_share_a_single_budget(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInstructor();

        $this->signIn($admin);

        $max = $this->bucket()['max'];

        for ($i = 1; $i <= $max; $i++) {
            $this->postJson('/api/v1/admin/broadcasts', $this->payload())->assertStatus(201);
        }

        $id = BroadcastNotification::query()->firstOrFail()->getKey();

        $this->putJson("/api/v1/admin/broadcasts/{$id}", $this->payload())->assertStatus(429);
        $this->patchJson("/api/v1/admin/broadcasts/{$id}/toggle")->assertStatus(429);
        $this->deleteJson("/api/v1/admin/broadcasts/{$id}")->assertStatus(429);

        $this->assertNotNull(
            BroadcastNotification::query()->whereKey($id)->first(),
            'A throttled DELETE must not have deleted anything.'
        );
    }

    // ── THE GET IS NOT LIMITED ────────────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE READ ROUTE CARRIES NO LIMITER, AND THAT IS A DELIBERATE SPLIT.   ║
     * ║                                                                      ║
     * ║ The admin screen re-reads this list after every mutation. Sharing the ║
     * ║ write bucket would lock an admin out of their own audit view for up   ║
     * ║ to 30 minutes over a READ. backend/routes/api.php makes exactly the   ║
     * ║ same split — two route groups, one with the limiter, one without.     ║
     * ║                                                                      ║
     * ║ Asserted AFTER the write budget is already exhausted, which is the    ║
     * ║ only arrangement that distinguishes "the GET has no limiter" from     ║
     * ║ "the GET has a limiter that simply was not reached".                  ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_get_is_never_throttled_even_after_the_write_budget_is_spent(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInstructor();

        $this->signIn($admin);

        for ($i = 1; $i <= $this->bucket()['max']; $i++) {
            $this->postJson('/api/v1/admin/broadcasts', $this->payload())->assertStatus(201);
        }

        $this->postJson('/api/v1/admin/broadcasts', $this->payload())->assertStatus(429);

        // …and now 25 reads, comfortably past any plausible bucket.
        for ($i = 1; $i <= 25; $i++) {
            $this->getJson('/api/v1/admin/broadcasts')->assertOk();
        }
    }

    // ── KEYED PER ADMIN, NOT PER IP ───────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ ONE ADMIN EXHAUSTING THE BUDGET MUST NOT LOCK OUT THE OTHERS.        ║
     * ║                                                                      ║
     * ║ Both requests come from the SAME IP (127.0.0.1 in the test harness),  ║
     * ║ which is what makes this test meaningful: if the bucket were keyed on ║
     * ║ the address, the second admin would inherit the first's 429. Real     ║
     * ║ deployments put the whole admin staff behind one office NAT, so an    ║
     * ║ IP-keyed bucket would mean one careless session disables broadcasting ║
     * ║ for everybody.                                                        ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_budget_is_per_admin_and_not_per_ip(): void
    {
        $first = $this->makeAdmin();
        $second = $this->makeAdmin();
        $this->makeInstructor();

        $this->signIn($first);

        for ($i = 1; $i <= $this->bucket()['max']; $i++) {
            $this->postJson('/api/v1/admin/broadcasts', $this->payload())->assertStatus(201);
        }

        $this->postJson('/api/v1/admin/broadcasts', $this->payload())
            // Precondition: the FIRST admin must genuinely be locked out.
            ->assertStatus(429);

        // Same process, same IP, different session identity.
        $this->signIn($second);

        $this->postJson('/api/v1/admin/broadcasts', $this->payload())
            // …and the SECOND admin, on the very same IP, has their own budget.
            ->assertStatus(201);
    }

    // ── ESCALATION ────────────────────────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE ESCALATING BLOCK — Stage 1's mechanism, REUSED not reimplemented. ║
     * ║ First offence 300 s, second 600 s, third 900 s (lockout × strikes),   ║
     * ║ capped at max_lockout_seconds.                                        ║
     * ║                                                                      ║
     * ║ ══ ESCALATION NEEDS THE LOCKOUT TO BE **SERVED OUT**, NOT HAMMERED. ══║
     * ║ Measured, not assumed: knocking repeatedly WHILE blocked returns the  ║
     * ║ same 300 every time (this test failed on exactly that first). A       ║
     * ║ strike is recorded when a NEW window is exhausted, so the clock has   ║
     * ║ to advance past the block before the next offence counts.             ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * The requests deliberately carry an EMPTY body and answer 422: the
     * limiter runs before the controller, so a rejected request still spends
     * budget — which keeps this test about the limiter and stops it writing
     * thirty broadcasts as a side effect.
     */
    public function test_a_repeat_offence_costs_strictly_more(): void
    {
        $admin = $this->makeAdmin();

        $this->signIn($admin);

        $first = $this->burnThroughOneLockout($admin);
        $this->assertSame(300, $first, 'first offence: lockout_seconds × 1');

        $second = $this->burnThroughOneLockout($admin);
        $this->assertSame(600, $second, 'second offence: lockout_seconds × 2');

        $third = $this->burnThroughOneLockout($admin);
        $this->assertSame(900, $third, 'third offence: lockout_seconds × 3');

        $this->assertGreaterThan($first, $third);
        $this->assertLessThanOrEqual($this->bucket()['maxLockout'], $third);
    }

    /**
     * Exhausts one window, returns the resulting 429's retry_after, and winds
     * the limiter's clock past the block so the NEXT call is a fresh offence.
     */
    private function burnThroughOneLockout(User $admin): int
    {
        for ($i = 1; $i <= $this->bucket()['max']; $i++) {
            $this->postJson('/api/v1/admin/broadcasts', [])->assertStatus(422);
        }

        $retryAfter = (int) $this->postJson('/api/v1/admin/broadcasts', [])
            ->assertStatus(429)
            ->json('data.retry_after');

        $this->rewindLimiterClock($admin, $retryAfter + 1);

        return $retryAfter;
    }

    /**
     * Moves every timestamp in this admin's `broadcast` counter BACK, which is
     * indistinguishable from that much real time having passed.
     *
     * NOTE the limiter reads time() rather than Carbon::now(), so travelling
     * with Carbon alone would not move it — and `sleep()` in a test is not
     * acceptable. `last_strike` is deliberately NOT rewound past the point that
     * would reset the strike counter: the strike total is precisely what must
     * escalate. Same helper, same reasoning, as Stage 1's RateLimitTest.
     */
    private function rewindLimiterClock(User $admin, int $seconds): void
    {
        $key = 'ratelimit:broadcast:' . hash('sha256', 'broadcast|user:' . $admin->getKey());

        $state = cache()->get($key);

        if (!\is_array($state)) {
            $this->fail("The broadcast limiter wrote no counter under {$key} — the caller key is not the user id.");
        }

        foreach (['window_start', 'blocked_until', 'last_strike'] as $field) {
            if (($state[$field] ?? 0) > 0) {
                $state[$field] -= $seconds;
            }
        }

        cache()->put($key, $state, 7200);
    }

    /**
     * The counter really is filed under the ADMIN'S USER ID — asserted on the
     * cache key itself, not only through behaviour.
     *
     * The behavioural test above proves two admins do not share a bucket; this
     * proves WHY, and would fail if the key were derived from the IP even in
     * the case where the two happen to differ.
     */
    public function test_the_counter_is_keyed_on_the_user_id_not_the_address(): void
    {
        $admin = $this->makeAdmin();

        $this->signIn($admin);

        $this->postJson('/api/v1/admin/broadcasts', [])->assertStatus(422);

        $userKey = 'ratelimit:broadcast:' . hash('sha256', 'broadcast|user:' . $admin->getKey());
        $ipKey = 'ratelimit:broadcast:' . hash('sha256', 'broadcast|ip:127.0.0.1');

        $this->assertIsArray(cache()->get($userKey), 'The bucket must be keyed on the acting admin.');
        $this->assertNull(cache()->get($ipKey), 'No IP-keyed counter may exist for an authenticated caller.');

        // The raw uuid never appears in the key — it is hashed, same as Stage 1.
        $this->assertStringNotContainsString((string) $admin->getKey(), $userKey);
    }

    /**
     * The limiter sits AHEAD of `auth` on the priority list, so abusive traffic
     * is rejected before the per-request user lookup — the same arrangement as
     * /auth/update-credentials and the exam/assignment submit routes.
     *
     * Proven by an UNAUTHENTICATED caller: they get 401s until the budget runs
     * out and a 429 after, which can only happen if the limiter ran first.
     */
    public function test_the_limiter_runs_before_auth(): void
    {
        config(['ratelimit.enabled' => true]);

        for ($i = 1; $i <= $this->bucket()['max']; $i++) {
            $this->postJson('/api/v1/admin/broadcasts', [])->assertStatus(401);
        }

        $this->postJson('/api/v1/admin/broadcasts', [])->assertStatus(429);
    }
}
