<?php

declare(strict_types=1);

namespace App\Http\Requests\QuestionBank;

use App\Http\Requests\ApiFormRequest;

/**
 * POST /api/v1/question-groups — freeze-table row 81.
 *
 * ┌── THERE IS NOTHING TO AUTHORISE, AND THAT IS THE POINT. ─────────────────┐
 * │ The row's owner is `teacher_id`, and it is taken from the SESSION by     │
 * │ QuestionBankController::storeGroup() — never from the body, and never    │
 * │ mass-assignable (App\Models\QuestionGroup omits it from $fillable). So   │
 * │ there is no resource the caller NAMES and therefore no policy question:  │
 * │ `role:admin,instructor` on the route is the entire gate, exactly as the  │
 * │ freeze table records for row 81 ("`teacher_id` من الجلسة").              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `'العنوان مطلوب'` IS THE LITERAL CONTRACT STRING, not Laravel's default.
 * backend/QuestionBankService::createGroup() throws
 * `['title' => ['العنوان مطلوب']]` on `empty($data['title'])`, and `empty()`
 * catches BOTH an absent key and `''` — which Laravel's `required` also does.
 * The two agree, so one rule reproduces both branches.
 *
 * `max:190` matches the column exactly (VARCHAR(190)). backend/ had no length
 * rule, so a longer title was SQLSTATE[22001] 1406 'Data too long' — a 500
 * where 422 is owed. The same gap Session 5A closed on `courses.title` and 5B
 * on `content_items.title`; no previously-successful request changes.
 *
 * `grade` is a FREE-TEXT label, not a foreign key (migration 000019 says so —
 * it mirrors what the teacher types in the bank filter, exactly as
 * `teachers.grades` stores grade NAME strings). So there is no `exists:` rule
 * and there must not be one: adding it would reject every bank filed under a
 * label later removed from the catalogue.
 */
final class StoreQuestionGroupRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:190'],
            'grade' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'title.required' => 'العنوان مطلوب',
        ]);
    }
}
