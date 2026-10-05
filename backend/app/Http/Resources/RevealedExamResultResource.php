<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A student's exam result WITH the answer key — freeze-table row 91, revealed.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ ONE OF ONLY TWO CLASSES IN THE APPLICATION THAT EMIT A `correct_answer` ║
 * ║ VALUE. THE OTHER IS StaffQuestionResource.                              ║
 * ║                                                                          ║
 * ║ SELECTED BY App\Policies\ExamPolicy::viewAnswerKey() AND BY NOTHING     ║
 * ║ ELSE — course staff always, a student only when `exams.show_answers`    ║
 * ║ is 1. That ability is a PURE function of the exam and the caller; the   ║
 * ║ choice between this class and ExamResultResource is made once, in       ║
 * ║ ExamResultResourceFactory.                                              ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── THE KEY IS THE EXAM'S, NEVER ANOTHER STUDENT'S ANSWERS. ───────────────┐
 * │ Only two fields per question ship — `question_id` and `correct_answer`   │
 * │ — read from THIS exam's bank. No attempt row other than the one being    │
 * │ reported on is ever touched, and `question_group_id` is not emitted      │
 * │ (approved decision #3: the bank id is a tenant identifier, and this is   │
 * │ a student-facing payload).                                               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NOT `extends ExamResultResource`. The shared nine keys come from
 * App\Http\Resources\ExamResultPayload, a plain function — see its banner for
 * why inheritance is the wrong tool for exactly this pair.
 *
 * @mixin ExamAttempt
 */
final class RevealedExamResultResource extends JsonResource
{
    /** @param Collection<int, Question> $questions this exam's bank */
    public function __construct(
        ExamAttempt $attempt,
        private readonly Exam $exam,
        private readonly Collection $questions,
    ) {
        parent::__construct($attempt);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // `show_answers` is TRUE by construction: this class is only ever
        // selected when the reveal decision was yes.
        return ExamResultPayload::of($this->resource, $this->exam, true) + [
            'correct_answers' => $this->questions->map(static fn (Question $question): array => [
                'question_id' => $question->getKey(),
                'correct_answer' => $question->getAttribute('correct_answer'),
            ])->values()->all(),
        ];
    }
}
