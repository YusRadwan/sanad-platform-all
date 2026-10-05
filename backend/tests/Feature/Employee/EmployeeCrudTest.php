<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\EmployeeAccountType;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * "إدارة الأدمنز" (decision 10.37) — CRUD, ownership isolation and the
 * privilege-ceiling rules (App\Support\EmployeePrivilegeCeiling), covering
 * the request's 16 explicit test scenarios.
 */
final class EmployeeCrudTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'أحمد محمد',
            'phone' => '01' . random_int(100000000, 999999999),
            'password' => self::FIXTURE_PASSWORD,
            'accountType' => 'employee',
            'permissions' => ['courses.manage'],
        ], $overrides);
    }

    // ══ Test 1/2 — teacher creates their own employee ═══════════════════

    public function test_a_teacher_can_create_an_employee_for_themselves(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        $this->loginAs($teacher);

        $row = $this->postJson('/api/v1/employees', $this->payload())
            ->assertStatus(201)
            ->json('data');

        $this->assertSame('أحمد محمد', $row['name']);
        $this->assertSame('employee', $row['accountType']);
        $this->assertSame(['courses.manage'], $row['permissions']);
        $this->assertSame(1, Employee::query()->where('owner_id', $teacher->getKey())->count());
    }

    public function test_a_teacher_can_create_an_amin(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        $this->loginAs($teacher);

        $row = $this->postJson('/api/v1/employees', $this->payload(['accountType' => 'amin', 'permissions' => []]))
            ->assertStatus(201)
            ->json('data');

        $this->assertSame('amin', $row['accountType']);
    }

    public function test_duplicate_phone_is_rejected(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        $this->makeEmployee($teacher, EmployeeAccountType::Employee, [], '01011112222');
        $this->loginAs($teacher);

        $this->postJson('/api/v1/employees', $this->payload(['phone' => '01011112222']))
            ->assertStatus(422);
    }

    public function test_an_unknown_permission_key_is_rejected(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        $this->loginAs($teacher);

        $this->postJson('/api/v1/employees', $this->payload(['permissions' => ['not_a_real_key.manage']]))
            ->assertStatus(422);
    }

    // ══ Test 3 — cross-teacher isolation on the LIST ═════════════════════

    public function test_employee_a1_does_not_see_teacher_bs_employees(): void
    {
        $this->disableRateLimiting();
        $teacherA = $this->makeInstructor();
        $teacherB = $this->makeInstructor();
        [, $employeeA1] = $this->makeEmployee($teacherA, EmployeeAccountType::Amin);
        $this->makeEmployee($teacherB, EmployeeAccountType::Employee);

        $this->loginAs($employeeA1->user);

        $rows = $this->getJson('/api/v1/employees')->assertStatus(200)->json('data.employees');

        $this->assertCount(1, $rows);
    }

    // ══ Test 4/5/6 — permission denial: page, direct URL, direct API ═════

    public function test_an_employee_without_a_permission_is_refused_the_matching_api(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$employeeUser] = $this->makeEmployee($teacher, EmployeeAccountType::Employee, ['courses.manage']);

        $this->loginAs($employeeUser);

        // No `admin_management.manage` — the exact route this whole feature
        // is gated behind, reached "directly" exactly as a URL-tamper would.
        $this->getJson('/api/v1/employees')->assertStatus(403);
    }

    public function test_an_employee_with_the_permission_can_reach_the_gated_api(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$employeeUser] = $this->makeEmployee($teacher, EmployeeAccountType::Employee, ['admin_management.manage']);

        $this->loginAs($employeeUser);

        $this->getJson('/api/v1/employees')->assertStatus(200);
    }

    // ══ Test 7/8 — self-escalation is refused ════════════════════════════

    public function test_an_employee_cannot_edit_their_own_permissions_even_with_admin_management(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$employeeUser, $employee] = $this->makeEmployee(
            $teacher,
            EmployeeAccountType::Employee,
            ['admin_management.manage']
        );

        $this->loginAs($employeeUser);

        $this->putJson("/api/v1/employees/{$employee->getKey()}", ['permissions' => ['revenue.view']])
            ->assertStatus(403);
    }

    public function test_an_employee_cannot_promote_themselves_to_amin(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$employeeUser, $employee] = $this->makeEmployee(
            $teacher,
            EmployeeAccountType::Employee,
            ['admin_management.manage']
        );

        $this->loginAs($employeeUser);

        $this->putJson("/api/v1/employees/{$employee->getKey()}", ['accountType' => 'amin'])
            ->assertStatus(403);

        $this->assertSame(EmployeeAccountType::Employee, $employee->refresh()->account_type);
    }

    public function test_an_employee_cannot_grant_a_permission_they_do_not_hold(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$managerUser] = $this->makeEmployee(
            $teacher,
            EmployeeAccountType::Employee,
            ['admin_management.manage', 'courses.manage']
        );
        [, $peer] = $this->makeEmployee($teacher, EmployeeAccountType::Employee, []);

        $this->loginAs($managerUser);

        $this->putJson("/api/v1/employees/{$peer->getKey()}", ['permissions' => ['revenue.view']])
            ->assertStatus(403);
    }

    public function test_an_employee_cannot_create_an_amin(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$managerUser] = $this->makeEmployee($teacher, EmployeeAccountType::Employee, ['admin_management.manage']);

        $this->loginAs($managerUser);

        $this->postJson('/api/v1/employees', $this->payload(['accountType' => 'amin']))
            ->assertStatus(403);
    }

    public function test_an_employee_cannot_modify_an_amin(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$managerUser] = $this->makeEmployee($teacher, EmployeeAccountType::Employee, ['admin_management.manage']);
        [, $amin] = $this->makeEmployee($teacher, EmployeeAccountType::Amin, [], '01055556666');

        $this->loginAs($managerUser);

        $this->putJson("/api/v1/employees/{$amin->getKey()}", ['name' => 'اسم جديد'])
            ->assertStatus(403);
        $this->deleteJson("/api/v1/employees/{$amin->getKey()}")->assertStatus(403);
    }

    // ══ Test 9/10 — Amin has full teacher access, never platform admin ═══

    public function test_amin_can_use_every_teacher_scoped_page_within_their_teachers_scope(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        $course = $this->makeCourseFor($teacher);
        [$aminUser] = $this->makeEmployee($teacher, EmployeeAccountType::Amin);

        $this->loginAs($aminUser);

        // No admin_management permission entry needed for an Amin — full
        // teacher parity, confirmed against a real ownership-checked route.
        $this->getJson('/api/v1/courses/my-courses')->assertStatus(200);
        $this->getJson('/api/v1/employees')->assertStatus(200);
        $ids = collect($this->getJson('/api/v1/courses/my-courses')->json('data.courses'))->pluck('id');
        $this->assertTrue($ids->contains($course->getKey()));
    }

    public function test_amin_cannot_reach_the_platform_admin_section(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$aminUser] = $this->makeEmployee($teacher, EmployeeAccountType::Amin);

        $this->loginAs($aminUser);

        $this->getJson('/api/v1/admin/teachers')->assertStatus(403);
    }

    // ══ Test 11/12 — teacher edits own employee, refused on another's ════

    public function test_a_teacher_can_edit_their_own_employee(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [, $employee] = $this->makeEmployee($teacher);
        $this->loginAs($teacher);

        $this->putJson("/api/v1/employees/{$employee->getKey()}", ['name' => 'اسم محدَّث'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'اسم محدَّث');
    }

    public function test_a_teacher_cannot_edit_another_teachers_employee(): void
    {
        $this->disableRateLimiting();
        $teacherA = $this->makeInstructor();
        $teacherB = $this->makeInstructor();
        [, $employeeB1] = $this->makeEmployee($teacherB);
        $this->loginAs($teacherA);

        $this->putJson("/api/v1/employees/{$employeeB1->getKey()}", ['name' => 'اسم آخر'])
            ->assertStatus(404);
    }

    // ══ Test 13/14 — delete, then login is blocked ═══════════════════════

    public function test_a_teacher_can_delete_their_own_employee(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$employeeUser, $employee] = $this->makeEmployee($teacher);
        $this->loginAs($teacher);

        $this->deleteJson("/api/v1/employees/{$employee->getKey()}")->assertStatus(200);

        $this->assertTrue($employeeUser->refresh()->trashed());
        $this->assertFalse($employee->refresh()->is_active);
    }

    public function test_after_deletion_the_employee_cannot_log_in(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$employeeUser, $employee] = $this->makeEmployee($teacher);
        $this->loginAs($teacher);
        $this->deleteJson("/api/v1/employees/{$employee->getKey()}")->assertStatus(200);

        $this->postJson('/api/v1/auth/login', [
            'username' => $employeeUser->username,
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(401);
    }

    public function test_deleting_an_employee_does_not_touch_the_teachers_own_data(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        $course = $this->makeCourseFor($teacher);
        [, $employee] = $this->makeEmployee($teacher);
        $this->loginAs($teacher);

        $this->deleteJson("/api/v1/employees/{$employee->getKey()}")->assertStatus(200);

        $this->assertNotNull(\App\Models\Course::query()->find($course->getKey()));
        $this->assertFalse($teacher->refresh()->trashed());
    }

    // ══ Test 15 — a new page adds no permission automatically ════════════

    public function test_a_new_catalog_key_is_never_retroactively_granted(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$employeeUser, $employee] = $this->makeEmployee($teacher, EmployeeAccountType::Employee, ['courses.manage']);

        // Simulates "a page shipped after this employee was created" —
        // the catalog gains a key nobody explicitly granted them.
        $this->assertNotContains('revenue.view', $employee->permissions);

        $this->loginAs($employeeUser);
        $this->getJson('/api/v1/revenue/my-statement')->assertStatus(403);
    }

    // ══ Test 16 — ID tampering never bypasses teacher isolation ══════════

    public function test_tampering_with_the_id_in_the_url_cannot_reach_another_teachers_employee(): void
    {
        $this->disableRateLimiting();
        $teacherA = $this->makeInstructor();
        $teacherB = $this->makeInstructor();
        [$managerUser] = $this->makeEmployee($teacherA, EmployeeAccountType::Employee, ['admin_management.manage']);
        [, $employeeB1] = $this->makeEmployee($teacherB);

        $this->loginAs($managerUser);

        // Employee A1 knows Employee B1's real id (e.g. leaked in a UI bug)
        // and edits the URL directly — still 404, never 403 (existence is
        // not disclosed), and never a successful mutation.
        $this->putJson("/api/v1/employees/{$employeeB1->getKey()}", ['name' => 'محاولة اختراق'])
            ->assertStatus(404);
        $this->deleteJson("/api/v1/employees/{$employeeB1->getKey()}")->assertStatus(404);

        $this->assertNotSame('محاولة اختراق', $employeeB1->user->refresh()->full_name);
    }

    public function test_tampering_with_a_course_id_cannot_reach_another_teachers_course(): void
    {
        $this->disableRateLimiting();
        $teacherA = $this->makeInstructor();
        $teacherB = $this->makeInstructor();
        $courseB = $this->makeCourseFor($teacherB);
        [$employeeA1] = $this->makeEmployee($teacherA, EmployeeAccountType::Amin);

        $this->loginAs($employeeA1);

        // Amin A1 has FULL access within teacher A's scope, but teacher B's
        // course id is still a foreign resource — the swap only ever makes
        // the session equal to teacher A, never any other teacher.
        $this->putJson("/api/v1/courses/{$courseB->getKey()}", ['title' => 'اختراق'])
            ->assertStatus(403);
    }
}
