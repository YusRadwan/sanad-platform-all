<?php

declare(strict_types=1);

namespace App\Http\Requests\QuestionBank;

use App\Enums\QuestionType;
use App\Http\Requests\ApiFormRequest;
use App\Models\QuestionGroup;
use App\Support\AssessmentAccess;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/question-groups/{groupId}/questions — freeze-table row 85.
 *
 * 404 'Question group not found' → 403 'You do not own this question group' →
 * 422 `['body' => ['نص السؤال مطلوب']]`.
 *
 * ORDER MATTERS AND IS PORTED: backend/createQuestion() calls getOwnedGroup()
 * BEFORE it checks `empty($data['body'])`, so a foreign teacher posting a
 * malformed body gets 403, not 422. authorize() runs before rules() in Laravel,
 * so the ordering falls out of putting the gate there — the same placement, and
 * the same reasoning, as the section routes (open decision 5.5).
 *
 * ┌── `correctAnswer` IS ACCEPTED AS AN ALIAS **ON CREATE ONLY**. ───────────┐
 * │ backend/QuestionBankService::createQuestion() reads                      │
 * │   $data['correct_answer'] ?? $data['correctAnswer'] ?? null              │
 * │ while updateQuestion() delegates to QuestionRepository::update(), whose  │
 * │ field ladder knows ONLY the snake_case key. So the alias exists on one   │
 * │ verb and not the other. That asymmetry is ported rather than tidied:     │
 * │ adding the alias to PATCH would make a body that is currently a NO-OP    │
 * │ start writing a column, which is a behaviour change no consumer asked    │
 * │ for. Recorded in docs/open-decisions.md as a contract note.              │
 * │                                                                          │
 * │ snake_case WINS when both are sent, matching the `??` chain's order.     │
 * │ Normalised in prepareForValidation() so the rules, the controller and    │
 * │ the model all see ONE key.                                               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── `image_url` CARRIES NO URL RULE, AND THAT IS DELIBERATE. ──────────────┐
 * │ backend/ applies UrlValidator to `videos.video_url`, `links.url` and     │
 * │ `videos.thumbnail_url` — and to nothing on `questions`. The value is     │
 * │ rendered as an `<img src>`, where a `javascript:` scheme does not        │
 * │ execute, so the omission is not the vulnerability class App\Rules\       │
 * │ SafeUrlValue exists for. Adding the rule would 422 requests that succeed │
 * │ today (an internal `uploads/...` path from the shared upload endpoint is │
 * │ not an http(s) URL and would be rejected). Ported as-is; `max:255`       │
 * │ matches the column and turns a would-be 1406 into a 422.                 │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class StoreQuestionRequest extends ApiFormRequest
{
    private ?QuestionGroup $group = null;

    public function authorize(): bool
    {
        AssessmentAccess::assertGroupManageable($this->group());

        return true;
    }

    /** Fold the camelCase alias into the snake_case key, snake_case winning. */
    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        if (!array_key_exists('correct_answer', $payload) && array_key_exists('correctAnswer', $payload)) {
            $payload['correct_answer'] = $payload['correctAnswer'];
            $this->replace($payload);
        }
    }

    /**
     * `type` defaults to 'mcq' and `points` to 1 IN THE CONTROLLER, not here —
     * backend/'s `$data['type'] ?? 'mcq'` / `$data['points'] ?? 1` are write-
     * time defaults, and a validator-side default would also fire for an
     * explicit null, which those `??` chains do too but for a different reason.
     * Keeping defaults in one place keeps them checkable.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `required` catches an absent key AND '' — exactly what
            // `empty($data['body'])` caught. `body` is TEXT, so no max.
            'body' => ['required', 'string'],
            // A short label distinct from `body` (the full prompt) — optional,
            // matches the column's own nullability.
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', Rule::enum(QuestionType::class)],
            // A JSON array of choice strings, meaningful only for mcq. The
            // column is JSON; anything non-array would encode to a shape the
            // reader's json_decode(…, true) never produced.
            'options' => ['sometimes', 'nullable', 'array'],
            'options.*' => ['string'],
            // How the four options are LABELLED when rendered — numeric
            // ('1234'), Latin letters ('abcd') or Arabic letters ('arabic').
            // Presentation only; never changes which option is correct.
            'choice_format' => ['sometimes', 'nullable', Rule::in(['1234', 'abcd', 'arabic'])],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'correct_answer' => ['sometimes', 'nullable', 'string', 'max:255'],
            // DECIMAL(6,2). `min:0` is new — backend/ had no bound and a
            // negative score simply landed in the column. No previously
            // successful, sane request changes.
            'points' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'body.required' => 'نص السؤال مطلوب',
        ]);
    }

    public function group(): QuestionGroup
    {
        return $this->group ??= AssessmentAccess::findGroupOrFail((string) $this->route('groupId'));
    }
}
