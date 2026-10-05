<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A question WITH its answer key — the shape course staff receive.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ONLY CLASS IN THE APPLICATION THAT EMITS `correct_answer`.          ║
 * ║ Asserted, not asserted-by-convention: QuestionBankTest greps every      ║
 * ║ Resource class for the key and requires exactly one match.              ║
 * ║                                                                          ║
 * ║ NOT `extends QuestionResource`. The seven shared keys are re-declared    ║
 * ║ below from scratch, for the same reason the exam trio does not use      ║
 * ║ inheritance: a field added to a shared parent appears in the child with ║
 * ║ no diff on the file anybody reviews, and on THIS family the field in    ║
 * ║ question is the answer key.                                             ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * SERVED TODAY ON ONE ROUTE ONLY — GET /question-groups/{groupId}/questions
 * (freeze-table row 84), which is `auth` + `role:admin,instructor` AND behind
 * QuestionGroupPolicy::view(), i.e. the bank's owner or an admin. Every caller
 * that can reach it authored the key.
 *
 * SESSION B will select between this class and QuestionResource on
 * App\Policies\ExamPolicy::viewAnswerKey() for row 90 — course staff always, a
 * student only when `exams.show_answers = 1` and only on their own submitted
 * result. That ability still throws; this class is ready for it.
 *
 * @mixin Question
 */
final class StaffQuestionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Question $question */
        $question = $this->resource;

        return [
            'id' => $question->getKey(),
            'question_group_id' => $question->getAttribute('question_group_id'),
            'title' => $question->getAttribute('title'),
            'body' => $question->getAttribute('body'),
            'type' => $question->getAttribute('type')?->value,
            'options' => $question->getAttribute('options'),
            'choice_format' => $question->getAttribute('choice_format'),
            'image_url' => $question->getAttribute('image_url'),
            // ── THE STAFF-ONLY FIELD. Nothing else on this class differs. ──
            'correct_answer' => $question->getAttribute('correct_answer'),
            'points' => (float) $question->getAttribute('points'),
        ];
    }
}
