<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;

/**
 * POST /api/v1/admin/enrollment-codes — freeze-table row 57.
 *
 * ┌── TWO SPELLINGS OF ONE FIELD, AND THE snake_case ONE WINS. ──────────────┐
 * │ backend/EnrollmentCodeController::store() reads                          │
 * │ `$data['course_id'] ?? $data['courseId'] ?? ''`, so both are already on  │
 * │ the wire and dropping either would break a caller. prepareForValidation  │
 * │ normalises to `course_id` BEFORE the rules run, so the rest of the       │
 * │ request — and the 422 payload's error key — speaks one name.             │
 * │                                                                          │
 * │ Same duplication as decision 4.5 (`is_active` / `isActive` on            │
 * │ /admin/users), same resolution, same warning: it must not spread.        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * THE MISSING-COURSE ANSWER IS **404, NOT 422** — 'الدورة غير موجودة', pinned
 * by freeze-table row 57. So `exists:courses,id` is deliberately NOT a rule
 * here: it would answer 422 and change the contract. The controller resolves
 * the course itself and raises App\Exceptions\NotFoundException. This request
 * only asserts that SOMETHING was sent, with backend/'s literal string.
 *
 * `count` IS NEW (bulk generation, open decision 10.15): optional, defaults
 * to 1, capped at MAX_COUNT — an admin generating codes for a whole
 * classroom in one click, not a rate-limit workaround (there still is none).
 * `count=1` behaves byte-identical to the pre-existing single-code contract
 * except the RESPONSE shape, which is now always an array — see
 * EnrollmentCodeController::store()'s own docblock for that half.
 */
final class StoreEnrollmentCodeRequest extends ApiFormRequest
{
    public const COURSE_REQUIRED = 'برجاء اختيار الكورس';

    public const MAX_COUNT = 500;

    protected function prepareForValidation(): void
    {
        $courseId = $this->input('course_id', $this->input('courseId'));

        $this->merge(['course_id' => is_string($courseId) ? trim($courseId) : $courseId]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'course_id' => ['required', 'string'],
            'count' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_COUNT],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'course_id.required' => self::COURSE_REQUIRED,
            'course_id.string' => self::COURSE_REQUIRED,
            'count.integer' => 'عدد الأكواد يجب أن يكون رقماً صحيحاً',
            'count.min' => 'عدد الأكواد يجب أن يكون واحداً على الأقل',
            'count.max' => 'عدد الأكواد يجب ألا يزيد عن ' . self::MAX_COUNT,
        ];
    }

    public function courseId(): string
    {
        return (string) $this->validated('course_id');
    }

    public function count(): int
    {
        $count = $this->validated('count');

        return $count === null ? 1 : (int) $count;
    }
}
