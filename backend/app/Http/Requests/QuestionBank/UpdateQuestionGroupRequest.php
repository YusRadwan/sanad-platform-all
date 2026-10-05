<?php

declare(strict_types=1);

namespace App\Http\Requests\QuestionBank;

use App\Http\Requests\ApiFormRequest;
use App\Models\QuestionGroup;
use App\Support\AssessmentAccess;

/**
 * PATCH /api/v1/question-groups/{id} — freeze-table row 82.
 *
 * THE REFUSAL LADDER, from backend/QuestionBankService::updateGroup():
 *   404 'Question group not found'          the id names nothing
 *   403 'You do not own this question group'
 *   422                                     a bad body
 *
 * The gate lives in authorize(), which Laravel runs BEFORE the rules, so a
 * foreign teacher never receives a 422 describing someone else's bank. That is
 * the SECTION-route ordering (backend/ checks ownership first here too), not
 * the group-route one — see open decision 5.5 for why the two families differ
 * and why the order is read off each legacy service rather than standardised.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE `''` SEMANTICS ARE **INVERTED BETWEEN THE TWO FIELDS OF THIS ONE    ║
 * ║ REQUEST**, AND THAT IS PORTED, MEASURED BEHAVIOUR — NOT SYMMETRY WITH   ║
 * ║ ANY PRIOR STAGE.                                                        ║
 * ║                                                                          ║
 * ║ backend/QuestionBankService::updateGroup() reads the two fields with     ║
 * ║ TWO DIFFERENT IDIOMS, on adjacent lines:                                 ║
 * ║                                                                          ║
 * ║   $title = !empty($data['title']) ? $data['title'] : $group['title'];    ║
 * ║   $grade = array_key_exists('grade', $data)                              ║
 * ║               ? $data['grade'] : ($group['grade'] ?? null);              ║
 * ║                                                                          ║
 * ║ and the repository then writes BOTH unconditionally                      ║
 * ║ (`UPDATE … SET title = :title, grade = :grade`). So:                     ║
 * ║                                                                          ║
 * ║   field   absent      null                    ''                         ║
 * ║   ─────   ──────────  ──────────────────────  ───────────────────────    ║
 * ║   title   untouched   untouched (!empty)      **KEPT** — '' is falsy,    ║
 * ║                                                so the OLD title is       ║
 * ║                                                re-written                ║
 * ║   grade   untouched   **CLEARED to NULL**      **WRITTEN as ''**         ║
 * ║                       (the key IS present)     (the key IS present)      ║
 * ║                                                                          ║
 * ║ Read one half only and you get the wrong answer for the other. This is   ║
 * ║ NOT the course/content-item convention (where '' CLEARS a text column    ║
 * ║ and is IGNORED on a typed one) — `title` and `grade` are BOTH VARCHARs   ║
 * ║ here, so the split is driven by the IDIOM, not by the column type.       ║
 * ║                                                                          ║
 * ║ Pinned by QuestionBankFieldSemanticsTest::test_the_inverted_convention_  ║
 * ║ holds_for_both_fields_in_one_request, which sends `{title:'',grade:''}`  ║
 * ║ in a SINGLE request and asserts the title survived while the grade       ║
 * ║ became ''.                                                              ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── THE `'0'` QUIRK IS PART OF `!empty()` AND IS PORTED WITH IT. ──────────┐
 * │ `!empty('0')` is FALSE in PHP, so `title: '0'` also means "keep the old  │
 * │ title" — a genuinely odd but real behaviour of the endpoint. It is       │
 * │ reproduced literally in the controller rather than "fixed", because a    │
 * │ bank actually named "0" is not worth a silent contract change; it is     │
 * │ recorded in docs/open-decisions.md instead.                              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NONE OF THIS IS EXPRESSIBLE unless `''` survives the global
 * ConvertEmptyStringsToNull middleware, which rewrites every '' to null before
 * any rule runs. `api/v1/question-groups*` is therefore on
 * AppServiceProvider::preserveEmptyStringsOnClearableWrites()'s allow-list —
 * open decision 4.1's standing obligation on every stage that adds a write
 * route distinguishing '' from null. Forgetting it fails SILENTLY with a 200.
 */
final class UpdateQuestionGroupRequest extends ApiFormRequest
{
    private ?QuestionGroup $group = null;

    public function authorize(): bool
    {
        AssessmentAccess::assertGroupManageable($this->group());

        return true;
    }

    /**
     * `nullable` on BOTH, and `string` on both — so `''` passes validation and
     * reaches the controller, where the two idioms above give it two different
     * meanings. A `min:1` on `title` would 422 the request the legacy endpoint
     * answers 200 to.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:190'],
            'grade' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function group(): QuestionGroup
    {
        return $this->group ??= AssessmentAccess::findGroupOrFail((string) $this->route('id'));
    }
}
