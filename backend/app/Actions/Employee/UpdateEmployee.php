<?php

declare(strict_types=1);

namespace App\Actions\Employee;

use App\Enums\EmployeeAccountType;
use App\Models\Employee;
use App\Support\EmployeePrivilegeCeiling;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * PUT /api/v1/employees/{id} — "إدارة الأدمنز"، تعديل موظف/أمين.
 *
 * `has()` BEFORE reading any field — presence, not truthiness, exactly the
 * discipline this codebase already applies to `loginBlocked`/`centerEnabled`
 * on Teacher: an absent key must leave the column untouched, never reset to
 * its zero value on an unrelated save.
 *
 * أمين → موظف يترك `permissions` كما أُرسِلت (فارغة إن لم تُرسَل، مطابقةً
 * لطلب المستخدم أن يظهر نظام اختيار الصلاحيات عند هذا التحويل)؛ موظف → أمين
 * يفرغ `permissions` (`null`)، مطابقةً لقاعدة "لا تُقرَأ أبداً لأمين".
 */
final class UpdateEmployee
{
    /** @param array<string, mixed> $data */
    public function __invoke(
        Employee $target,
        ?Employee $actingEmployee,
        array $data,
        bool $hasName,
        bool $hasPhone,
        bool $hasPassword,
        ?EmployeeAccountType $accountType,
        ?array $permissions,
        bool $hasIsActive,
    ): Employee {
        // The resulting state, not just the changed fields — assertCanAssign
        // needs to judge what the row will BECOME.
        $resultingType = $accountType ?? $target->account_type;
        $resultingPermissions = $permissions ?? ($target->permissions ?? []);

        // ALWAYS: even a plain profile edit (name/phone/password/isActive)
        // on ANOTHER employee is gated by the self/amin rules.
        EmployeePrivilegeCeiling::assertCanModify($actingEmployee, $target);

        // ADDITIONALLY, only when the resulting type/permissions actually
        // change: the grant-ceiling check.
        if ($accountType !== null || $permissions !== null) {
            EmployeePrivilegeCeiling::assertCanAssign($actingEmployee, $resultingType, $resultingPermissions);
        }

        DB::transaction(function () use (
            $target,
            $data,
            $hasName,
            $hasPhone,
            $hasPassword,
            $accountType,
            $permissions,
            $hasIsActive,
        ): void {
            $user = $target->user;

            if ($hasName) {
                $user->forceFill(['full_name' => (string) $data['name']])->save();
            }
            if ($hasPhone) {
                $phone = (string) $data['phone'];
                $user->forceFill([
                    'username' => $phone,
                    'email' => $phone . '@sanad-employee.local',
                ])->save();
            }
            if ($hasPassword) {
                $user->forceFill(['password_hash' => Hash::make((string) $data['password'])])->save();
            }

            $fields = [];

            if ($accountType !== null) {
                $fields['account_type'] = $accountType;
                // موظف → أمين: لا قائمة صلاحيات لأمين إطلاقاً.
                if ($accountType === EmployeeAccountType::Amin) {
                    $fields['permissions'] = null;
                }
            }
            if ($permissions !== null && (($accountType ?? $target->account_type) !== EmployeeAccountType::Amin)) {
                $fields['permissions'] = $permissions;
            }
            if ($hasIsActive) {
                $fields['is_active'] = (bool) $data['isActive'];
            }

            if ($fields !== []) {
                $target->update($fields);
            }
        });

        SecurityLog::warning('Employee account updated', [
            'event' => 'employee.updated',
            'owner_id' => $target->getAttribute('owner_id'),
            'target_user_id' => $target->getAttribute('user_id'),
            'changed_fields' => array_keys(array_filter([
                'name' => $hasName,
                'phone' => $hasPhone,
                'password' => $hasPassword,
                'accountType' => $accountType !== null,
                'permissions' => $permissions !== null,
                'isActive' => $hasIsActive,
            ])),
        ]);

        return $target->refresh();
    }
}
