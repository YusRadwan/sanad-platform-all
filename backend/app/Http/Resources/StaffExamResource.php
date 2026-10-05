<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The COURSE-STAFF exam shape — the owning instructor and admins.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ONLY CLASS IN THE APPLICATION THAT EMITS `question_group_id`.       ║
 * ║                                                                          ║
 * ║ That is a property worth being able to state as a fact, and it is        ║
 * ║ enforced by a test rather than by convention: ExamPayloadSplitTest       ║
 * ║ greps every Resource class for the key and asserts exactly one match.    ║
 * ║ If a future session needs the id on another surface, it adds a FOURTH    ║
 * ║ class and updates that test — it does not widen this one, and it does    ║
 * ║ not add the key to EnrolledExamResource.                                 ║
 * ║                                                                          ║
 * ║ Reached only through ExamResourceFactory::for(), i.e. only when          ║
 * ║ Gate::forUser($user)->allows('viewStaffDetail', $exam) — `manage` on the ║
 * ║ owning course. A subscribed student fails that while passing            ║
 * ║ viewEnrolledDetail on the same exam in the same request; that gap is the ║
 * ║ single most-repeated caution in this rewrite.                            ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * NOT A SUBCLASS of either sibling. All twelve keys are declared here from
 * scratch — see PublicExamResource's banner for why.
 *
 * The numeric casts match EnrolledExamResource's exactly (float / int / bool),
 * which is what makes the cross-route consistency guarantee a statement about
 * TYPES as well as key sets. See that class for the measured reason floats
 * won over the legacy decimal-as-string shape.
 *
 * @mixin Exam
 */
final class StaffExamResource extends JsonResource
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
            'pass_score' => $passScore === null ? null : (float) $passScore,
            'total_score' => $totalScore === null ? null : (float) $totalScore,
            'allowed_attempts' => $allowedAttempts === null ? null : (int) $allowedAttempts,
            'show_answers' => (bool) $exam->getAttribute('show_answers'),
            'randomize_questions' => (bool) $exam->getAttribute('randomize_questions'),
            'lock_content' => (bool) $exam->getAttribute('lock_content'),
            // ── THE STAFF-ONLY FIELD. Nothing else on this class differs. ──
            'question_group_id' => $exam->getAttribute('question_group_id'),
        ];
    }
}
