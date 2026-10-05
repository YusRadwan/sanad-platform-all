<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The SUBSCRIBED-STUDENT exam shape — and the owning instructor's, on the way
 * to StaffExamResource.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `question_group_id` IS **NOT** HERE, AND ITS ABSENCE IS APPROVED        ║
 * ║ DECISION #3 — THE ONE FIELD THIS WHOLE THREE-WAY SPLIT EXISTS FOR.      ║
 * ║                                                                          ║
 * ║ backend/ExamService::present() emits it to EVERY caller of               ║
 * ║ GET /exams/{id} and GET /courses/{id}/exams — routes that carry no       ║
 * ║ `auth` at all. So today a logged-out visitor who knows an exam id learns ║
 * ║ the UUID of the instructor's private question bank. That id is a tenant  ║
 * ║ identifier: it turns GET /question-groups/{id}/questions from a guess    ║
 * ║ into a targeted request, and it is the single value that would make a    ║
 * ║ future authorization slip on the bank surface exploitable rather than    ║
 * ║ theoretical.                                                             ║
 * ║                                                                          ║
 * ║ No student surface has any use for it — the paper is served by           ║
 * ║ GET /exams/{id}/questions, which resolves the bank server-side — so the  ║
 * ║ narrowest correct answer is not to emit it, and the way to make that     ║
 * ║ structural is a class with no line of code capable of writing the key.   ║
 * ║ THIS CLASS IS THAT. Do not add the key "for symmetry".                   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * NOT A SUBCLASS OF PublicExamResource. The five shared keys are re-declared
 * from scratch below. Read PublicExamResource's banner for why that duplication
 * is the point rather than a smell.
 *
 * ┌── THE NUMERIC CASTS ARE ExamService::present()'s, AND THAT IS A CHOICE. ─┐
 * │ Two legacy presenters disagreed about the SAME columns:                  │
 * │                                                                          │
 * │   ExamService::present()    (float) $row['pass_score']   -> 50.0         │
 * │   ContentItemService::…     the raw PDO value            -> "50.00"      │
 * │                                                                          │
 * │ DECIMAL comes back from PDO as a PHP string (measured —                  │
 * │ tests/Feature/PdoTypeCastingTest.php), which is why the two diverged.    │
 * │ Routing ContentItemResource's `detail` through the same factory as the   │
 * │ /exams routes — the backdoor this session closes — forces one answer.    │
 * │                                                                          │
 * │ FLOAT WINS, for a measured reason and not a stylistic one: the exam      │
 * │ pages read `pass_score`/`total_score` as NUMBERS off GET /exams/{id}     │
 * │ (they already were numbers there), while the content-item consumer       │
 * │ applies `Number()` on the way in — which accepts 50.0 exactly as it      │
 * │ accepted "50.00". Choosing the string instead would have changed the     │
 * │ type on the route where it was never a string. Recorded as a wire        │
 * │ difference in docs/open-decisions.md rather than passed over in silence. │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * @mixin Exam
 */
final class EnrolledExamResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Exam $exam */
        $exam = $this->resource;

        $item = $exam->contentItem;

        $passScore = $exam->getAttribute('pass_score');
        $totalScore = $exam->getAttribute('total_score');
        $allowedAttempts = $exam->getAttribute('allowed_attempts');

        return [
            'id' => $exam->getKey(),
            'content_item_id' => $exam->getAttribute('content_item_id'),
            'title' => $item?->getAttribute('title'),
            'course_id' => $item?->getAttribute('course_id'),
            'duration_minutes' => (int) $exam->getAttribute('duration_minutes'),
            // NULL IS A REAL VALUE ON ALL THREE, not a missing one:
            //   pass_score / total_score  the instructor left the field empty
            //   allowed_attempts          NULL means UNLIMITED **by design**
            //                             (migration 000021's note), which is
            //                             why it must not be coerced to 0 —
            //                             0 is forbidden by chk_exams_scores
            //                             and would read as "no attempts".
            'pass_score' => $passScore === null ? null : (float) $passScore,
            'total_score' => $totalScore === null ? null : (float) $totalScore,
            'allowed_attempts' => $allowedAttempts === null ? null : (int) $allowedAttempts,
            // Cast to boolean on the model (TINYINT(1) arrives as int 1|0).
            'show_answers' => (bool) $exam->getAttribute('show_answers'),
            'randomize_questions' => (bool) $exam->getAttribute('randomize_questions'),
            'lock_content' => (bool) $exam->getAttribute('lock_content'),
        ];
    }
}
