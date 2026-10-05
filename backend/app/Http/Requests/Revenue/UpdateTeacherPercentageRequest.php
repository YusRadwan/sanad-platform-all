<?php

declare(strict_types=1);

namespace App\Http\Requests\Revenue;

use App\Http\Requests\ApiFormRequest;

/**
 * PUT /api/v1/revenue/teachers/{teacherId}/percentage — freeze-table row 67.
 *
 * ┌── THE TEACHER ID COMES FROM THE **PATH** AND IS MERGED IN TO BE ─────────┐
 * │   VALIDATED. `teacher_profit_settings.teacher_id` is an FK to            │
 * │   `teachers.user_id`, so an unknown id in the path reached backend/'s    │
 * │   `INSERT … ON DUPLICATE KEY UPDATE` and died on the constraint — a 500. │
 * │   prepareForValidation() lifts the route segment into the validated      │
 * │   payload so one `exists:` rule covers it, exactly as the body fields    │
 * │   are covered. Same 500→422 correction as CreatePayoutRequest, and       │
 * │   again there is no literal 404 string in backend/ that this displaces.  │
 * │                                                                          │
 * │   `teachers.user_id`, not `users.id`: the FK points at the PROFILE row,  │
 * │   so a real account with no `teachers` row must still be refused.        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── `between:0,100`, AND THE MESSAGE IS THE CONTRACT. ─────────────────────┐
 * │ backend/RevenueService::updateTeacherPercentage() throws                 │
 * │ 'النسبة يجب أن تكون بين 0 و100' for `< 0 || > 100`. `between` is         │
 * │ INCLUSIVE at both ends, which matches: 0 and 100 both pass there and     │
 * │ both pass here.                                                          │
 * │                                                                          │
 * │ 0 IS A REAL VALUE, NOT "UNSET" — a teacher on a 0% share is a            │
 * │ constraint-permitted state (`chk_profit_settings_percentage` allows      │
 * │ `>= 0`) and every reader of this column tests `!== null` rather than     │
 * │ truthiness for that reason. See RecordRevenueTransaction::               │
 * │ percentageFor().                                                         │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * A MISSING `percentage` WAS A 500 (backend/ reads `$data['percentage']` with
 * no null-coalescing and casts it) and is now a 422 carrying the same range
 * string — because "you sent nothing" and "you sent 250" are, to this
 * endpoint, the same complaint about the same field.
 */
final class UpdateTeacherPercentageRequest extends ApiFormRequest
{
    /** backend/RevenueService::updateTeacherPercentage()'s literal. */
    public const OUT_OF_RANGE = 'النسبة يجب أن تكون بين 0 و100';

    /**
     * NEW STRING — backend/ answered this case with a 500. It exists because
     * an `exists:` rule with no message emits Laravel's raw translation KEY
     * (`validation.exists`) in a project that ships no lang file. Same
     * constant, same wording, as CreatePayoutRequest::TEACHER_NOT_FOUND; see
     * that class for the measurement and docs/open-decisions.md (7.14).
     */
    public const TEACHER_NOT_FOUND = 'المعلم غير موجود';

    /**
     * The path segment is not part of the JSON body, so it is not part of the
     * validated data unless it is put there. merge() is the framework's own
     * hook for this and runs BEFORE rules() is consulted.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['teacherId' => (string) $this->route('teacherId')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'teacherId' => ['required', 'string', 'exists:teachers,user_id'],
            'percentage' => ['required', 'numeric', 'between:0,100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'percentage.between' => self::OUT_OF_RANGE,
            'percentage.numeric' => self::OUT_OF_RANGE,
            'percentage.required' => self::OUT_OF_RANGE,
            'teacherId.exists' => self::TEACHER_NOT_FOUND,
        ]);
    }

    public function teacherId(): string
    {
        return (string) $this->validated('teacherId');
    }

    public function percentage(): float
    {
        return (float) $this->validated('percentage');
    }
}
