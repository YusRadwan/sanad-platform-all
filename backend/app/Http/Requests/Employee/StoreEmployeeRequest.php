<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\EmployeeAccountType;
use App\Http\Requests\ApiFormRequest;
use App\Rules\PasswordPolicy;
use App\Support\EmployeePermissions;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/employees — "إدارة الأدمنز"، إضافة موظف/أمين جديد.
 *
 * `phone` هو اسم المستخدم/Login Identifier نفسه — نفس نطاق التفرّد المستخدَم
 * في `StoreTeacherRequest` (`users.username`، مقصور على الصفوف الحيّة).
 * `permissions.*` يُتحقَّق ضد `EmployeePermissions::keys()` — الكتالوج
 * الوحيد، فلا يمكن تخزين مفتاح صلاحية غير معروف مهما كان الطلب.
 */
final class StoreEmployeeRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:190'],
            'phone' => [
                'required',
                'digits:11',
                Rule::unique('users', 'username')->whereNull('deleted_at'),
            ],
            'password' => PasswordPolicy::rules(),
            'accountType' => ['required', 'string', Rule::in(EmployeeAccountType::values())],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(EmployeePermissions::keys())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'phone.unique' => 'رقم الهاتف مستخدم بالفعل',
            'phone.digits' => 'رقم الهاتف يجب أن يتكون من 11 رقماً',
        ];
    }

    public function accountType(): EmployeeAccountType
    {
        return EmployeeAccountType::from((string) $this->validated('accountType'));
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $value = $this->validated('permissions');

        return is_array($value) ? array_values(array_unique($value)) : [];
    }
}
