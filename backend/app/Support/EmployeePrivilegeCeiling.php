<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EmployeeAccountType;
use App\Exceptions\ForbiddenException;
use App\Models\Employee;

/**
 * "إدارة الأدمنز" (decision 10.37) — the self-escalation guards, all in one
 * place so `CreateEmployee`/`UpdateEmployee`/`DeleteEmployee` can never
 * individually forget one. Every rule here is enforced ONLY against an
 * ACTING EMPLOYEE (a real teacher or an Amin session passes both methods
 * unconditionally — an Amin IS "full teacher permissions", so it is subject
 * to none of these ceilings, exactly like the real teacher).
 *
 * These are Backend rules, not Frontend hints — this class is the ONLY place
 * "لا يستطيع رفع صلاحياته بنفسه" is actually enforced; nothing upstream of it
 * (the Form Requests, the routes) can substitute for it.
 */
final class EmployeePrivilegeCeiling
{
    public const CANNOT_CREATE_AMIN = 'لا يمكن لموظف إنشاء أو تعديل حساب أمين';

    public const CANNOT_GRANT_UNHELD_PERMISSION = 'لا يمكنك منح صلاحية لا تملكها';

    public const CANNOT_MODIFY_SELF = 'لا يمكنك تعديل بيانات أو صلاحيات حسابك الخاص';

    public const CANNOT_MODIFY_AMIN = 'لا يمكن لموظف تعديل أو حذف حساب أمين';

    /**
     * Guards CREATING a new employee/amin row, and CHANGING an existing
     * one's `accountType`/`permissions`. `$targetType`/`$targetPermissions`
     * are the RESULTING state after the write, not just the changed fields.
     *
     * @param list<string> $targetPermissions
     */
    public static function assertCanAssign(
        ?Employee $actingEmployee,
        EmployeeAccountType $targetType,
        array $targetPermissions,
    ): void {
        if ($actingEmployee === null || $actingEmployee->account_type === EmployeeAccountType::Amin) {
            return;
        }

        if ($targetType === EmployeeAccountType::Amin) {
            throw new ForbiddenException(self::CANNOT_CREATE_AMIN);
        }

        // Privilege ceiling: an employee may only grant permissions THEY
        // themselves already hold — "لا يستطيع تحويل نفسه إلى أمين" and "لا
        // يستطيع إنشاء موظف أعلى منه" are both special cases of this one
        // general rule.
        $granted = $actingEmployee->permissions ?? [];
        $notHeld = array_diff($targetPermissions, $granted);

        if ($notHeld !== []) {
            throw new ForbiddenException(self::CANNOT_GRANT_UNHELD_PERMISSION);
        }
    }

    /** Guards UPDATING or DELETING an existing employee row at all. */
    public static function assertCanModify(?Employee $actingEmployee, Employee $target): void
    {
        if ($actingEmployee === null || $actingEmployee->account_type === EmployeeAccountType::Amin) {
            return;
        }

        if ((string) $target->getAttribute('user_id') === (string) $actingEmployee->getAttribute('user_id')) {
            throw new ForbiddenException(self::CANNOT_MODIFY_SELF);
        }

        if ($target->account_type === EmployeeAccountType::Amin) {
            throw new ForbiddenException(self::CANNOT_MODIFY_AMIN);
        }
    }

    /** Static-only. */
    private function __construct()
    {
    }
}
