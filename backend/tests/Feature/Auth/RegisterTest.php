<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * POST /auth/register — the single legacy no-curriculum payload shape.
 * See App\Http\Requests\Auth\RegisterRequest for the full story.
 */
final class RegisterTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 3 signups per hour would fail most of this file for the wrong reason.
        $this->disableRateLimiting();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'fullName' => 'طالب جديد',
            'studentPhone' => '01012345678',
            'parentPhone' => '01098765432',
            'governorate' => 'القاهرة',
            'gender' => 'male',
            'educationalStage' => 'ثانوي',
            'gradeLevel' => 'الصف الأول الثانوي',
            'password' => 'CorrectHorse42Battery',
        ], $overrides);
    }

    public function test_registration_works_via_the_legacy_digits_eleven_path(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم إنشاء الحساب بنجاح');

        $this->assertMatchesRegularExpression('/^STU\d{6}$/', $response->json('data.studentCode'));

        $user = User::query()->where('username', '01012345678')->firstOrFail();

        $this->assertSame('01012345678@students.sanad.local', $user->email);
        $this->assertSame(UserRole::Student, $user->role);
        $this->assertTrue($user->is_active);

        Student::query()->whereKey($user->getKey())->firstOrFail();
    }

    public function test_the_legacy_path_still_rejects_a_ten_digit_phone(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['studentPhone' => '0101234567']))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.studentPhone.0', 'الحقل studentPhone يجب أن يتكون من 11 أرقام');
    }

    public function test_the_password_policy_applies_at_registration(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['password' => 'abc12']))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.password.0', \App\Rules\StudentPasswordPolicy::describe());
    }

    public function test_a_simple_six_character_password_is_accepted(): void
    {
        // The student registration policy is deliberately weak: any password
        // is acceptable as long as it is at least 6 characters, with no
        // composition requirement (unlike App\Rules\PasswordPolicy, which
        // still governs teacher/admin accounts).
        $this->postJson('/api/v1/auth/register', $this->payload(['password' => 'aaaaaa']))
            ->assertStatus(201);
    }

    public function test_the_password_is_stored_as_a_verifiable_bcrypt_hash(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(201);

        $user = User::query()->where('username', '01012345678')->firstOrFail();

        $this->assertNotSame('CorrectHorse42Battery', $user->getAuthPassword());
        $this->assertTrue(Hash::check('CorrectHorse42Battery', $user->getAuthPassword()));
    }

    // ── Duplicate phone ───────────────────────────────────────────────────

    public function test_a_duplicate_phone_is_rejected_with_the_exact_message(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(201);

        $this->postJson('/api/v1/auth/register', $this->payload(['parentPhone' => '01011112222']))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.studentPhone.0', 'هذا الهاتف مسجل بالفعل');
    }

    public function test_parent_phone_may_not_equal_student_phone(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['parentPhone' => '01012345678']))
            ->assertStatus(422)
            ->assertJsonPath('data.errors.parentPhone.0', 'لا يمكن أن يتطابق رقم هاتف ولي الأمر مع هاتف الطالب');
    }

    // ── Atomicity ─────────────────────────────────────────────────────────

    /**
     * Without a transaction, a failure on the students insert left an orphan
     * users row holding the phone hostage forever. Forcing the second insert to
     * fail proves the rollback.
     */
    public function test_a_failed_student_insert_rolls_the_user_back(): void
    {
        // A grade_level longer than the column allows makes the students INSERT
        // fail while the users INSERT has already succeeded.
        $this->postJson('/api/v1/auth/register', $this->payload([
            'gradeLevel' => str_repeat('ص', 300),
        ]));

        $this->assertDatabaseMissing('users', ['username' => '01012345678']);
        $this->assertSame(0, Student::query()->count());
    }

    // ── CSRF ──────────────────────────────────────────────────────────────

    public function test_register_is_csrf_exempt(): void
    {
        $response = $this->withCsrfEnforcementOn(
            fn () => $this->postJson('/api/v1/auth/register', $this->payload())
        );

        $response->assertStatus(201);
    }

    /** Registration must NOT sign the new account in — the wizard sends them to /login. */
    public function test_registration_does_not_authenticate_the_new_account(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(201);

        $this->assertGuest();
    }

    public function test_optional_fields_may_be_omitted(): void
    {
        $payload = $this->payload();
        unset($payload['gender'], $payload['educationalStage']);

        $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);

        $student = Student::query()->firstOrFail();
        $this->assertNull($student->gender);
        $this->assertNull($student->educational_stage);
    }
}
