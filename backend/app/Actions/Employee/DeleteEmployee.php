<?php

declare(strict_types=1);

namespace App\Actions\Employee;

use App\Models\Employee;
use App\Support\EmployeePrivilegeCeiling;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * DELETE /api/v1/employees/{id} — "إدارة الأدمنز"، حذف موظف/أمين.
 *
 * SOFT-DELETE the employee's OWN `users` row — the exact same convention
 * already used for a teacher/student ("DELETE /admin/students/{id} writes
 * only users.deleted_at"). `employees.is_active` is ALSO flipped false as
 * belt-and-braces defence-in-depth: App\Http\Middleware\EnsureUserIsActive's
 * per-request revalidation checks BOTH the `users.deleted_at`/`is_active`
 * AND `employees.is_active`, so either write alone would already be enough
 * to lock the account out on its very next request — this sets both so
 * neither is a single point of failure.
 *
 * NOTHING ELSE IS TOUCHED. No teacher data, no student data, no course/group/
 * center row — the employee's `users`+`employees` rows are the entirety of
 * what this action can reach in the first place (Employee has no relation to
 * anything else), so "لا تحذف بيانات المدرس أو الطلاب" holds by construction,
 * not by care.
 */
final class DeleteEmployee
{
    public function __invoke(Employee $target, ?Employee $actingEmployee): void
    {
        EmployeePrivilegeCeiling::assertCanModify($actingEmployee, $target);

        DB::transaction(function () use ($target): void {
            $target->update(['is_active' => false]);
            $target->user?->delete();
        });

        SecurityLog::warning('Employee account deleted', [
            'event' => 'employee.deleted',
            'owner_id' => $target->getAttribute('owner_id'),
            'target_user_id' => $target->getAttribute('user_id'),
        ]);
    }
}
