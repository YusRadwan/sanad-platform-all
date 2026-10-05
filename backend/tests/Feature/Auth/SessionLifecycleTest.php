<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\LoginSessionStatus;
use App\Enums\UserRole;
use App\Models\LoginSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * /auth/me, /auth/logout, /auth/refresh, and the per-request re-validation that
 * makes a mid-session change take effect IMMEDIATELY rather than at cookie
 * expiry.
 *
 * The mid-session tests all mutate the row with a RAW DB::table() UPDATE, not
 * through the model. That is deliberate: going through Eloquent could leave a
 * cached instance around and prove nothing about what the next request reads
 * from the database. A raw UPDATE is what an admin action (or a DBA) actually
 * does to the row.
 */
final class SessionLifecycleTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();
    }

    // ── /auth/me ──────────────────────────────────────────────────────────

    public function test_me_returns_the_public_user_with_the_ok_message(): void
    {
        $user = $this->makeUser(['username' => 'me01']);

        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('message', 'OK')
            ->assertJsonPath('data.user.id', $user->getKey())
            ->assertJsonPath('data.user.fullName', $user->full_name);
    }

    public function test_me_is_401_without_a_session(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_me_never_exposes_the_password_hash_or_deleted_at(): void
    {
        $user = $this->makeUser();

        $payload = $this->actingAs($user)->getJson('/api/v1/auth/me')->json('data.user');

        $this->assertArrayNotHasKey('password_hash', $payload);
        $this->assertArrayNotHasKey('deleted_at', $payload);
    }

    /**
     * The legacy payload shipped the raw MySQL DATETIME string. Preserved
     * explicitly, because the model's Carbon cast would otherwise emit
     * ISO-8601 with microseconds — a different string for the same instant.
     */
    public function test_created_at_keeps_the_legacy_datetime_format(): void
    {
        $user = $this->makeUser();

        $createdAt = $this->actingAs($user)->getJson('/api/v1/auth/me')->json('data.user.created_at');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $createdAt);
    }

    // ── Mid-session revalidation ──────────────────────────────────────────

    /** Test 3 from the brief: deactivate mid-session -> next request is 401. */
    public function test_deactivating_an_account_mid_session_401s_the_very_next_request(): void
    {
        $user = $this->makeUser();
        $this->loginAs($user);

        $this->getJson('/api/v1/auth/me')->assertStatus(200);

        DB::table('users')->where('id', $user->getKey())->update(['is_active' => 0]);

        $this->startNextRequest();

        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->assertGuest();
    }

    /** Test 4 from the brief: soft-delete mid-session -> next request is 401. */
    public function test_soft_deleting_an_account_mid_session_401s_the_very_next_request(): void
    {
        $user = $this->makeUser();
        $this->loginAs($user);

        $this->getJson('/api/v1/auth/me')->assertStatus(200);

        DB::table('users')->where('id', $user->getKey())->update(['deleted_at' => now()]);

        $this->startNextRequest();

        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->assertGuest();
    }

    /**
     * Test 5 from the brief: a role change is reflected on the next request.
     *
     * This is free with the session guard — the user is re-hydrated from the
     * database every request and nothing about them is cached in the session
     * payload. The legacy code had to mirror the role into $_SESSION and
     * re-sync it by hand on every request precisely because its "session" was
     * the source of truth. Asserted so a future "optimisation" that caches the
     * user in the session cannot silently reintroduce a stale role.
     */
    public function test_a_role_change_is_visible_on_the_next_request(): void
    {
        $user = $this->makeUser(['role' => UserRole::Student]);
        $this->loginAs($user);

        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.user.role', 'student');

        DB::table('users')->where('id', $user->getKey())->update(['role' => 'instructor']);

        $this->startNextRequest();

        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.user.role', 'instructor');
    }

    // ── /auth/logout ──────────────────────────────────────────────────────

    public function test_logout_closes_the_login_session_row_and_clears_auth(): void
    {
        $user = $this->makeUser(['username' => 'bye01']);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'bye01',
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(200);

        $rowId = session('login_session_id');
        $this->assertNotNull($rowId);

        $this->postJson('/api/v1/auth/logout')
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم تسجيل الخروج بنجاح');

        $this->assertGuest();
        $this->assertSame(
            LoginSessionStatus::LoggedOut,
            LoginSession::query()->findOrFail($rowId)->status
        );
    }

    public function test_logout_requires_a_session(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
    }

    public function test_me_after_logout_is_401(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/v1/auth/logout')->assertStatus(200);

        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    // ── /auth/refresh ─────────────────────────────────────────────────────

    /**
     * Test 12 from the brief. The frontend's interceptor calls this ON a 401 to
     * try to recover, so the route must NOT itself require auth — a clean 401
     * from inside the handler is the contract, and `url !== '/auth/refresh'` in
     * api.ts is what stops the loop.
     */
    public function test_refresh_with_no_session_is_a_clean_401_from_the_handler(): void
    {
        $response = $this->postJson('/api/v1/auth/refresh');

        $response->assertStatus(401)->assertJsonPath('success', false);

        // Envelope intact — the frontend parses the body of this response.
        $this->assertSame(
            ['success', 'message', 'data'],
            array_keys($response->json())
        );
    }

    /** The route genuinely carries no auth middleware. */
    public function test_the_refresh_route_has_no_auth_middleware(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($r) => $r->getName() === 'auth.refresh');

        $this->assertNotNull($route);
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('active', $route->gatherMiddleware());
    }

    public function test_refresh_with_a_session_renews_it(): void
    {
        $user = $this->makeUser(['username' => 'refresh01']);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'refresh01',
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(200);

        $before = session()->getId();

        $this->postJson('/api/v1/auth/refresh')
            ->assertStatus(200)
            ->assertJsonPath('message', 'Session refreshed')
            ->assertJsonPath('data.refreshed', true);

        $this->assertNotSame($before, session()->getId(), 'refresh must regenerate the session id');
        $this->assertTrue(auth()->check(), 'the user must still be logged in after a refresh');
    }

    public function test_refresh_touches_the_login_session_row(): void
    {
        $user = $this->makeUser(['username' => 'touch01']);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'touch01',
            'password' => self::FIXTURE_PASSWORD,
        ]);

        $rowId = session('login_session_id');

        DB::table('login_sessions')
            ->where('id', $rowId)
            ->update(['last_activity_at' => now()->subHour()]);

        $this->postJson('/api/v1/auth/refresh')->assertStatus(200);

        $this->assertTrue(
            LoginSession::query()->findOrFail($rowId)->last_activity_at->gt(now()->subMinute())
        );
    }

    /**
     * A disabled account must not be able to renew its session. Cannot loop:
     * api.ts never retries the refresh call itself.
     */
    public function test_refresh_refuses_a_deactivated_account(): void
    {
        $user = $this->makeUser();
        $this->loginAs($user);

        $this->postJson('/api/v1/auth/refresh')->assertStatus(200);

        DB::table('users')->where('id', $user->getKey())->update(['is_active' => 0]);

        $this->startNextRequest();

        $this->postJson('/api/v1/auth/refresh')->assertStatus(401);
    }

    // ── The full cycle ────────────────────────────────────────────────────

    public function test_the_full_login_me_logout_cycle(): void
    {
        $user = $this->makeUser(['username' => 'cycle01', 'full_name' => 'دورة كاملة']);

        $login = $this->postJson('/api/v1/auth/login', [
            'username' => 'cycle01',
            'password' => self::FIXTURE_PASSWORD,
        ]);
        $login->assertStatus(200)->assertJsonPath('data.user.username', 'cycle01');

        $this->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.user.id', $user->getKey());

        $this->postJson('/api/v1/auth/logout')->assertStatus(200);

        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    /** The `me` and `login` payloads are built by ONE resource and must agree. */
    public function test_login_and_me_return_an_identical_user_payload(): void
    {
        $instructor = $this->makeInstructor(['الصف الثالث'], ['فيزياء']);

        $fromLogin = $this->postJson('/api/v1/auth/login', [
            'username' => $instructor->username,
            'password' => self::FIXTURE_PASSWORD,
        ])->json('data.user');

        $fromMe = $this->getJson('/api/v1/auth/me')->json('data.user');

        $this->assertSame($fromLogin, $fromMe);
    }

    public function test_a_user_deleted_between_login_and_me_cannot_be_resurrected_by_refresh(): void
    {
        $user = $this->makeUser(['username' => 'ghost01']);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'ghost01',
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(200);

        User::query()->whereKey($user->getKey())->delete();

        $this->startNextRequest();
        $this->getJson('/api/v1/auth/me')->assertStatus(401);

        $this->startNextRequest();
        $this->postJson('/api/v1/auth/refresh')->assertStatus(401);
    }
}
