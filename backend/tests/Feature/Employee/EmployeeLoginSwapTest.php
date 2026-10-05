<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\EmployeeAccountType;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * "إدارة الأدمنز" (decision 10.37) — the identity-swap mechanism itself:
 * an employee logs in with THEIR OWN credentials, but the session ends up
 * scoped to the OWNING TEACHER for every ownership check, while `/auth/me`
 * still discloses who actually authenticated.
 */
final class EmployeeLoginSwapTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    public function test_an_employee_logs_in_with_their_own_credentials_and_the_session_becomes_the_teacher(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$employeeUser] = $this->makeEmployee($teacher, EmployeeAccountType::Employee, ['courses.manage']);

        $this->loginAs($employeeUser);

        $me = $this->getJson('/api/v1/auth/me')->assertStatus(200)->json('data.user');

        // The session-user payload is the TEACHER's own identity — the
        // authorization surface this swap exists to make work for free.
        $this->assertSame((string) $teacher->getKey(), $me['id']);
        $this->assertSame('instructor', $me['role']);

        // But the ACTING employee is disclosed too, for the UI.
        $this->assertSame((string) $employeeUser->getKey(), $me['actingEmployee']['id']);
        $this->assertSame(['courses.manage'], $me['employeePermissions']);
    }

    public function test_an_amin_session_reports_all_permissions(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$aminUser] = $this->makeEmployee($teacher, EmployeeAccountType::Amin);

        $this->loginAs($aminUser);

        $me = $this->getJson('/api/v1/auth/me')->assertStatus(200)->json('data.user');

        $this->assertSame('all', $me['employeePermissions']);
    }

    public function test_an_ordinary_teacher_session_has_no_acting_employee(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();

        $this->loginAs($teacher);

        $me = $this->getJson('/api/v1/auth/me')->assertStatus(200)->json('data.user');

        $this->assertNull($me['actingEmployee']);
        $this->assertNull($me['employeePermissions']);
    }

    public function test_a_disabled_employee_cannot_log_in(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$employeeUser, $employee] = $this->makeEmployee($teacher);
        $employee->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'username' => $employeeUser->username,
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(401);
    }

    /**
     * Decision 10.37's non-negotiable requirement: disabling the employee
     * MID-SESSION must terminate their access on their very next request —
     * not merely block a future login. This is the exact scenario
     * `EnsureUserIsActive::actingEmployeeStillValid()` exists for; the
     * ordinary `$user->trashed()||!$user->is_active` check in that same
     * middleware can NEVER see this, because `$user` there is permanently
     * the teacher after the swap.
     */
    public function test_deactivating_the_employee_mid_session_ends_access_on_the_next_request(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$employeeUser, $employee] = $this->makeEmployee($teacher, EmployeeAccountType::Amin);

        $this->loginAs($employeeUser);
        $this->getJson('/api/v1/auth/me')->assertStatus(200);

        $employee->update(['is_active' => false]);

        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_soft_deleting_the_employee_mid_session_ends_access_on_the_next_request(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$employeeUser] = $this->makeEmployee($teacher, EmployeeAccountType::Amin);

        $this->loginAs($employeeUser);
        $this->getJson('/api/v1/auth/me')->assertStatus(200);

        $employeeUser->delete();

        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    /** After a delete, the employee cannot start a NEW session either. */
    public function test_a_deleted_employee_cannot_log_in_again(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$employeeUser] = $this->makeEmployee($teacher);

        $employeeUser->delete();

        $this->postJson('/api/v1/auth/login', [
            'username' => $employeeUser->username,
            'password' => self::FIXTURE_PASSWORD,
        ])->assertStatus(401);
    }
}
