<?php

declare(strict_types=1);

namespace App\Http\Requests\ContentItem\Concerns;

use App\Enums\ContentItemType;
use App\Rules\OwnedQuestionGroup;
use App\Rules\SafeUrlValue;

/**
 * The type-specific half of every content-item write, in one place.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE RULES ARE KEYED ON THE **RESOLVED TYPE**, NOT APPLIED TO EVERY      ║
 * ║ REQUEST. THAT IS A FIDELITY REQUIREMENT, NOT TIDINESS.                  ║
 * ║                                                                          ║
 * ║ backend/ContentItemService::validateUrls() reads:                        ║
 * ║                                                                          ║
 * ║   if ($type === 'video')      { video_url, thumbnail_url }               ║
 * ║   elseif ($type === 'link')   { url }                                     ║
 * ║                                                                          ║
 * ║ So a NOTE carrying a junk `url` key is accepted today (the key is        ║
 * ║ ignored, never persisted, never validated). Applying the URL rule        ║
 * ║ unconditionally would 422 a request that currently answers 200 — a       ║
 * ║ contract break dressed up as extra safety, and one with no security      ║
 * ║ value at all, because the value in question is never stored or rendered. ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── `sometimes` + `nullable` EVERYWHERE, AND NEITHER IS DECORATION. ───────┐
 * │ `sometimes`  an absent key is not a validation failure — every detail    │
 * │              field is optional, including video_url. backend/'s          │
 * │              saveTypeDetail() is guarded by isset(), so a video item     │
 * │              with no URL legitimately gets no `videos` row rather than   │
 * │              a 422.                                                      │
 * │ `nullable`   an explicit null must behave exactly like an absent key,    │
 * │              because isset() cannot tell them apart. Without it, `string`│
 * │              would reject a null the legacy code silently ignored.       │
 * │                                                                          │
 * │ What is left over is '' — the ONE value that means something. See        │
 * │ App\Rules\SafeUrlValue for the thumbnail_url case and                    │
 * │ App\Actions\ContentItem\PersistNoteDetail for content_text's.            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── THE NUMERIC BOUNDS TURN THREE 500s INTO 422s. ─────────────────────────┐
 * │ `exams` and `assignments` carry CHECK constraints (migrations 000021 /   │
 * │ 000023): pass_score 0-100, total_score >= 0, allowed_attempts >= 1,      │
 * │ duration_minutes > 0, max_score >= 0. backend/ validates NONE of them,   │
 * │ so `allowed_attempts: 0` is a raw constraint violation — a 500 naming    │
 * │ chk_exams_scores. The rules below answer 422 instead. No request that    │
 * │ previously SUCCEEDED changes; this is the same class of fix recorded as  │
 * │ decision 5.2 in Session A.                                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
trait ValidatesContentDetail
{
    /**
     * @return array<string, mixed>  rules for $type's detail fields, or [] for
     *                               a type with no detail table (`file`).
     */
    protected function detailRules(?ContentItemType $type): array
    {
        if ($type === null) {
            return [];
        }

        return match ($type) {
            ContentItemType::Video => [
                // UrlValidator::required(): '' is a 422 here, unlike thumbnail.
                'video_url' => ['sometimes', 'nullable', 'string', 'max:255', new SafeUrlValue()],
                // UrlValidator::nullable(): '' means "remove the poster".
                'thumbnail_url' => ['sometimes', 'nullable', 'string', 'max:255', new SafeUrlValue(allowBlank: true)],
                'duration' => ['sometimes', 'nullable', 'integer', 'min:0'],
                'is_preview' => ['sometimes', 'nullable', 'boolean'],
                'provider' => ['sometimes', 'nullable', 'string', 'max:50'],
            ],
            ContentItemType::Link => [
                'url' => ['sometimes', 'nullable', 'string', 'max:255', new SafeUrlValue()],
            ],
            ContentItemType::Note => [
                'content_text' => ['sometimes', 'nullable', 'string'],
                'file_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'file_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            ],
            ContentItemType::Exam => [
                /*
                 * ╔══════════════════════════════════════════════════════════╗
                 * ║ STAGE 6 / SESSION A — APPROVED DECISION #2. THE ONE      ║
                 * ║ CHANGE THIS SESSION MAKES TO A STAGE 5B FILE.            ║
                 * ║                                                          ║
                 * ║ Session B left this field with no ownership check and    ║
                 * ║ said why: "Stage 6 owns the question-group surface and   ║
                 * ║ the ownership rule that belongs with it." This is that   ║
                 * ║ rule. It is the ONLY point in the application where      ║
                 * ║ `exams.question_group_id` is accepted from a request, so ║
                 * ║ one rule closes the whole leak — read                    ║
                 * ║ App\Rules\OwnedQuestionGroup for what the leak actually  ║
                 * ║ read (another teacher's answer keys).                    ║
                 * ║                                                          ║
                 * ║ STILL NO `exists:question_groups,id`, for the reason     ║
                 * ║ Session B gave and this rule preserves: an existence     ║
                 * ║ rule is a positive oracle over other teachers' bank ids. ║
                 * ║ OwnedQuestionGroup answers ONE message for "not yours"   ║
                 * ║ and "no such bank" alike.                                ║
                 * ╚══════════════════════════════════════════════════════════╝
                 */
                'question_group_id' => [
                    'sometimes', 'nullable', 'string', 'max:36',
                    new OwnedQuestionGroup($this->user()),
                ],
                'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'pass_score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
                'total_score' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'allowed_attempts' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'randomize_questions' => ['sometimes', 'nullable', 'boolean'],
                'lock_content' => ['sometimes', 'nullable', 'boolean'],
                'show_answers' => ['sometimes', 'nullable', 'boolean'],
            ],
            // Decision #1: `file` has no detail table and therefore no fields.
            ContentItemType::File => [],
        };
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ REMOVE `''` FROM EVERY NON-TEXT FIELD **BEFORE** VALIDATION RUNS.    ║
     * ║ MEASURED, NOT ANTICIPATED — THE `date` RULE DOES NOT CATCH IT.      ║
     * ║                                                                      ║
     * ║ Laravel's Validator::presentOrRuleIsImplicit() reads:                ║
     * ║                                                                      ║
     * ║   if (is_string($value) && trim($value) === '') {                    ║
     * ║       return $this->isImplicit($rule);                               ║
     * ║   }                                                                  ║
     * ║                                                                      ║
     * ║ `date`, `integer` and `numeric` are NOT implicit rules, so a blank    ║
     * ║ string SKIPS them entirely and arrives at the column untouched.       ║
     * ║ Measured: `start_at: ''` reached MySQL and answered                  ║
     * ║   SQLSTATE[22007] 1292 Incorrect datetime value: ''                  ║
     * ║ — a 500 — with the `nullable|date` rule already in place. The rule    ║
     * ║ was not enough and asserting that it was would have shipped the bug.  ║
     * ║                                                                      ║
     * ║ THIS IS DECISION 5.1'S EXACT SITUATION, ONE COLUMN TYPE OVER.        ║
     * ║ Session A found `discount_price: ''` was a 1366 on a DECIMAL and     ║
     * ║ settled it by REMOVING the key in prepareForValidation() — adopting  ║
     * ║ the validator's reading ("'' means not sent") rather than inventing   ║
     * ║ a clear semantic backend/ never had. The same reading, the same       ║
     * ║ mechanism, and the same reason apply here: the split between "'' is  ║
     * ║ a CLEAR instruction" (description, title, content_text, thumbnail)   ║
     * ║ and "'' is IGNORED" (dates, numbers) is a property of the COLUMN     ║
     * ║ TYPE, not of anybody's preference.                                    ║
     * ║                                                                      ║
     * ║ Doing it by REMOVAL rather than by a rule means the validator, the   ║
     * ║ persister and the controller agree BY CONSTRUCTION instead of by     ║
     * ║ three people remembering the same edge case.                          ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * The BOOLEAN fields are deliberately absent from the list: TINYINT(1)
     * accepts '' as 0 rather than erroring, and `(bool) ''` is false, so the
     * value is harmless and removing it would change an existing outcome.
     */
    protected function dropBlankTypedFields(): void
    {
        $typed = [
            // DATETIME
            'start_at', 'end_at', 'due_at',
            // INT / DECIMAL
            'duration', 'duration_minutes', 'allowed_attempts',
            'pass_score', 'total_score', 'max_score',
        ];

        $payload = $this->all();
        $touched = false;

        foreach ($typed as $field) {
            if (array_key_exists($field, $payload)
                && \is_string($payload[$field])
                && \trim($payload[$field]) === ''
            ) {
                unset($payload[$field]);
                $touched = true;
            }
        }

        if ($touched) {
            $this->replace($payload);
        }
    }

    /**
     * The parent `content_items` columns every write shares.
     *
     * `title` is NOT here: it is required on create and optional on update, and
     * a shared rule that had to be overridden by both callers would be worse
     * than two explicit declarations.
     *
     * `max:190` matches the column exactly (VARCHAR(190)) — backend/ had no
     * length rule at all and answered 1406 'Data too long' as a 500, the same
     * gap Session A closed on `courses.title`.
     *
     * @return array<string, mixed>
     */
    protected function parentRules(): array
    {
        return [
            'is_hidden' => ['sometimes', 'nullable', 'boolean'],
            // NO `min:` and never one: `content_items.description` is TEXT, ''
            // is legal in it, and backend/'s array_filter genuinely wrote ''.
            // That is what makes `description: ''` a CLEAR instruction.
            'description' => ['sometimes', 'nullable', 'string'],
            // `date` turns `start_at: ''` from a 1292 'Incorrect datetime
            // value' 500 into a 422 — the DATETIME twin of decision 5.1's
            // DECIMAL finding.
            'start_at' => ['sometimes', 'nullable', 'date'],
            'end_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
