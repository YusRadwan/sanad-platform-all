<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\EmployeeAccountType;
use App\Exceptions\NotFoundException;
use App\Http\Requests\ApiFormRequest;
use App\Models\Employee;
use App\Models\User;
use App\Rules\PasswordPolicy;
use App\Support\EmployeePermissions;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/employees/{id} — "إدارة الأدمنز"، تعديل موظف/أمين.
 *
 * `employee()` هو نفس نمط `ManageCenterGroupRequest::group()` — يحصر
 * الاستعلام بـ`owner_id = <صاحب الجلسة>` (مدرّس أو أدمن، decisions 10.37 +
 * 10.40) فيصبح موظف مالكٍ آخر ببساطة "غير موجود" (404، لا 403)، لا يُوجَد
 * رقم صفّ يُسرَّب.
 */
final class UpdateEmployeeRequest extends ApiFormRequest
{
    private ?Employee $employee = null;

    public function employee(): Employee
    {
        if ($this->employee !== null) {
            return $this->employee;
        }

        /** @var User $owner */
        $owner = $this->user();

        return $this->employee = Employee::query()
            ->ownedBy($owner)
            ->whereKey((string) $this->route('id'))
            ->first()
            ?? throw new NotFoundException('الموظف غير موجود');
    }

    public function authorize(): bool
    {
        $this->employee();

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $employee = $this->employee();

        return [
            'name' => ['sometimes', 'string', 'min:3', 'max:190'],
            'phone' => [
                'sometimes',
                'digits:11',
                Rule::unique('users', 'username')
                    ->ignore($employee->getAttribute('user_id'))
                    ->whereNull('deleted_at'),
            ],
            'password' => ['sometimes', 'string', new PasswordPolicy()],
            'accountType' => ['sometimes', 'string', Rule::in(EmployeeAccountType::values())],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(EmployeePermissions::keys())],
            'isActive' => ['sometimes', 'boolean'],
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

    public function accountType(): ?EmployeeAccountType
    {
        $value = $this->validated('accountType');

        return $value !== null ? EmployeeAccountType::from((string) $value) : null;
    }

    /** @return list<string>|null */
    public function permissions(): ?array
    {
        if (!$this->has('permissions')) {
            return null;
        }

        $value = $this->validated('permissions');

        return is_array($value) ? array_values(array_unique($value)) : [];
    }
}
