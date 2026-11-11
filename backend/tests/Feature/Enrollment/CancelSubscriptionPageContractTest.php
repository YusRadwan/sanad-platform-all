<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\EmployeeAccountType;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Support\EmployeePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * The admin «إلغاء اشتراك» page (frontend/src/components/Admin/
 * CancelSubscription.tsx) adds NO route of its own. It calls:
 *
 *   GET /api/v1/admin/subscriptions     (student_code | phone)
 *   PUT /api/v1/admin/enrollments/toggle (isActive=false)
 *
 * Both are `role:admin` + `employee.permission:admin_subscriptions.manage`.
 * These tests pin the two facts the page relies on:
 *
 *  1. AUTHORIZATION IS ENFORCED SERVER-SIDE, not just by hiding the sidebar
 *     item: an admin-owned Employee WITH the key reaches both routes; one
 *     WITHOUT it is 403 on both, and the 403 on the toggle writes nothing.
 *
 *  2. THE PHONE SEARCH IS ON students.phone ONLY. The 10-digit fragment the
 *     page sends ("1xxxxxxxxx") matches every stored spelling of the student's
 *     own number, and never matches a student via parent_phone.
 */
final class CancelSubscriptionPageContractTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    /** @return array{0: \App\Models\User, 1: \App\Models\Course} */
    private function enrolledStudent(): array
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        [$student] = $this->makeStudentWithCode('STU510078', ['phone' => '01012345678']);
        $this->makeSubscription($student, $course);

        return [$student, $course];
    }

    private function cancelPayload($student, $course): array
    {
        return [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) $course->getKey(),
            'isActive' => false,
        ];
    }

    public function test_an_admin_employee_with_the_subscriptions_key_can_search_and_cancel(): void
    {
        $this->disableRateLimiting();
        [$student, $course] = $this->enrolledStudent();
        $admin = $this->makeAdmin();
        [$employeeUser] = $this->makeEmployee(
            $admin,
            EmployeeAccountType::Employee,
            [EmployeePermissions::ADMIN_SUBSCRIPTIONS_MANAGE],
        );

        $this->loginAs($employeeUser);

        $this->getJson('/api/v1/admin/subscriptions?student_code=510078')
            ->assertStatus(200)
            ->assertJsonPath('data.subscriptions.0.id', (string) $student->getKey());

        $this->putJson('/api/v1/admin/enrollments/toggle', $this->cancelPayload($student, $course))
            ->assertStatus(200)
            ->assertJsonPath('data.enrollment.status', 'cancelled');
    }

    public function test_an_admin_employee_without_the_key_is_refused_both_routes_and_nothing_is_written(): void
    {
        $this->disableRateLimiting();
        [$student, $course] = $this->enrolledStudent();
        $admin = $this->makeAdmin();
        // Holds a DIFFERENT, unrelated key — the closest a real limited
        // employee gets to "almost allowed".
        [$employeeUser] = $this->makeEmployee(
            $admin,
            EmployeeAccountType::Employee,
            [EmployeePermissions::ADMIN_STUDENT_CODE_DETAILS_VIEW],
        );

        $this->loginAs($employeeUser);

        $this->getJson('/api/v1/admin/subscriptions?student_code=510078')->assertStatus(403);
        $this->putJson('/api/v1/admin/enrollments/toggle', $this->cancelPayload($student, $course))
            ->assertStatus(403);

        self::assertSame(SubscriptionStatus::Active, Subscription::query()->sole()->status);
    }

    public function test_an_admin_owned_amin_can_reach_both_routes(): void
    {
        $this->disableRateLimiting();
        [$student, $course] = $this->enrolledStudent();
        $admin = $this->makeAdmin();
        [$aminUser] = $this->makeEmployee($admin, EmployeeAccountType::Amin);

        $this->loginAs($aminUser);

        $this->getJson('/api/v1/admin/subscriptions?phone=1012345678')->assertStatus(200);
        $this->putJson('/api/v1/admin/enrollments/toggle', $this->cancelPayload($student, $course))
            ->assertStatus(200);
    }

    public function test_a_teacher_owned_amin_is_refused_both_routes(): void
    {
        $this->disableRateLimiting();
        [$student, $course] = $this->enrolledStudent();
        [$instructor] = $this->makeTeacherWithProfile();
        [$aminUser] = $this->makeEmployee($instructor, EmployeeAccountType::Amin);

        $this->loginAs($aminUser);

        $this->getJson('/api/v1/admin/subscriptions')->assertStatus(403);
        $this->putJson('/api/v1/admin/enrollments/toggle', $this->cancelPayload($student, $course))
            ->assertStatus(403);

        self::assertSame(SubscriptionStatus::Active, Subscription::query()->sole()->status);
    }

    public function test_the_ten_digit_phone_fragment_matches_every_stored_spelling_of_the_students_own_phone(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);

        $local = $this->makeStudentWithCode('STU100001', ['phone' => '01012345678', 'parent_phone' => '01200000001'])[0];
        $intl = $this->makeStudentWithCode('STU100002', ['phone' => '+201012345678', 'parent_phone' => '01200000002'])[0];
        $zeros = $this->makeStudentWithCode('STU100003', ['phone' => '00201012345678', 'parent_phone' => '01200000003'])[0];
        // Same number as the PARENT's phone only — must never be returned.
        $parentOnly = $this->makeStudentWithCode('STU100004', ['phone' => '01199999999', 'parent_phone' => '01012345678'])[0];

        foreach ([$local, $intl, $zeros, $parentOnly] as $s) {
            $this->makeSubscription($s, $course);
        }

        $this->loginAsAdmin();

        $ids = array_column(
            $this->getJson('/api/v1/admin/subscriptions?phone=1012345678')
                ->assertStatus(200)
                ->json('data.subscriptions'),
            'id'
        );
        sort($ids);

        $expected = [(string) $local->getKey(), (string) $intl->getKey(), (string) $zeros->getKey()];
        sort($expected);

        self::assertSame($expected, $ids);
        self::assertNotContains((string) $parentOnly->getKey(), $ids);
    }
}
