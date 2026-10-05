<?php

declare(strict_types=1);

namespace App\Http\Requests\QuestionBank;

use App\Http\Requests\ApiFormRequest;
use App\Models\QuestionGroup;
use App\Support\AssessmentAccess;

/**
 * GET /api/v1/question-groups/{groupId}/questions — freeze-table row 84.
 *
 * 404 'Question group not found' → 403 'You do not own this question group'.
 *
 * ┌── THIS READ IS admin/instructor IN THE **FINAL** CONTRACT TOO. ──────────┐
 * │ It is not a public route awaiting a public Resource, the way rows 20-22  │
 * │ were between Sessions 5B and 5C. It serves                               │
 * │ App\Http\Resources\StaffQuestionResource — the ONLY class in the         │
 * │ application that emits `correct_answer` — to the bank's owner and to an  │
 * │ admin, and to nobody else, ever. The student-facing paper is a different │
 * │ route (row 90, Session B) served by a different class                    │
 * │ (QuestionResource, which has no code path capable of reading the key).   │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * The ability is QuestionGroupPolicy::view(), NOT ::manage(), even though the
 * two bodies are equal today — see that policy for why a read that may widen
 * and a write that may not are kept as separate questions.
 */
final class ListQuestionsRequest extends ApiFormRequest
{
    private ?QuestionGroup $group = null;

    public function authorize(): bool
    {
        AssessmentAccess::assertGroupViewable($this->group());

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function group(): QuestionGroup
    {
        return $this->group ??= AssessmentAccess::findGroupOrFail((string) $this->route('groupId'));
    }
}
