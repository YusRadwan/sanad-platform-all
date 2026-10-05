<?php

declare(strict_types=1);

namespace App\Http\Requests\Group;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;

/**
 * POST /api/v1/groups — freeze-table row 75.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ ONE COMBINED ERROR, UNDER ONE KEY, WITH backend/'s LITERAL WORDING.     ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * backend/GroupService::create() (~lines 32-35):
 *
 *     $courseId = $data['course_id'] ?? $data['courseId'] ?? null;
 *     if (!$courseId || empty($data['title'])) {
 *         throw new ValidationException(['course_id' => ['course_id والعنوان مطلوبان']]);
 *     }
 *
 * Note what that is NOT: it is not two `required` rules. Either field missing
 * produces exactly ONE error, reported under `course_id`, describing both. The
 * obvious Laravel translation —
 *
 *     'course_id' => ['required'], 'title' => ['required']
 *
 * — would answer TWO errors under TWO keys with two different messages, which
 * is a different response body for the same request. So the check is expressed
 * as a single after-hook instead, and no `required` rule is declared.
 *
 * `!$courseId || empty($title)` is FALSY-based, not presence-based: '' and '0'
 * both count as missing. `filled()` is Laravel's exact equivalent for the
 * string case, and the explicit `'0'` note matters only in theory (no course
 * id is ever '0'), so filled() is used without further contortion.
 *
 * ┌── TWO KEY NAMES, ONE FIELD ──────────────────────────────────────────────┐
 * │ frontend/src/services/groupApiService.ts posts `courseId`; other callers │
 * │ post `course_id`. backend/ accepts both with snake_case winning, and     │
 * │ prepareForValidation() collapses them here so everything downstream sees │
 * │ one key. Same pattern as grade/grade_name on the course routes.          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * OWNERSHIP IS **NOT** CHECKED HERE, and that is the ported order: backend/
 * validates first, then resolves the course (404), then checks ownership
 * (403). Laravel runs authorize() BEFORE validation, so putting the gate in
 * this class would invert the two and turn a malformed request from a foreign
 * instructor into a 403 where it used to be a 422.
 * App\Http\Controllers\GroupController::store() holds the gate instead.
 */
final class StoreCourseGroupRequest extends ApiFormRequest
{
    public const REQUIRED_MESSAGE = 'course_id والعنوان مطلوبان';

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (array_key_exists('courseId', $data) || array_key_exists('course_id', $data)) {
            // snake_case wins when both are sent — it is the key backend/
            // reads first and therefore the established contract.
            $data['course_id'] = $data['course_id'] ?? $data['courseId'];
        }

        unset($data['courseId']);

        $this->replace($data);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Shape only. Presence is the after-hook's business — see above.
            'course_id' => ['sometimes', 'nullable', 'string'],
            'title' => ['sometimes', 'nullable', 'string', 'max:190'],
            'is_hidden' => ['sometimes', 'boolean'],
            // Open decision 10.21. NULL is a real, permanent state — "no
            // start/end gate" — not "not chosen yet"; both are nullable
            // rather than required. `after_or_equal:start_at` only fires
            // when both are present in THIS request, which is exactly the
            // case that matters on create (the form always submits both).
            'start_at' => ['sometimes', 'nullable', 'date'],
            'end_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_at'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('course_id') && $this->filled('title')) {
                return;
            }

            $validator->errors()->add('course_id', self::REQUIRED_MESSAGE);
        });
    }
}
