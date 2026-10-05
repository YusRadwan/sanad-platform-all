<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Rules\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * PATCH /auth/update-credentials.
 *
 * Every test here corresponds to a hole the legacy implementation had; see
 * App\Actions\Auth\UpdateCredentials for the four rules and what each one fixed.
 */
final class UpdateCredentialsTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private const NEW_PASSWORD = 'BrandNewPass2026x';

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();
    }

    // ── (a) Re-authentication gate ────────────────────────────────────────

    public function test_a_password_change_without_the_current_password_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/update-credentials', ['newPassword' => self::NEW_PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath(
                'data.errors.currentPassword.0',
                'كلمة المرور الحالية مطلوبة لتأكيد أي تغيير في بيانات الدخول'
            );

        $this->assertTrue(Hash::check(self::FIXTURE_PASSWORD, $user->fresh()->getAuthPassword()));
    }

    /**
     * The account-takeover primitive: repointing the email from a hijacked
     * session used to need no re-authentication at all.
     */
    public function test_an_email_only_change_also_requires_the_current_password(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/update-credentials', ['email' => 'attacker@evil.test'])
            ->assertStatus(422)
            ->assertJsonPath(
                'data.errors.currentPassword.0',
                'كلمة المرور الحالية مطلوبة لتأكيد أي تغيير في بيانات الدخول'
            );

        $this->assertNotSame('attacker@evil.test', $user->fresh()->email);
    }

    public function test_a_wrong_current_password_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/update-credentials', [
                'currentPassword' => 'NotThePassword1',
                'newPassword' => self::NEW_PASSWORD,
            ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.currentPassword.0', 'كلمة المرور الحالية غير صحيحة');
    }

    public function test_an_empty_payload_says_nothing_was_sent(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/update-credentials', [])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.newPassword.0', 'لم يتم إرسال أي بيانات للتحديث');
    }

    /** The UI pre-fills both fields; resending them unchanged is a no-op. */
    public function test_resubmitting_the_current_username_and_email_is_a_no_op_not_a_conflict(): void
    {
        $user = $this->makeUser(['username' => 'same01', 'email' => 'same01@sanad.local']);

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/update-credentials', [
                'currentPassword' => self::FIXTURE_PASSWORD,
                'newUsername' => 'same01',
                'email' => 'SAME01@SANAD.LOCAL', // same address, different case
            ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.newPassword.0', 'لم يتم إرسال أي بيانات للتحديث');
    }

    // ── (b) Password policy ───────────────────────────────────────────────

    public function test_a_weak_new_password_is_rejected_with_the_policy_text(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/update-credentials', [
                'currentPassword' => self::FIXTURE_PASSWORD,
                'newPassword' => '1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.newPassword.0', PasswordPolicy::describe());
    }

    public function test_the_new_password_must_differ_from_the_current_one(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/update-credentials', [
                'currentPassword' => self::FIXTURE_PASSWORD,
                'newPassword' => self::FIXTURE_PASSWORD,
            ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.newPassword.0', 'يجب أن تختلف كلمة المرور الجديدة عن الحالية');
    }

    // ── Happy paths ───────────────────────────────────────────────────────

    public function test_a_valid_password_change_succeeds_and_is_usable_for_the_next_login(): void
    {
        $user = $this->makeUser(['username' => 'changer01']);

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/update-credentials', [
                'currentPassword' => self::FIXTURE_PASSWORD,
                'newPassword' => self::NEW_PASSWORD,
            ])
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم تحديث البيانات بنجاح')
            ->assertJsonPath('data.updated', true)
            ->assertJsonPath('data.passwordChanged', true);

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->getAuthPassword()));

        // Old password is dead, new one works.
        $this->postJson('/api/v1/auth/login', [
            'username' => 'changer01',
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'changer01',
            'password' => self::NEW_PASSWORD,
        ])->assertStatus(200);
    }

    public function test_an_email_and_name_change_succeeds(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/update-credentials', [
                'currentPassword' => self::FIXTURE_PASSWORD,
                'email' => 'renamed@sanad.local',
                'full_name' => 'الاسم الجديد',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.passwordChanged', false);

        $fresh = $user->fresh();
        $this->assertSame('renamed@sanad.local', $fresh->email);
        $this->assertSame('الاسم الجديد', $fresh->full_name);
    }

    // ── (c) Identity-field validation ─────────────────────────────────────

    public function test_a_taken_email_is_rejected(): void
    {
        $this->makeUser(['email' => 'taken@sanad.local']);
        $user = $this->makeUser();

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/update-credentials', [
                'currentPassword' => self::FIXTURE_PASSWORD,
                'email' => 'TAKEN@sanad.local', // case-insensitive check
            ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.email.0', 'هذا البريد الإلكتروني مستخدم بالفعل');
    }

    public function test_a_taken_username_is_rejected(): void
    {
        $this->makeUser(['username' => 'occupied']);
        $user = $this->makeUser();

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/update-credentials', [
                'currentPassword' => self::FIXTURE_PASSWORD,
                'newUsername' => 'occupied',
            ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.newUsername.0', 'اسم المستخدم مستخدم بالفعل');
    }

    public function test_a_malformed_email_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/update-credentials', [
                'currentPassword' => self::FIXTURE_PASSWORD,
                'email' => 'not-an-email',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['email']]]);
    }

    /**
     * A bad email must not leave the password half-changed: identity fields are
     * validated BEFORE anything is written.
     */
    public function test_a_rejected_email_does_not_change_the_password(): void
    {
        $this->makeUser(['email' => 'clash@sanad.local']);
        $user = $this->makeUser();

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/update-credentials', [
                'currentPassword' => self::FIXTURE_PASSWORD,
                'newPassword' => self::NEW_PASSWORD,
                'email' => 'clash@sanad.local',
            ])
            ->assertStatus(422);

        $this->assertTrue(Hash::check(self::FIXTURE_PASSWORD, $user->fresh()->getAuthPassword()));
    }

    // ── (d) Session rotation ──────────────────────────────────────────────

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THESE TWO DRIVE THE ACTION DIRECTLY, WITH A REAL SESSION STORE,      │
     * │ INSTEAD OF GOING THROUGH THE HTTP TEST CLIENT.                       │
     * │                                                                      │
     * │ The client does not carry Set-Cookie from one test request into the  │
     * │ next, so StartSession mints a fresh id every time and                │
     * │ `session()->getId()` differs between ANY two requests — a feature-   │
     * │ level assertion here would be green whether the code rotates or not. │
     * │ (Measured: the "does NOT rotate" case failed against correct code.)  │
     * │                                                                      │
     * │ A Store instance the test owns makes the assertion exact. The        │
     * │ end-to-end proof that the real `sanad_session` COOKIE VALUE changes  │
     * │ is in the live-HTTP pass documented in the stage report.             │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_password_change_rotates_the_session_id(): void
    {
        $user = $this->makeUser();
        [$request, $store] = $this->requestWithRealSession();

        $before = $store->getId();

        app(\App\Actions\Auth\UpdateCredentials::class)($request, $user, [
            'currentPassword' => self::FIXTURE_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        $this->assertNotSame($before, $store->getId(), 'the session id MUST rotate after a password change');
    }

    /** An email-only change does NOT rotate — matching the legacy behaviour. */
    public function test_an_email_only_change_does_not_rotate_the_session_id(): void
    {
        $user = $this->makeUser();
        [$request, $store] = $this->requestWithRealSession();

        $before = $store->getId();

        app(\App\Actions\Auth\UpdateCredentials::class)($request, $user, [
            'currentPassword' => self::FIXTURE_PASSWORD,
            'email' => 'moved@sanad.local',
        ]);

        $this->assertSame($before, $store->getId());
    }

    /**
     * The session payload must SURVIVE the rotation — migrate(true) carries it
     * over. If it did not, changing your password would silently sign you out,
     * which the legacy code did not do and the settings page does not expect.
     */
    public function test_the_session_payload_survives_the_rotation(): void
    {
        $user = $this->makeUser();
        [$request, $store] = $this->requestWithRealSession();

        $store->put('login_session_id', 'a-login-row-id');

        app(\App\Actions\Auth\UpdateCredentials::class)($request, $user, [
            'currentPassword' => self::FIXTURE_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        $this->assertSame('a-login-row-id', $store->get('login_session_id'));
    }

    /** @return array{0: \Illuminate\Http\Request, 1: \Illuminate\Session\Store} */
    private function requestWithRealSession(): array
    {
        $store = new \Illuminate\Session\Store(
            'sanad_session',
            new \Illuminate\Session\ArraySessionHandler(120)
        );
        $store->start();

        $request = \Illuminate\Http\Request::create('/api/v1/auth/update-credentials', 'PATCH');
        $request->setLaravelSession($store);

        return [$request, $store];
    }

    // ── Access control ────────────────────────────────────────────────────

    public function test_it_requires_authentication(): void
    {
        $this->patchJson('/api/v1/auth/update-credentials', [
            'currentPassword' => self::FIXTURE_PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ])->assertStatus(401);
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE REAL WRITE OPERATION THAT PROVES CSRF END TO END.                │
     * │ Unlike login/register this route is NOT exempt, so a mutating call   │
     * │ with no X-CSRF-Token must be 403 (backend/'s status, not Laravel's   │
     * │ native 419) and the same call WITH the token must succeed.           │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_without_a_csrf_token_the_write_is_403(): void
    {
        $user = $this->makeUser();

        $response = $this->withCsrfEnforcementOn(
            fn () => $this->actingAs($user)->patchJson('/api/v1/auth/update-credentials', [
                'currentPassword' => self::FIXTURE_PASSWORD,
                'newPassword' => self::NEW_PASSWORD,
            ])
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotSame(419, $response->getStatusCode());
        $this->assertTrue(Hash::check(self::FIXTURE_PASSWORD, $user->fresh()->getAuthPassword()));
    }

    public function test_with_a_valid_csrf_token_the_write_succeeds(): void
    {
        $user = $this->makeUser();

        $this->startSession();
        $token = session()->token();

        $response = $this->withCsrfEnforcementOn(
            fn () => $this->actingAs($user)
                ->withHeaders(['X-CSRF-Token' => $token])
                ->patchJson('/api/v1/auth/update-credentials', [
                    'currentPassword' => self::FIXTURE_PASSWORD,
                    'newPassword' => self::NEW_PASSWORD,
                ])
        );

        $response->assertStatus(200);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->getAuthPassword()));
    }
}
