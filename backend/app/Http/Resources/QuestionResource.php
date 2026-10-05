<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A question WITHOUT its answer key — the shape a student sitting the exam
 * receives.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `correct_answer` IS NOT HERE, AND IT CANNOT BE. THIS CLASS HAS NO CODE  ║
 * ║ PATH — none, not one branch — CAPABLE OF READING THAT COLUMN.           ║
 * ║                                                                          ║
 * ║ backend/ExamService::questionsFor() strips it with                       ║
 * ║   array_map(fn ($q) => { unset($q['correct_answer']); return $q; }, …)   ║
 * ║ i.e. it BUILDS the full row and then removes the key. That is a          ║
 * ║ subtractive design, and subtractive designs fail open: a new call site   ║
 * ║ that forgets the unset ships the key. Two classes fail CLOSED — this one ║
 * ║ never wrote it in the first place.                                       ║
 * ║                                                                          ║
 * ║ Same anti-`when()`, anti-inheritance rule as the exam/assignment trio.   ║
 * ║ StaffQuestionResource does NOT extend this class and must not.           ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `question_group_id` IS ALSO ABSENT, AND THAT IS THE ONE PLACE THIS      ║
 * ║ CLASS DEPARTS FROM backend/QuestionBankService::presentQuestion().      ║
 * ║                                                                          ║
 * ║ It is the SAME field, and the same argument, as approved decision #3 on  ║
 * ║ EnrolledExamResource: the bank id is a TENANT IDENTIFIER, and handing it ║
 * ║ to a student turns GET /question-groups/{id}/questions from a guess into ║
 * ║ a targeted request. Emitting it here would re-open, one route over,      ║
 * ║ exactly the leak decision #3 closes — a student who cannot see the id on ║
 * ║ GET /exams/{id} would read it off the paper instead.                     ║
 * ║                                                                          ║
 * ║ THIS BREAKS NO CONTRACT. The only route that would serve this class is   ║
 * ║ row 90, which does not exist yet — Session B ships it FIRST, so there is ║
 * ║ no consumer to regress. The staff shape keeps the field                  ║
 * ║ (StaffQuestionResource), which is where the bank picker needs it.        ║
 * ║ Recorded in docs/open-decisions.md as item 6.4 so Session B can reverse  ║
 * ║ it deliberately if a real consumer appears.                              ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── BUILT IN SESSION A, CONSUMED BY SESSION B. THAT IS DELIBERATE. ────────┐
 * │ No route in this session serves this class: the student-facing paper is  │
 * │ GET /exams/{examId}/questions (freeze-table row 90), which belongs with  │
 * │ the submit/grade/results family in Session B. It is built now because it │
 * │ is PURE DATA SHAPING with no write-path dependency, and because the      │
 * │ alternative — Session B inventing the split while also writing the       │
 * │ concurrency-critical attempt path — is how the answer key leaks.         │
 * │                                                                          │
 * │ The gate it will be selected by already exists too:                      │
 * │ App\Policies\ExamPolicy::viewAnswerKey() (still throwing, Session B's).  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `options` is already a PHP array — App\Models\Question casts it — so it
 * ships as a JSON array, matching QuestionRepository::decode()'s json_decode.
 * It is NULL for a true_false/short_answer row, and null is the ported answer
 * (the legacy presenter emitted `'options' => $row['options']` unconditionally)
 * rather than an omitted key.
 *
 * `points` is (float): the column is DECIMAL(6,2), PDO returns it as a string,
 * and backend/QuestionBankService::presentQuestion() cast it to float. The
 * model's `decimal:2` cast pins the stored precision; the wire cast is here,
 * where presentation belongs.
 *
 * @mixin Question
 */
final class QuestionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Question $question */
        $question = $this->resource;

        return [
            'id' => $question->getKey(),
            // NOTE: `question_group_id` is DELIBERATELY ABSENT — see the
            // banner. It is the ONE field this class drops relative to
            // backend/QuestionBankService::presentQuestion().
            'title' => $question->getAttribute('title'),
            'body' => $question->getAttribute('body'),
            'type' => $question->getAttribute('type')?->value,
            'options' => $question->getAttribute('options'),
            'choice_format' => $question->getAttribute('choice_format'),
            'image_url' => $question->getAttribute('image_url'),
            'points' => (float) $question->getAttribute('points'),
        ];
    }
}
