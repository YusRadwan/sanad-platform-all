<?php

declare(strict_types=1);

namespace Tests\Feature\Center;

use App\Models\CenterCode;
use App\Models\CenterGroup;
use App\Models\CenterPaymentRecord;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * "متابعة مدفوعات السنتر" — covers the 10 scenarios the feature request
 * named explicitly: cross-teacher isolation (list, search-by-code,
 * search-by-center-code, direct id access), month/session period
 * separation, September/October separation, a shared student keeping two
 * independent records with two teachers, and — CRITICAL — zero effect on
 * any financial table (Subscription) anywhere in the platform.
 */
final class CenterPaymentTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $teacherA;

    private User $teacherB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();

        [$this->teacherA] = $this->makeTeacherWithProfile([], ['full_name' => 'المعلّم أ']);
        [$this->teacherB] = $this->makeTeacherWithProfile([], ['full_name' => 'المعلّم ب']);
    }

    // ══ 1-2 — each teacher sees only their own group's roster ═══════════

    public function test_teacher_a_sees_only_students_in_their_own_group(): void
    {
        $codeA = $this->makeCenterCode($this->teacherA);
        $groupA = $this->makeCenterGroup($this->teacherA);
        [, $studentA] = $this->joinedStudent($codeA);
        $this->addToRoster($groupA, $studentA->getKey());

        $codeB = $this->makeCenterCode($this->teacherB);
        $groupB = $this->makeCenterGroup($this->teacherB);
        [, $studentB] = $this->joinedStudent($codeB);
        $this->addToRoster($groupB, $studentB->getKey());

        $this->loginAs($this->teacherA);
        $rows = $this->getJson($this->studentsUrl($groupA))->assertStatus(200)->json('data.students');

        $this->assertCount(1, $rows);
        $this->assertSame((string) $studentA->getKey(), $rows[0]['student_id']);
    }

    public function test_teacher_b_sees_only_students_in_their_own_group(): void
    {
        $codeA = $this->makeCenterCode($this->teacherA);
        $groupA = $this->makeCenterGroup($this->teacherA);
        [, $studentA] = $this->joinedStudent($codeA);
        $this->addToRoster($groupA, $studentA->getKey());

        $codeB = $this->makeCenterCode($this->teacherB);
        $groupB = $this->makeCenterGroup($this->teacherB);
        [, $studentB] = $this->joinedStudent($codeB);
        $this->addToRoster($groupB, $studentB->getKey());

        $this->loginAs($this->teacherB);
        $rows = $this->getJson($this->studentsUrl($groupB))->assertStatus(200)->json('data.students');

        $this->assertCount(1, $rows);
        $this->assertSame((string) $studentB->getKey(), $rows[0]['student_id']);
    }

    // ══ 3 — direct id manipulation cannot reach another teacher's data ═══

    public function test_teacher_a_cannot_list_teacher_bs_group_by_id(): void
    {
        $groupB = $this->makeCenterGroup($this->teacherB);

        $this->loginAs($this->teacherA);
        $this->getJson($this->studentsUrl($groupB))->assertStatus(403);
    }

    public function test_teacher_a_cannot_set_payment_status_on_teacher_bs_group(): void
    {
        $codeB = $this->makeCenterCode($this->teacherB);
        $groupB = $this->makeCenterGroup($this->teacherB);
        [, $studentB] = $this->joinedStudent($codeB);
        $this->addToRoster($groupB, $studentB->getKey());

        $this->loginAs($this->teacherA);
        $this->postJson('/api/v1/center/payments', [
            'group_id' => $groupB->getKey(),
            'student_id' => $studentB->getKey(),
            'payment_type' => 'month',
            'period' => '2026-09',
            'status' => 'paid',
        ])->assertStatus(403);

        $this->assertSame(0, CenterPaymentRecord::query()->count());
    }

    public function test_teacher_a_cannot_open_teacher_bs_student_report(): void
    {
        $codeB = $this->makeCenterCode($this->teacherB);
        $groupB = $this->makeCenterGroup($this->teacherB);
        [, $studentB] = $this->joinedStudent($codeB);
        $this->addToRoster($groupB, $studentB->getKey());

        $this->loginAs($this->teacherA);
        $this->getJson("/api/v1/center/payments/students/{$studentB->getKey()}/report")
            ->assertStatus(404);
    }

    // ══ 4-5 — search stays scoped to the current teacher ═════════════════

    public function test_searching_by_student_code_never_leaks_another_teachers_student(): void
    {
        $codeA = $this->makeCenterCode($this->teacherA);
        $groupA = $this->makeCenterGroup($this->teacherA);
        [, $studentA] = $this->joinedStudent($codeA);
        $this->addToRoster($groupA, $studentA->getKey());

        $codeB = $this->makeCenterCode($this->teacherB);
        $groupB = $this->makeCenterGroup($this->teacherB);
        [, $studentB] = $this->joinedStudent($codeB);
        $this->addToRoster($groupB, $studentB->getKey());

        $this->loginAs($this->teacherA);
        // Searching Teacher A's OWN group with Teacher B's student_code
        // finds nothing — it is simply not in this roster, never a leak.
        $rows = $this->getJson($this->studentsUrl($groupA, ['q' => $studentB->student_code]))
            ->assertStatus(200)->json('data.students');

        $this->assertCount(0, $rows);
    }

    public function test_searching_by_center_code_never_leaks_another_teachers_student(): void
    {
        $codeA = $this->makeCenterCode($this->teacherA);
        $groupA = $this->makeCenterGroup($this->teacherA);
        [, $studentA] = $this->joinedStudent($codeA);
        $this->addToRoster($groupA, $studentA->getKey());

        $codeB = $this->makeCenterCode($this->teacherB);
        $groupB = $this->makeCenterGroup($this->teacherB);
        [, $studentB] = $this->joinedStudent($codeB);
        $this->addToRoster($groupB, $studentB->getKey());

        $this->loginAs($this->teacherA);
        $rows = $this->getJson($this->studentsUrl($groupA, ['q' => $codeB->code]))
            ->assertStatus(200)->json('data.students');

        $this->assertCount(0, $rows);

        // The matching search, against the RIGHT teacher's own group, works.
        $rows = $this->getJson($this->studentsUrl($groupA, ['q' => $codeA->code]))
            ->assertStatus(200)->json('data.students');
        $this->assertCount(1, $rows);
    }

    // ══ 6 — month and session records never mix ══════════════════════════

    public function test_month_and_session_payments_are_independent_records(): void
    {
        $code = $this->makeCenterCode($this->teacherA);
        $group = $this->makeCenterGroup($this->teacherA);
        [, $student] = $this->joinedStudent($code);
        $this->addToRoster($group, $student->getKey());

        $this->loginAs($this->teacherA);

        $this->postJson('/api/v1/center/payments', [
            'group_id' => $group->getKey(),
            'student_id' => $student->getKey(),
            'payment_type' => 'month',
            'period' => '2026-09',
            'status' => 'paid',
        ])->assertStatus(200);

        // The SESSION-type status for the same student stays "unpaid"
        // (i.e. no matching record) even though the month is "paid".
        $rows = $this->getJson($this->studentsUrl($group, ['payment_type' => 'session', 'period' => '2026-09-07']))
            ->assertStatus(200)->json('data.students');

        $this->assertSame('unpaid', $rows[0]['status']);
        $this->assertSame(1, CenterPaymentRecord::query()->count());
    }

    // ══ 7 — one month never bleeds into another ═══════════════════════════

    public function test_september_and_october_are_independent_records(): void
    {
        $code = $this->makeCenterCode($this->teacherA);
        $group = $this->makeCenterGroup($this->teacherA);
        [, $student] = $this->joinedStudent($code);
        $this->addToRoster($group, $student->getKey());

        $this->loginAs($this->teacherA);

        $this->postJson('/api/v1/center/payments', [
            'group_id' => $group->getKey(),
            'student_id' => $student->getKey(),
            'payment_type' => 'month',
            'period' => '2026-09',
            'status' => 'paid',
        ])->assertStatus(200);

        $octoberRows = $this->getJson($this->studentsUrl($group, ['payment_type' => 'month', 'period' => '2026-10']))
            ->assertStatus(200)->json('data.students');
        $this->assertSame('unpaid', $octoberRows[0]['status']);

        $septemberRows = $this->getJson($this->studentsUrl($group, ['payment_type' => 'month', 'period' => '2026-09']))
            ->assertStatus(200)->json('data.students');
        $this->assertSame('paid', $septemberRows[0]['status']);
    }

    // ══ 8 — zero effect on any financial table on the platform ═══════════

    public function test_recording_payments_never_touches_the_subscriptions_table(): void
    {
        $code = $this->makeCenterCode($this->teacherA);
        $group = $this->makeCenterGroup($this->teacherA);
        [, $student] = $this->joinedStudent($code);
        $this->addToRoster($group, $student->getKey());

        $this->loginAs($this->teacherA);

        $this->postJson('/api/v1/center/payments', [
            'group_id' => $group->getKey(),
            'student_id' => $student->getKey(),
            'payment_type' => 'session',
            'period' => '2026-09-07',
            'status' => 'paid',
        ])->assertStatus(200);

        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_the_response_never_carries_an_amount_price_or_balance_field(): void
    {
        $code = $this->makeCenterCode($this->teacherA);
        $group = $this->makeCenterGroup($this->teacherA);
        [, $student] = $this->joinedStudent($code);
        $this->addToRoster($group, $student->getKey());

        $this->loginAs($this->teacherA);

        $row = $this->getJson($this->studentsUrl($group))->assertStatus(200)->json('data.students.0');

        $this->assertArrayNotHasKey('amount', $row);
        $this->assertArrayNotHasKey('price', $row);
        $this->assertArrayNotHasKey('balance', $row);
        $this->assertArrayNotHasKey('total', $row);
    }

    // ══ 10 — one student, two teachers, two independent records ══════════

    public function test_a_student_shared_by_two_teachers_keeps_a_separate_record_per_teacher(): void
    {
        $codeA = $this->makeCenterCode($this->teacherA);
        $groupA = $this->makeCenterGroup($this->teacherA);
        $codeB = $this->makeCenterCode($this->teacherB);
        $groupB = $this->makeCenterGroup($this->teacherB);

        [$user, $student] = $this->joinedStudent($codeA);
        $this->loginAs($user);
        $this->postJson('/api/v1/center/join', ['code' => $codeB->code])->assertStatus(200);

        $this->addToRoster($groupA, $student->getKey());
        $this->addToRoster($groupB, $student->getKey());

        $this->loginAs($this->teacherA);
        $this->postJson('/api/v1/center/payments', [
            'group_id' => $groupA->getKey(),
            'student_id' => $student->getKey(),
            'payment_type' => 'month',
            'period' => '2026-09',
            'status' => 'paid',
        ])->assertStatus(200);

        // Teacher B's own record for the SAME student/period is untouched.
        $this->loginAs($this->teacherB);
        $rows = $this->getJson($this->studentsUrl($groupB, ['payment_type' => 'month', 'period' => '2026-09']))
            ->assertStatus(200)->json('data.students');
        $this->assertSame('unpaid', $rows[0]['status']);

        $this->assertSame(1, CenterPaymentRecord::query()->where('teacher_id', $this->teacherA->getKey())->count());
        $this->assertSame(0, CenterPaymentRecord::query()->where('teacher_id', $this->teacherB->getKey())->count());
    }

    // ══ Report ═══════════════════════════════════════════════════════════

    public function test_the_student_report_lists_every_record_for_this_teacher_in_period_order(): void
    {
        $code = $this->makeCenterCode($this->teacherA);
        $group = $this->makeCenterGroup($this->teacherA);
        [, $student] = $this->joinedStudent($code);
        $this->addToRoster($group, $student->getKey());

        $this->loginAs($this->teacherA);
        $this->postJson('/api/v1/center/payments', [
            'group_id' => $group->getKey(), 'student_id' => $student->getKey(),
            'payment_type' => 'month', 'period' => '2026-09', 'status' => 'paid',
        ])->assertStatus(200);
        $this->postJson('/api/v1/center/payments', [
            'group_id' => $group->getKey(), 'student_id' => $student->getKey(),
            'payment_type' => 'session', 'period' => '2026-09-07', 'status' => 'unpaid',
        ])->assertStatus(200);

        $report = $this->getJson("/api/v1/center/payments/students/{$student->getKey()}/report")
            ->assertStatus(200)->json('data');

        $this->assertSame($code->code, $report['student']['center_code']);
        $this->assertCount(2, $report['records']);
    }

    public function test_setting_the_same_period_twice_updates_not_duplicates(): void
    {
        $code = $this->makeCenterCode($this->teacherA);
        $group = $this->makeCenterGroup($this->teacherA);
        [, $student] = $this->joinedStudent($code);
        $this->addToRoster($group, $student->getKey());

        $this->loginAs($this->teacherA);
        $payload = [
            'group_id' => $group->getKey(), 'student_id' => $student->getKey(),
            'payment_type' => 'month', 'period' => '2026-09',
        ];
        $this->postJson('/api/v1/center/payments', $payload + ['status' => 'unpaid'])->assertStatus(200);
        $this->postJson('/api/v1/center/payments', $payload + ['status' => 'paid'])->assertStatus(200);

        $this->assertSame(1, CenterPaymentRecord::query()->count());
        $this->assertSame('paid', CenterPaymentRecord::query()->first()->status);
    }

    // ══ fixtures ═══════════════════════════════════════════════════════

    private function studentsUrl(CenterGroup $group, array $extra = []): string
    {
        $params = array_merge([
            'group_id' => $group->getKey(),
            'payment_type' => 'month',
            'period' => '2026-09',
        ], $extra);

        return '/api/v1/center/payments/students?' . http_build_query($params);
    }

    private function addToRoster(CenterGroup $group, string $studentId): void
    {
        \Illuminate\Support\Facades\DB::table('center_group_students')->insert([
            'id' => (string) Str::uuid7(),
            'center_group_id' => $group->getKey(),
            'student_id' => $studentId,
            'joined_at' => now(),
        ]);
    }

    private function makeCenterCode(User $teacher): CenterCode
    {
        return CenterCode::query()->create([
            'code' => \chr(random_int(65, 90)) . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT),
            'teacher_id' => $teacher->getKey(),
            'is_active' => true,
        ]);
    }

    private function makeCenterGroup(User $teacher): CenterGroup
    {
        return CenterGroup::query()->create([
            'teacher_id' => $teacher->getKey(),
            'center_name' => 'سنتر الاختبار',
            'grade_label' => 'الثالث الثانوي',
            'group_name' => 'مجموعة ' . bin2hex(random_bytes(3)),
            'schedule_text' => 'السبت 6:00 مساءً',
        ]);
    }

    /** @return array{0: User, 1: \App\Models\Student} */
    private function joinedStudent(CenterCode $code, array $profile = []): array
    {
        [$user, $student] = $this->makeStudentWithCode(null, $profile);

        $this->loginAs($user);
        $this->postJson('/api/v1/center/join', ['code' => $code->code])->assertStatus(200);
        $this->post('/api/v1/auth/logout');

        return [$user, $student];
    }
}
