<?php

declare(strict_types=1);

namespace App\Actions\Employee;

use App\Enums\EmployeeAccountType;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeePrivilegeCeiling;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * POST /api/v1/employees — "إدارة الأدمنز"، إنشاء موظف/أمين جديد.
 *
 * Same "one transaction, no orphan row" shape as
 * App\Actions\Teacher\CreateTeacher — a failure on the `employees` insert
 * must not leave a login-capable `users` row with no owner.
 *
 * `$owner` is `$request->user()` as resolved by the controller — ALWAYS the
 * real teacher/admin's own row, whether the caller is that owner themselves
 * or an Amin-swapped session (App\Actions\Auth\LoginUser's identity swap
 * makes the two indistinguishable at this layer, which is the whole point).
 * Genuinely either role (decisions 10.37 teacher-owned / 10.40 admin-owned)
 * — this Action does not care which; the route's `role:admin,instructor`
 * gate is what already decided that. `$actingEmployee` is the acting
 * employee row when the caller is a (non-Amin) Employee with
 * `admin_management.manage`, or null otherwise — it is what
 * `EmployeePrivilegeCeiling` checks the requested grant against.
 */
final class CreateEmployee
{
    /**
     * @param array{name: string, phone: string, password: string} $data
     * @param list<string> $permissions
     */
    public function __invoke(
        User $owner,
        ?Employee $actingEmployee,
        array $data,
        EmployeeAccountType $accountType,
        array $permissions,
    ): Employee {
        EmployeePrivilegeCeiling::assertCanAssign($actingEmployee, $accountType, $permissions);

        $phone = (string) $data['phone'];

        try {
            $employee = DB::transaction(function () use ($owner, $data, $phone, $accountType, $permissions): Employee {
                $user = new User();

                $user->forceFill([
                    'username' => $phone,
                    'email' => $phone . '@sanad-employee.local',
                    'password_hash' => Hash::make((string) $data['password']),
                    'full_name' => (string) $data['name'],
                    'role' => UserRole::Employee,
                    'is_active' => true,
                ])->save();

                return Employee::query()->create([
                    'user_id' => $user->getKey(),
                    'owner_id' => $owner->getKey(),
                    'account_type' => $accountType,
                    // `null` for an Amin — EmployeePermissions::grants()
                    // never reads this column for one; storing an empty
                    // array instead would be an equally correct "no extra
                    // keys" value but a less honest one, since an Amin's
                    // access is never actually a list.
                    'permissions' => $accountType === EmployeeAccountType::Amin ? null : $permissions,
                    'is_active' => true,
                ]);
            });
        } catch (Throwable $e) {
            SecurityLog::error('Employee creation failed and was rolled back', [
                'event' => 'employee.create_failure',
                'owner_id' => $owner->getKey(),
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        SecurityLog::warning('Employee account created', [
            'event' => 'employee.created',
            'owner_id' => $owner->getKey(),
            'owner_role' => $owner->role->value,
            'target_user_id' => $employee->getAttribute('user_id'),
            'account_type' => $accountType->value,
        ]);

        return $employee;
    }
}
