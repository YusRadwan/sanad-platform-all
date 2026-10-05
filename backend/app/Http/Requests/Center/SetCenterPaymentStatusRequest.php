<?php

declare(strict_types=1);

namespace App\Http\Requests\Center;

use App\Http\Requests\ApiFormRequest;
use App\Models\CenterGroup;
use App\Models\CenterPaymentRecord;
use App\Support\CenterAccess;
use Illuminate\Validation\Rule;

/** POST /center/payments — "تسجيل حالة الدفع" (دفع/لم يدفع). */
final class SetCenterPaymentStatusRequest extends ApiFormRequest
{
    private ?CenterGroup $group = null;

    public function authorize(): bool
    {
        CenterAccess::assertOwnsGroup($this->group());

        return true;
    }

    public function group(): CenterGroup
    {
        return $this->group ??= CenterAccess::findGroupOrFail($this->input('group_id'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'group_id' => ['required', 'string'],
            'student_id' => ['required', 'string'],
            'payment_type' => ['required', 'string', Rule::in(CenterPaymentRecord::TYPES)],
            'period' => ['required', 'string', $this->periodMatchesTypeRule()],
            'status' => ['required', 'string', Rule::in(CenterPaymentRecord::STATUSES)],
        ];
    }

    private function periodMatchesTypeRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $type = (string) $this->input('payment_type');
            $pattern = $type === CenterPaymentRecord::TYPE_MONTH ? '/^\d{4}-\d{2}$/' : '/^\d{4}-\d{2}-\d{2}$/';

            if (!is_string($value) || !preg_match($pattern, $value)) {
                $fail($type === CenterPaymentRecord::TYPE_MONTH
                    ? 'صيغة الشهر يجب أن تكون YYYY-MM'
                    : 'صيغة تاريخ الحصة يجب أن تكون YYYY-MM-DD');
            }
        };
    }

    public function studentId(): string
    {
        return (string) $this->validated('student_id');
    }

    public function paymentType(): string
    {
        return (string) $this->validated('payment_type');
    }

    public function period(): string
    {
        return (string) $this->validated('period');
    }

    public function status(): string
    {
        return (string) $this->validated('status');
    }
}
