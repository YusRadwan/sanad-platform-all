<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Exam;
use App\Models\ExamAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A student's exam result WITHOUT the answer key — freeze-table row 91.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `correct_answers` IS NOT HERE, AND IT CANNOT BE. THIS CLASS HAS NO CODE  ║
 * ║ PATH CAPABLE OF READING A `correct_answer` COLUMN — it is never handed   ║
 * ║ the questions at all.                                                    ║
 * ║                                                                          ║
 * ║ THE KEY IS **ABSENT**, NOT NULL, NOT AN EMPTY ARRAY. That is the ported  ║
 * ║ contract (backend/ExamService::resultsFor() only assigns                 ║
 * ║ `$result['correct_answers']` inside an `if`), and it is also the only    ║
 * ║ shape a client can test with `'correct_answers' in data`. A null would   ║
 * ║ be indistinguishable from "revealed, but the exam has no questions".     ║
 * ║                                                                          ║
 * ║ SAME ANTI-`when()`, ANTI-INHERITANCE RULE AS THE QUESTION AND EXAM       ║
 * ║ FAMILIES. RevealedExamResultResource does NOT extend this class and must ║
 * ║ not: a `$this->when($reveal, …)` conditional inside one class is a       ║
 * ║ single edit away from leaking the key to everyone, and the diff that     ║
 * ║ does it looks like a formatting change.                                  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── `show_answers` IS STILL EMITTED HERE, AND IT IS NOT ALWAYS FALSE. ─────┐
 * │ It reports the DECISION, and the decision can be "reveal" while the key  │
 * │ is still absent — an exam whose `question_group_id` is NULL has no key   │
 * │ to send. backend/ has exactly this case (`$reveal && $question_group_id`)│
 * │ and emits `show_answers: true` with no `correct_answers`. Hard-coding    │
 * │ false here would misreport it.                                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * @mixin ExamAttempt
 */
final class ExamResultResource extends JsonResource
{
    public function __construct(
        ExamAttempt $attempt,
        private readonly Exam $exam,
        private readonly bool $showAnswers,
    ) {
        parent::__construct($attempt);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ExamResultPayload::of($this->resource, $this->exam, $this->showAnswers);
    }
}
