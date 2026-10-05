<?php

declare(strict_types=1);

namespace App\Http\Requests\QuestionBank;

use App\Http\Requests\ApiFormRequest;
use App\Models\Question;
use App\Support\AssessmentAccess;

/**
 * DELETE /api/v1/questions/{id} — freeze-table row 87.
 *
 * 404 'Question not found' → 403 'You do not own this question group'. Note the
 * 403 names the GROUP, not the question: the bank is the only authorization
 * boundary and backend/ throws getOwnedGroup()'s string here verbatim.
 *
 * HARD delete, no re-authentication, no audit — the same class of write as
 * DELETE /content-items/{id}. A question carries no submission history of its
 * own; `exam_attempts.answers` is a JSON blob that stores question ids by
 * value, so a deleted question leaves an already-graded attempt intact rather
 * than dangling. Session B owns anything further about that table.
 */
final class DeleteQuestionRequest extends ApiFormRequest
{
    private ?Question $question = null;

    public function authorize(): bool
    {
        AssessmentAccess::assertQuestionManageable($this->question());

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function question(): Question
    {
        return $this->question ??= AssessmentAccess::findQuestionOrFail((string) $this->route('id'));
    }
}
