<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Student;
use App\Models\User;
use App\Support\StudentCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * Student read / update / delete, plus the by-code lookup.
 *
 * StudentService::update()'s field semantics are NOT the same as Teacher's,
 * and the difference was read from backend/'s code rather than assumed — see
 * App\Actions\Student\UpdateStudent's docblock. Both matrices are asserted
 * here so a future "harmonisation" fails loudly.
 */
final class StudentCrudTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $studentUser;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();

        [$this->studentUser, $this->student] = $this->makeStudentWithCode('STU510078', [
            'gender' => 'ذكر',
            'education_type' => 'عام',
        ]);

        $this->studentUser->forceFill(['full_name' => 'طالب أصلي'])->save();

        $this->loginAsAdmin();
    }

    private function patchStudent(array $payload): void
    {
        $this->patchJson("/api/v1/admin/students/{$this->student->getKey()}", $payload)
            ->assertStatus(200);
    }

    private function freshStudent(): Student
    {
        return Student::query()->findOrFail($this->student->getKey());
    }

    private function freshUser(): User
    {
        return User::query()->findOrFail($this->studentUser->getKey());
    }

    // ══ UPDATE SEMANTICS — DIFFERENT FROM TEACHER'S ═════════════════════

    /**
     * @dataProvider clearableFields
     */
    public function test_a_clearable_field_absent_is_untouched(string $wire, string $column, string $original): void
    {
        $this->patchStudent(['fullName' => 'اسم']);

        $this->assertSame($original, $this->freshStudent()->getAttribute($column));
    }

    /**
     * @dataProvider clearableFields
     */
    public function test_a_clearable_field_empty_string_clears_it(string $wire, string $column): void
    {
        $this->patchStudent([$wire => '']);

        $this->assertSame(
            '',
            $this->freshStudent()->getAttribute($column),
            "{$wire}: backend/'s array_filter used an EXPLICIT `!== null` callback, so '' passes through."
        );
    }

    /**
     * @dataProvider clearableFields
     */
    public function test_a_clearable_field_real_value_updates_it(string $wire, string $column): void
    {
        $this->patchStudent([$wire => 'جديد']);

        $this->assertSame('جديد', $this->freshStudent()->getAttribute($column));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function clearableFields(): array
    {
        return [
            'studentPhone' => ['studentPhone', 'phone', '01012345678'],
            'parentPhone' => ['parentPhone', 'parent_phone', '01087654321'],
            'governorate' => ['governorate', 'governorate', 'القاهرة'],
            'gradeLevel' => ['gradeLevel', 'grade_level', 'الصف الأول الثانوي'],
            'educationalStage' => ['educationalStage', 'educational_stage', 'الثانوية'],
            'gender' => ['gender', 'gender', 'ذكر'],
            'eduType' => ['eduType', 'education_type', 'عام'],
        ];
    }

    /**
     * THE ONE FIELD THAT DOES NOT FOLLOW THE OTHERS. backend/ guards fullName
     * with `!empty()`, so '' is ignored — the same rule as the teacher form,
     * reached through a different idiom, and the reason this file does not
     * simply reuse Teacher's matrix.
     */
    public function test_fullName_empty_string_is_ignored_unlike_the_profile_fields(): void
    {
        $this->patchStudent(['fullName' => '']);

        $this->assertSame('طالب أصلي', $this->freshUser()->full_name);
    }

    public function test_fullName_real_value_updates_it(): void
    {
        $this->patchStudent(['fullName' => 'طالب جديد']);

        $this->assertSame('طالب جديد', $this->freshUser()->full_name);
    }

    public function test_an_explicit_null_leaves_a_profile_field_untouched(): void
    {
        $this->patchStudent(['studentPhone' => null]);

        $this->assertSame('01012345678', $this->freshStudent()->phone);
    }

    // ══ isActive — PRESENCE, NOT TRUTHINESS ═════════════════════════════

    public function test_isActive_absent_does_not_suspend_the_student(): void
    {
        $this->patchStudent(['studentPhone' => '01099999999']);

        $this->assertTrue((bool) $this->freshUser()->is_active);
    }

    public function test_isActive_false_suspends_the_student(): void
    {
        $this->patchStudent(['isActive' => false]);

        $this->assertFalse((bool) $this->freshUser()->is_active);
    }

    public function test_isActive_true_reactivates_the_student(): void
    {
        $this->studentUser->forceFill(['is_active' => false])->save();

        $this->patchStudent(['isActive' => true]);

        $this->assertTrue((bool) $this->freshUser()->is_active);
    }

    /** The status the frontend renders is a STRING derived from users.is_active. */
    public function test_the_status_field_reflects_the_users_active_flag(): void
    {
        $this->patchJson("/api/v1/admin/students/{$this->student->getKey()}", ['isActive' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_put_behaves_identically_to_patch(): void
    {
        $this->putJson("/api/v1/admin/students/{$this->student->getKey()}", [
            'fullName' => '',
            'studentPhone' => '',
            'isActive' => false,
        ])->assertStatus(200);

        $this->assertSame('طالب أصلي', $this->freshUser()->full_name);
        $this->assertSame('', $this->freshStudent()->phone);
        $this->assertFalse((bool) $this->freshUser()->is_active);
    }

    // ══ DECISION #4 — BY-CODE LOOKUP ════════════════════════════════════

    public function test_the_full_code_finds_the_student(): void
    {
        $this->getJson('/api/v1/admin/students/by-code/STU510078')
            ->assertStatus(200)
            ->assertJsonPath('data.student.studentCode', 'STU510078');
    }

    /** The bare six digits are still accepted — admin flexibility is unchanged. */
    public function test_the_bare_six_digits_find_the_student(): void
    {
        $this->getJson('/api/v1/admin/students/by-code/510078')
            ->assertStatus(200)
            ->assertJsonPath('data.student.studentCode', 'STU510078');
    }

    public function test_lower_case_is_normalised(): void
    {
        $this->getJson('/api/v1/admin/students/by-code/stu510078')
            ->assertStatus(200)
            ->assertJsonPath('data.student.studentCode', 'STU510078');
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE OVER-MATCH THAT backend/'s SUFFIX LIKE ALLOWED.                  ║
     * ║                                                                      ║
     * ║ `student_code LIKE CONCAT('%', '510078')` matches ANY code ending in ║
     * ║ those digits. With normalise-then-exact-match, the query is          ║
     * ║ `= 'STU510078'` and a differently-prefixed neighbour cannot be       ║
     * ║ returned by accident.                                                ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_suffix_collision_cannot_return_the_wrong_student(): void
    {
        // A row whose code ends in the same six digits. It cannot arise from
        // generateStudentCode() — which is exactly the invariant decision #4
        // rests on — so it is inserted directly to model the hazard.
        $other = $this->makeUser(['role' => \App\Enums\UserRole::Student]);
        DB::table('students')->insert([
            'user_id' => $other->getKey(),
            'student_code' => 'OLD510078',
            'phone' => '01000000000',
            'parent_phone' => '01000000001',
            'governorate' => 'القاهرة',
            'grade_level' => 'الصف الأول الثانوي',
            'created_at' => now(),
        ]);

        $this->getJson('/api/v1/admin/students/by-code/510078')
            ->assertStatus(200)
            ->assertJsonPath('data.student.id', $this->student->getKey());
    }

    /**
     * The lookup is an EQUALITY on the unique column — no leading wildcard, so
     * uq_students_code is usable. Asserted on the SQL, because the response
     * looks identical either way.
     */
    public function test_the_lookup_query_is_an_exact_match_not_a_like(): void
    {
        DB::enableQueryLog();
        $this->getJson('/api/v1/admin/students/by-code/510078')->assertStatus(200);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $lookup = collect($queries)->first(
            fn (array $q): bool => str_contains($q['query'], 'student_code')
        );

        $this->assertNotNull($lookup);
        $this->assertStringContainsString('`student_code` = ?', $lookup['query']);
        $this->assertStringNotContainsString('like', strtolower($lookup['query']));
        $this->assertContains('STU510078', $lookup['bindings']);
    }

    // ── shape 422 vs unknown 404, which binding alone cannot distinguish ──

    /** @dataProvider malformedCodes */
    public function test_a_malformed_code_is_422_before_any_query(string $code): void
    {
        $this->getJson('/api/v1/admin/students/by-code/' . $code)
            ->assertStatus(422)
            ->assertJsonPath('data.errors.code.0', StudentCode::INVALID_MESSAGE);
    }

    /** @return array<string, array{0: string}> */
    public static function malformedCodes(): array
    {
        return [
            'letters' => ['banana'],
            'too few digits' => ['12345'],
            'too many digits' => ['1234567'],
            'wrong prefix' => ['ABC510078'],
            'prefix only' => ['STU'],
        ];
    }

    public function test_a_well_formed_but_unknown_code_is_404(): void
    {
        $this->getJson('/api/v1/admin/students/by-code/STU999999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'لا يوجد طالب بهذا الكود');
    }

    public function test_the_lookup_returns_every_subscription_whatever_its_status(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['title' => 'كورس']);

        $this->makeSubscription(
            $this->studentUser,
            $course,
            \App\Enums\SubscriptionStatus::Cancelled
        );

        // NOT filtered by scopeActive(): the page's purpose is the student's
        // whole history. That is a deliberate absence, not a missing filter.
        $this->getJson('/api/v1/admin/students/by-code/STU510078')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.subscriptions')
            ->assertJsonPath('data.subscriptions.0.course_title', 'كورس')
            ->assertJsonPath('data.subscriptions.0.status', 'cancelled');
    }

    // ══ READ / DELETE ═══════════════════════════════════════════════════

    public function test_an_unknown_student_id_is_404_with_the_contract_message(): void
    {
        $this->getJson('/api/v1/admin/students/' . Str::uuid7())
            ->assertStatus(404)
            ->assertJsonPath('message', 'Student not found');
    }

    public function test_a_soft_deleted_student_is_404_though_the_profile_row_remains(): void
    {
        $this->studentUser->delete();

        $this->getJson("/api/v1/admin/students/{$this->student->getKey()}")->assertStatus(404);

        $this->assertNotNull(Student::query()->find($this->student->getKey()));
    }

    public function test_a_soft_deleted_student_is_not_reachable_by_code_either(): void
    {
        $this->studentUser->delete();

        $this->getJson('/api/v1/admin/students/by-code/STU510078')->assertStatus(404);
    }

    // ══ DECISION #1 — RE-AUTH IS NEW ON THIS ENDPOINT ═══════════════════

    /**
     * NEW BEHAVIOUR. backend/StudentService::delete() took no actor and
     * verified no password: any admin session could soft-delete any student
     * with a bare DELETE.
     */
    public function test_delete_without_a_password_is_401(): void
    {
        $this->deleteJson("/api/v1/admin/students/{$this->student->getKey()}")
            ->assertStatus(401)
            ->assertJsonPath('message', 'كلمة مرور الإدارة العامة مطلوبة لتأكيد الحذف');

        $this->assertNotNull(User::query()->find($this->student->getKey()));
    }

    public function test_delete_with_the_wrong_password_is_401(): void
    {
        $this->deleteJson("/api/v1/admin/students/{$this->student->getKey()}", ['password' => 'nope'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'كلمة مرور الإدارة العامة غير صحيحة');
    }

    public function test_delete_succeeds_with_the_admins_own_password(): void
    {
        $this->deleteJson("/api/v1/admin/students/{$this->student->getKey()}", $this->reauth())
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم الحذف بنجاح');

        $this->assertSoftDeleted('users', ['id' => $this->student->getKey()]);
        $this->assertNotNull(
            Student::query()->find($this->student->getKey()),
            'The profile row is deliberately left intact.'
        );
    }

    public function test_the_password_is_accepted_from_the_header(): void
    {
        $this->deleteJson(
            "/api/v1/admin/students/{$this->student->getKey()}",
            [],
            ['x-admin-password' => self::FIXTURE_PASSWORD]
        )->assertStatus(200);
    }

    // ══ DECISION 10.19 — DELETE BLOCKED BY AN ACTIVE SUBSCRIPTION ═══════

    public function test_delete_is_blocked_when_the_student_has_an_active_subscription(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['title' => 'كورس']);
        $this->makeSubscription($this->studentUser, $course, \App\Enums\SubscriptionStatus::Active);

        $this->deleteJson("/api/v1/admin/students/{$this->student->getKey()}", $this->reauth())
            ->assertStatus(409);

        $this->assertNotNull(User::query()->find($this->student->getKey()));
    }

    /** A CANCELLED subscription does not block the delete — only Active does. */
    public function test_delete_succeeds_when_every_subscription_is_cancelled(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['title' => 'كورس']);
        $this->makeSubscription($this->studentUser, $course, \App\Enums\SubscriptionStatus::Cancelled);

        $this->deleteJson("/api/v1/admin/students/{$this->student->getKey()}", $this->reauth())
            ->assertStatus(200);

        $this->assertSoftDeleted('users', ['id' => $this->student->getKey()]);
    }

    // ══ DECISION 10.19 — ADMIN PASSWORD RESET ON THE EDIT ENDPOINT ══════

    public function test_password_absent_leaves_the_password_untouched(): void
    {
        $this->patchStudent(['fullName' => 'اسم']);

        $this->post('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/login', [
            'username' => $this->studentUser->username,
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(200);
    }

    public function test_password_empty_string_leaves_the_password_untouched(): void
    {
        $this->patchStudent(['password' => '']);

        $this->post('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/login', [
            'username' => $this->studentUser->username,
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(200);
    }

    public function test_a_real_password_resets_it_and_the_old_one_stops_working(): void
    {
        $this->patchStudent(['password' => 'BrandNewHorse99Battery']);

        $this->post('/api/v1/auth/logout');

        $this->postJson('/api/v1/auth/login', [
            'username' => $this->studentUser->username,
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'username' => $this->studentUser->username,
            'password' => 'BrandNewHorse99Battery',
        ])->assertStatus(200);
    }

    public function test_a_password_that_fails_the_policy_is_422(): void
    {
        $this->patchJson("/api/v1/admin/students/{$this->student->getKey()}", ['password' => '123'])
            ->assertStatus(422);
    }

    // ══ studentPhone -> username sync — the login bug found in the security ═
    // ══ log (a password reset "worked" but login still failed because the  ═
    // ══ account's username had drifted from the phone shown everywhere).   ═

    public function test_a_real_new_phone_also_becomes_the_login_username(): void
    {
        $this->patchStudent(['studentPhone' => '01099998888']);

        $this->assertSame('01099998888', $this->freshUser()->username);

        $this->post('/api/v1/auth/logout');

        $this->postJson('/api/v1/auth/login', [
            'username' => '01099998888',
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(200);
    }

    public function test_the_old_username_stops_working_once_the_phone_changes(): void
    {
        $oldUsername = $this->studentUser->username;

        $this->patchStudent(['studentPhone' => '01099998888']);

        $this->post('/api/v1/auth/logout');

        $this->postJson('/api/v1/auth/login', [
            'username' => $oldUsername,
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(401);
    }

    public function test_phone_absent_leaves_the_username_untouched(): void
    {
        $this->patchStudent(['fullName' => 'اسم']);

        $this->assertSame($this->studentUser->username, $this->freshUser()->username);
    }

    public function test_clearing_the_phone_never_touches_the_username(): void
    {
        $this->patchStudent(['studentPhone' => '']);

        $this->assertSame($this->studentUser->username, $this->freshUser()->username);
        $this->assertSame('', $this->freshStudent()->phone);
    }

    public function test_a_phone_already_used_as_someone_elses_username_is_422_and_changes_nothing(): void
    {
        $other = $this->makeUser(['username' => '01055551234']);

        $this->patchJson(
            "/api/v1/admin/students/{$this->student->getKey()}",
            ['studentPhone' => '01055551234']
        )->assertStatus(422)->assertJsonPath('data.errors.studentPhone.0', 'رقم الهاتف هذا مستخدم بالفعل كاسم دخول لحساب آخر');

        $this->assertSame($this->studentUser->username, $this->freshUser()->username);
        $this->assertSame('01012345678', $this->freshStudent()->phone);
        $this->assertSame('01055551234', $other->fresh()->username);
    }

    // ══ ROLE GATE ═══════════════════════════════════════════════════════

    public function test_a_non_admin_cannot_reach_the_admin_student_routes(): void
    {
        $this->post('/api/v1/auth/logout');
        $this->loginAs($this->makeInstructor());

        $this->getJson('/api/v1/admin/students')->assertStatus(403);
        $this->getJson('/api/v1/admin/students/by-code/STU510078')->assertStatus(403);
    }
}
