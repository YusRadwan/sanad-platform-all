<?php

declare(strict_types=1);

namespace App\Http\Requests\Center;

use App\Http\Requests\ApiFormRequest;
use App\Models\CenterGroup;
use Illuminate\Validation\Rule;

/**
 * POST /center/groups.
 *
 * `schedules` REPLACES the old free-typed `schedule_text` as the actual
 * input — one or more {day_of_week, time} slots, day drawn from
 * CenterGroup::DAYS (a fixed set, not free text) and time a plain "H:i".
 * CenterGroupController computes the human `schedule_text` summary from
 * these and stores THAT, so every existing reader of `schedule_text`
 * (attendance/report group pickers, etc.) is unaffected.
 */
final class StoreCenterGroupRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'center_name' => ['required', 'string', 'max:190'],
            'grade_label' => ['required', 'string', 'max:190'],
            'group_name' => ['required', 'string', 'max:190'],
            'billing_type' => ['sometimes', 'string', Rule::in(CenterGroup::BILLING_TYPES)],
            'schedules' => ['required', 'array', 'min:1'],
            'schedules.*.day_of_week' => ['required', 'string', Rule::in(CenterGroup::DAYS)],
            'schedules.*.time' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * NOT named `data()` — `Illuminate\Http\Request::data($key = null, $default = null)`
     * already declares that name (JSON-body access), and this override's
     * incompatible signature (no params, non-nullable array) is a FATAL
     * ERROR the moment PHP loads the class in a real request — invisible to
     * `artisan test` here because the Center System test suite exercises
     * group creation through the Model/Action directly, never through this
     * Form Request's HTTP path. `validatedGroupData()` avoids the collision.
     *
     * @return array<string, mixed>
     */
    public function validatedGroupData(): array
    {
        return [
            'center_name' => (string) $this->validated('center_name'),
            'grade_label' => (string) $this->validated('grade_label'),
            'group_name' => (string) $this->validated('group_name'),
            'billing_type' => (string) ($this->validated('billing_type') ?: CenterGroup::BILLING_TYPES[1]),
        ];
    }

    /** @return list<array{day_of_week: string, time: string}> */
    public function schedules(): array
    {
        /** @var list<array{day_of_week: string, time: string}> $schedules */
        $schedules = $this->validated('schedules');

        return $schedules;
    }
}
