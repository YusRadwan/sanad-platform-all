<?php

declare(strict_types=1);

namespace App\Http\Requests\QuestionBank;

use App\Http\Requests\ApiFormRequest;
use App\Models\QuestionGroup;
use App\Support\AssessmentAccess;

/**
 * DELETE /api/v1/question-groups/{id} — freeze-table row 83.
 *
 * 404 'Question group not found' → 403 'You do not own this question group'.
 *
 * ┌── HARD DELETE, AND IT **CASCADES TWICE** — WITH ONE OPEN ITEM. ──────────┐
 * │ `question_groups` has no `deleted_at`, so this is a real DELETE, and two │
 * │ foreign keys react to it in DIFFERENT ways:                              │
 * │                                                                          │
 * │   fk_questions_group     ON DELETE **CASCADE**  — the bank's questions   │
 * │                          go with it. Intended: they have no meaning      │
 * │                          outside their bank.                             │
 * │   fk_exams_question_group ON DELETE **SET NULL** — every exam pointing   │
 * │                          at the bank is silently DETACHED and keeps      │
 * │                          working with zero questions.                    │
 * │                                                                          │
 * │ THE SECOND ONE IS THE OPEN ITEM (6.3 in docs/open-decisions.md, and the  │
 * │ fifth question of this session's plan): should this route refuse — 409 — │
 * │ while any exam still links to the bank? It is NOT implemented here, by   │
 * │ explicit instruction. Adding a guard would change a request that         │
 * │ currently succeeds into one that fails, which is a product decision and  │
 * │ not a rewrite decision. Recorded, not built.                             │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO RE-AUTHENTICATION. Stage 4's decision #1 requires the acting admin's
 * password on the three ADMIN deletes (users, teachers, students) because those
 * destroy accounts. This is an instructor deleting their own working material,
 * like DELETE /groups/{id} and DELETE /content-items/{id}, neither of which
 * asks for one. Same class, same answer.
 */
final class DeleteQuestionGroupRequest extends ApiFormRequest
{
    private ?QuestionGroup $group = null;

    public function authorize(): bool
    {
        AssessmentAccess::assertGroupManageable($this->group());

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function group(): QuestionGroup
    {
        return $this->group ??= AssessmentAccess::findGroupOrFail((string) $this->route('id'));
    }
}
