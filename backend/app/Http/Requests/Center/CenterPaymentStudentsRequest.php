<?php

declare(strict_types=1);

namespace App\Http\Requests\Center;

use App\Http\Requests\ApiFormRequest;
use App\Models\CenterGroup;
use App\Models\CenterPaymentRecord;
use App\Support\CenterAccess;
use Illuminate\Validation\Rule;

/**
 * GET /center/payments/students — "متابعة مدفوعات السنتر": the chosen
 * group's roster with each student's paid/unpaid status for one
 * (payment_type, period). `group_id` resolves through the SAME
 * CenterAccess::assertOwnsGroup() gate every other center/groups/{id}
 * route uses — a foreign teacher's group_id 403s here exactly as it does
 * on the attendance page.
 */
final class CenterPaymentStudentsRequest extends ApiFormRequest
{
    private ?CenterGroup $group = null;

    public function authorize(): bool
    {
        CenterAccess::assertOwnsGroup($this->group());

        return true;
    }

    public function group(): CenterGroup
    {
        return $this->group ??= CenterAccess::findGroupOrFail($this->query('group_id'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'group_id' => ['required', 'string'],
            'payment_type' => ['required', 'string', Rule::in(CenterPaymentRecord::TYPES)],
            'period' => ['required', 'string', $this->periodMatchesTypeRule()],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /** "2026-09" for a month record, "2026-09-07" for a session record — see CenterPaymentRecord's docblock. */
    private function periodMatchesTypeRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $type = (string) $this->query('payment_type');
            $pattern = $type === CenterPaymentRecord::TYPE_MONTH ? '/^\d{4}-\d{2}$/' : '/^\d{4}-\d{2}-\d{2}$/';

            if (!is_string($value) || !preg_match($pattern, $value)) {
                $fail($type === CenterPaymentRecord::TYPE_MONTH
                    ? 'صيغة الشهر يجب أن تكون YYYY-MM'
                    : 'صيغة تاريخ الحصة يجب أن تكون YYYY-MM-DD');
            }
        };
    }

    public function paymentType(): string
    {
        return (string) $this->validated('payment_type');
    }

    public function period(): string
    {
        return (string) $this->validated('period');
    }

    public function q(): string
    {
        return trim((string) $this->query('q', ''));
    }
}
