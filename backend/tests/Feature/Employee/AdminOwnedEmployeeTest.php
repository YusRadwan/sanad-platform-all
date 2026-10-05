<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\EmployeeAccountType;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * "إدارة الأدمنز" — قسم الأدمن (decision 10.40). موظفو الأدمن منفصلون
 * كلياً عن موظفي المدرّسين (القرار 10.37) — نفس الآلية (انتحال الهوية عبر
 * `App\Actions\Auth\LoginUser`، نفس مسارات `/employees`)، لكن `owner_id`
 * يشير هنا إلى أدمن، والعزل بين كل أدمن وآخر.
 */
final class AdminOwnedEmployeeTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'موظف أدمن',
            'phone' => '01' . random_int(100000000, 999999999),
            'password' => self::FIXTURE_PASSWORD,
            'accountType' => 'employee',
            'permissions' => [],
        ], $overrides);
    }

    public function test_an_admin_can_create_an_employee_for_themselves(): void
    {
        $this->disableRateLimiting();
        $admin = $this->makeAdmin();
        $this->loginAs($admin);

        $row = $this->postJson('/api/v1/employees', $this->payload())
            ->assertStatus(201)
            ->json('data');

        $this->assertSame('employee', $row['accountType']);
        $this->assertSame(1, Employee::query()->where('owner_id', $admin->getKey())->count());
    }

    /** The isolation boundary this whole feature exists for. */
    public function test_admin_a_does_not_see_admin_bs_employees(): void
    {
        $this->disableRateLimiting();
        $adminA = $this->makeAdmin();
        $adminB = $this->makeAdmin();
        $this->makeEmployee($adminA);
        $this->makeEmployee($adminB);
        $this->makeEmployee($adminB);

        $this->loginAs($adminA);

        $rows = $this->getJson('/api/v1/employees')->assertStatus(200)->json('data.employees');

        $this->assertCount(1, $rows);
    }

    public function test_an_admin_owned_amin_inherits_full_super_admin_authority(): void
    {
        $this->disableRateLimiting();
        $admin = $this->makeAdmin();
        [$aminUser] = $this->makeEmployee($admin, EmployeeAccountType::Amin);

        $this->loginAs($aminUser);

        $me = $this->getJson('/api/v1/auth/me')->assertStatus(200)->json('data.user');
        $this->assertSame('admin', $me['role']);
        $this->assertSame('all', $me['employeePermissions']);

        // Genuine platform-admin reach — not merely "full teacher scope"
        // the way a teacher-owned Amin is limited to (decision 10.37).
        $this->getJson('/api/v1/admin/teachers')->assertStatus(200);
    }

    public function test_an_admin_owned_limited_employee_is_refused_pages_they_lack(): void
    {
        $this->disableRateLimiting();
        $admin = $this->makeAdmin();
        [$employeeUser] = $this->makeEmployee($admin, EmployeeAccountType::Employee, []);

        $this->loginAs($employeeUser);

        // No admin_management.manage granted — refused the very feature
        // that manages employees, exactly like the teacher-owned case.
        $this->getJson('/api/v1/employees')->assertStatus(403);
    }

    public function test_an_admin_owned_employee_cannot_promote_themselves_to_amin(): void
    {
        $this->disableRateLimiting();
        $admin = $this->makeAdmin();
        [$employeeUser, $employee] = $this->makeEmployee(
            $admin,
            EmployeeAccountType::Employee,
            ['admin_management.manage']
        );

        $this->loginAs($employeeUser);

        $this->putJson("/api/v1/employees/{$employee->getKey()}", ['accountType' => 'amin'])
            ->assertStatus(403);
    }

    /** Cross-owner-KIND isolation: an admin-owned employee is not a teacher-owned one, and vice versa. */
    public function test_an_admin_owned_employee_cannot_reach_a_teacher_owned_employee(): void
    {
        $this->disableRateLimiting();
        $admin = $this->makeAdmin();
        $teacher = $this->makeInstructor();
        [$adminEmployeeUser] = $this->makeEmployee($admin, EmployeeAccountType::Employee, ['admin_management.manage']);
        [, $teacherEmployee] = $this->makeEmployee($teacher);

        $this->loginAs($adminEmployeeUser);

        $this->putJson("/api/v1/employees/{$teacherEmployee->getKey()}", ['name' => 'اختراق'])
            ->assertStatus(404);
    }

    public function test_a_teacher_owned_amin_never_gains_platform_admin_access_even_though_an_admin_owned_amin_does(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        [$aminUser] = $this->makeEmployee($teacher, EmployeeAccountType::Amin);

        $this->loginAs($aminUser);

        $me = $this->getJson('/api/v1/auth/me')->assertStatus(200)->json('data.user');
        $this->assertSame('instructor', $me['role']);

        $this->getJson('/api/v1/admin/teachers')->assertStatus(403);
    }

    public function test_deactivating_an_admin_owned_employee_mid_session_ends_access(): void
    {
        $this->disableRateLimiting();
        $admin = $this->makeAdmin();
        [$employeeUser, $employee] = $this->makeEmployee($admin, EmployeeAccountType::Amin);

        $this->loginAs($employeeUser);
        $this->getJson('/api/v1/auth/me')->assertStatus(200);

        $employee->update(['is_active' => false]);

        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }
}
