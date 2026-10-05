<?php

declare(strict_types=1);

namespace App\Http\Requests\QuestionBank;

use App\Enums\QuestionType;
use App\Http\Requests\ApiFormRequest;
use App\Models\Question;
use App\Support\AssessmentAccess;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/questions/{id} — freeze-table row 86.
 *
 * 404 'Question not found' → 404 'Question group not found' (orphan) → 403
 * 'You do not own this question group'. The bank is the ONLY authorization
 * boundary; see App\Support\AssessmentAccess::assertQuestionManageable().
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE UPDATE HAS **TWO** SEMANTICS, SPLIT PER FIELD, AND THE SPLIT IS NOT ║
 * ║ THE ONE THE GROUP ENDPOINT USES.                                        ║
 * ║                                                                          ║
 * ║ backend/QuestionRepository::update() builds its field list with two      ║
 * ║ different guards, interleaved:                                           ║
 * ║                                                                          ║
 * ║   isset($data['body'])                    -> body                        ║
 * ║   isset($data['type'])                    -> type                        ║
 * ║   array_key_exists('options', $data)      -> options                     ║
 * ║   array_key_exists('image_url', $data)    -> image_url                   ║
 * ║   array_key_exists('correct_answer', …)   -> correct_answer              ║
 * ║   isset($data['points'])                  -> points                      ║
 * ║                                                                          ║
 * ║ `isset()` is FALSE for null; `array_key_exists()` is TRUE for it. So:    ║
 * ║                                                                          ║
 * ║   field           absent     null                  ''                    ║
 * ║   ──────────────  ─────────  ────────────────────  ──────────────────    ║
 * ║   body            untouched  untouched             WRITTEN (TEXT)        ║
 * ║   type            untouched  untouched             422 (enum)            ║
 * ║   points          untouched  untouched             dropped (see below)   ║
 * ║   options         untouched  **CLEARED to NULL**   422 (array)           ║
 * ║   image_url       untouched  **CLEARED to NULL**   WRITTEN               ║
 * ║   correct_answer  untouched  **CLEARED to NULL**   WRITTEN               ║
 * ║                                                                          ║
 * ║ The three array_key_exists fields are the ONLY way to erase an answer    ║
 * ║ key, a choice list or an image through this API. Collapsing them onto    ║
 * ║ the isset() idiom would delete that capability silently.                 ║
 * ║                                                                          ║
 * ║ NOTE `''` differs from the group endpoint's `title`, which is read with  ║
 * ║ `!empty()` and therefore KEEPS the old value. Three idioms across two    ║
 * ║ endpoints of one feature; each is read off the legacy code it ports,     ║
 * ║ none is inferred from a sibling.                                         ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── `points: ''` IS DROPPED BEFORE VALIDATION — MECHANISM, NOT TASTE. ─────┐
 * │ Open decision 5.10: Laravel's presentOrRuleIsImplicit() SKIPS every      │
 * │ non-implicit rule (`numeric` included) for a blank string, so `''` would │
 * │ pass validation, survive `isset('')`, and reach a DECIMAL(6,2) column as │
 * │ SQLSTATE[HY000] 1366 — a 500. The fix is decision 5.1's: REMOVE the key  │
 * │ in prepareForValidation(), so the rules, the writer and the column agree │
 * │ BY CONSTRUCTION. `body`, `image_url` and `correct_answer` are TEXT/      │
 * │ VARCHAR and are deliberately NOT dropped — '' is legal in them and IS a  │
 * │ real instruction.                                                        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO `correctAnswer` ALIAS HERE. It exists on POST only; see
 * StoreQuestionRequest's banner for why the asymmetry is ported rather than
 * levelled.
 *
 * NO `question_group_id` RULE, EVER. Accepting it would let a teacher move a
 * question they own into another teacher's bank — the ownership check having
 * already passed against the OLD parent. App\Models\Question keeps the column
 * out of $fillable so the omission is structural and not merely a missing rule.
 */
final class UpdateQuestionRequest extends ApiFormRequest
{
    private ?Question $question = null;

    public function authorize(): bool
    {
        AssessmentAccess::assertQuestionManageable($this->question());

        return true;
    }

    /** `''` on the DECIMAL field means "not sent" — see the banner. */
    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        if (array_key_exists('points', $payload)
            && \is_string($payload['points'])
            && \trim($payload['points']) === ''
        ) {
            unset($payload['points']);
            $this->replace($payload);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // `nullable` on all six: null must behave exactly as the legacy
            // guards behave, and it is the guards — not the rules — that give
            // null its two different meanings. Without `nullable`, `string`
            // would 422 a null that the legacy code silently ignored (body) or
            // deliberately wrote (correct_answer).
            'body' => ['sometimes', 'nullable', 'string'],
            // Same isset()-guarded slot as `body`/`type` below: absent or null
            // leaves the title untouched, matching how every other optional
            // text field on this request behaves.
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', Rule::enum(QuestionType::class)],
            'options' => ['sometimes', 'nullable', 'array'],
            'options.*' => ['string'],
            'choice_format' => ['sometimes', 'nullable', Rule::in(['1234', 'abcd', 'arabic'])],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'correct_answer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'points' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function question(): Question
    {
        return $this->question ??= AssessmentAccess::findQuestionOrFail((string) $this->route('id'));
    }
}
