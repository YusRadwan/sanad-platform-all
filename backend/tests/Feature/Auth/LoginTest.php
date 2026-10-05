<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\LoginSessionStatus;
use App\Enums\UserRole;
use App\Models\LoginSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests are about login semantics, not throttling; the limiter
        // has its own suite and a 5-per-minute budget would otherwise make
        // several of these fail for the wrong reason.
        $this->disableRateLimiting();
    }

    public function test_a_valid_login_returns_the_public_user_and_the_exact_arabic_message(): void
    {
        $user = $this->makeUser(['username' => 'student01', 'full_name' => 'طالب واحد']);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'student01',
            'password' => self::FIXTURE_PASSWORD,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم تسجيل الدخول بنجاح')
            ->assertJsonPath('data.user.id', $user->getKey())
            ->assertJsonPath('data.user.username', 'student01')
            ->assertJsonPath('data.user.role', 'student');

        $this->assertAuthenticatedAs($user);
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ `full_name` AND `fullName` MUST BOTH BE PRESENT.                     │
     * │ The duplicate is not cruft — Instructor components read camelCase and│
     * │ silently render the username in the teacher's place without it.      │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_the_payload_carries_both_spellings_of_full_name(): void
    {
        $this->makeUser(['username' => 'dupkey', 'full_name' => 'اسم كامل']);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'dupkey',
            'password' => self::FIXTURE_PASSWORD,
        ]);

        $response->assertJsonPath('data.user.full_name', 'اسم كامل')
            ->assertJsonPath('data.user.fullName', 'اسم كامل');
    }

    public function test_the_public_user_has_exactly_the_contract_keys_for_a_student(): void
    {
        $this->makeUser(['username' => 'keys01']);

        $keys = array_keys(
            $this->postJson('/api/v1/auth/login', [
                'username' => 'keys01',
                'password' => self::FIXTURE_PASSWORD,
            ])->json('data.user')
        );

        sort($keys);

        // "إدارة الأدمنز" (decisions 10.37 + 10.40) — actingEmployee/
        // employeePermissions are unconditional on every role, not just
        // instructor (see PublicUser's own docblock for why), so they are
        // part of every session payload's contract, including a student's.
        $expected = ['actingEmployee', 'avatar_url', 'created_at', 'email', 'employeePermissions', 'full_name', 'fullName', 'id', 'impersonating', 'is_active', 'role', 'student_code', 'username'];
        sort($expected);

        $this->assertSame($expected, $keys);
    }

    /** grades/subjects are attached ONLY for the instructor role. */
    public function test_an_instructor_also_receives_grades_and_subjects(): void
    {
        $instructor = $this->makeInstructor(['الصف الأول الثانوي'], ['رياضيات']);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $instructor->username,
            'password' => self::FIXTURE_PASSWORD,
        ]);

        $response->assertJsonPath('data.user.role', UserRole::Instructor->value)
            ->assertJsonPath('data.user.grades', ['الصف الأول الثانوي'])
            ->assertJsonPath('data.user.subjects', ['رياضيات']);
    }

    public function test_is_active_serialises_as_a_real_boolean_not_one_or_zero(): void
    {
        $this->makeUser(['username' => 'boolcheck']);

        $value = $this->postJson('/api/v1/auth/login', [
            'username' => 'boolcheck',
            'password' => self::FIXTURE_PASSWORD,
        ])->json('data.user.is_active');

        $this->assertTrue($value);
        $this->assertIsBool($value);
    }

    /** One generic message for both cases — no user enumeration. */
    public function test_an_unknown_username_and_a_wrong_password_share_one_message(): void
    {
        $this->makeUser(['username' => 'realuser']);

        $unknown = $this->postJson('/api/v1/auth/login', [
            'username' => 'nobody',
            'password' => self::FIXTURE_PASSWORD,
        ]);

        $wrong = $this->postJson('/api/v1/auth/login', [
            'username' => 'realuser',
            'password' => 'WrongPassword999',
        ]);

        $unknown->assertStatus(401)->assertJsonPath('message', 'اسم المستخدم أو كلمة المرور غير صحيحة');
        $wrong->assertStatus(401)->assertJsonPath('message', 'اسم المستخدم أو كلمة المرور غير صحيحة');

        $this->assertGuest();
    }

    /**
     * The username is a bound value, never SQL syntax.  A quote appended to a
     * real username must therefore remain a literal non-matching username;
     * knowing the real account's password cannot turn it into another query.
     */
    public function test_a_username_containing_a_sql_metacharacter_cannot_log_in(): void
    {
        $user = $this->makeUser(['username' => 'boundvalue01']);

        $this->postJson('/api/v1/auth/login', [
            'username' => $user->username . "'",
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(401)
            ->assertJsonPath('message', 'اسم المستخدم أو كلمة المرور غير صحيحة');

        $this->assertGuest();
    }

    /**
     * Order matters: the password is checked FIRST, so this message can only be
     * reached by someone who already proved they own the account.
     */
    public function test_a_disabled_account_gets_its_own_message(): void
    {
        $this->makeUser(['username' => 'disabled01', 'is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'disabled01',
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(401)->assertJsonPath('message', 'هذا الحساب غير مفعل');

        $this->assertGuest();
    }

    public function test_a_disabled_account_with_a_wrong_password_gets_the_generic_message(): void
    {
        $this->makeUser(['username' => 'disabled02', 'is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'disabled02',
            'password' => 'WrongPassword999',
        ])->assertJsonPath('message', 'اسم المستخدم أو كلمة المرور غير صحيحة');
    }

    /** SoftDeletes' global scope means a deleted account simply is not found. */
    public function test_a_soft_deleted_account_cannot_log_in(): void
    {
        $user = $this->makeUser(['username' => 'gone01']);
        $user->delete();

        $this->postJson('/api/v1/auth/login', [
            'username' => 'gone01',
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(401)->assertJsonPath('message', 'اسم المستخدم أو كلمة المرور غير صحيحة');
    }

    public function test_missing_fields_are_422_with_the_exact_messages(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonPath('data.errors.username.0', 'اسم المستخدم مطلوب')
            ->assertJsonPath('data.errors.password.0', 'كلمة المرور مطلوبة');
    }

    public function test_a_login_session_row_is_created_and_remembered(): void
    {
        $user = $this->makeUser(['username' => 'session01']);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'session01',
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(200);

        $row = LoginSession::query()->where('user_id', $user->getKey())->firstOrFail();

        $this->assertSame(LoginSessionStatus::Active, $row->status);
        $this->assertNotNull($row->ip_address);
        $this->assertSame($row->getKey(), session('login_session_id'));
    }

    /**
     * session_regenerate_id(true) equivalent. Auth::login() -> updateSession()
     * -> Session::migrate(true) does this natively; asserting it means a future
     * refactor to Auth::attempt/loginUsingId cannot quietly drop the fixation
     * defence.
     */
    public function test_the_session_id_is_regenerated_on_login(): void
    {
        $this->makeUser(['username' => 'fixation01']);

        $this->startSession();
        $before = session()->getId();

        $this->postJson('/api/v1/auth/login', [
            'username' => 'fixation01',
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(200);

        $this->assertNotSame($before, session()->getId());
    }

    /**
     * A 401 must never leak the submitted password into the log, and the log
     * must still name the targeted username so repeated attacks on one account
     * are visible.
     */
    public function test_a_failed_login_logs_the_username_and_never_the_password(): void
    {
        $this->makeUser(['username' => 'logged01']);

        \Illuminate\Support\Facades\Log::shouldReceive('channel')
            ->andReturnSelf();

        \Illuminate\Support\Facades\Log::shouldReceive('log')
            ->atLeast()->once()
            ->withArgs(function (string $level, string $message, array $ctx): bool {
                if (($ctx['event'] ?? null) !== 'login.failure') {
                    return true;
                }

                return $ctx['username'] === 'logged01'
                    && $ctx['reason'] === 'bad_password'
                    && !in_array('SuperSecret123456', $ctx, true);
            });

        \Illuminate\Support\Facades\Log::shouldReceive('warning')->andReturnNull();

        $this->postJson('/api/v1/auth/login', [
            'username' => 'logged01',
            'password' => 'SuperSecret123456',
        ])->assertStatus(401);
    }

    /** CSRF-EXEMPT: a client cannot hold a token before it has a session. */
    public function test_login_succeeds_with_no_csrf_token_at_all(): void
    {
        $this->makeUser(['username' => 'nocsrf01']);

        $response = $this->withCsrfEnforcementOn(
            fn () => $this->postJson('/api/v1/auth/login', [
                'username' => 'nocsrf01',
                'password' => self::FIXTURE_PASSWORD,
            ])
        );

        $response->assertStatus(200);
        $this->assertTrue(Auth::check());
    }
}
