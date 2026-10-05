<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The UNENTITLED exam shape — guests, unsubscribed students, expired and
 * cancelled subscribers, and foreign instructors.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ ONE OF THREE STRUCTURALLY SEPARATE CLASSES. **NONE EXTENDS ANOTHER.**   ║
 * ║ THAT RULE IS THE ENTIRE SECURITY ARGUMENT OF THIS SESSION AND IT MUST   ║
 * ║ NOT BE "SIMPLIFIED" INTO INHERITANCE OR INTO `when()`.                  ║
 * ║                                                                          ║
 * ║   PublicExamResource     ← you are here. 5 keys.                        ║
 * ║   EnrolledExamResource     11 keys. Its own explicit list.               ║
 * ║   StaffExamResource        12 keys. Its own explicit list.               ║
 * ║                                                                          ║
 * ║ WHY NOT INHERITANCE. If Public extended nothing and Enrolled extended    ║
 * ║ Public, a field added to the parent would appear in every child — which  ║
 * ║ sounds convenient until the direction reverses: a field added to a       ║
 * ║ SHARED base for the staff view leaks to the public one, silently, with   ║
 * ║ no diff on the file anybody reviews. The freeze table has one such       ║
 * ║ column already (`question_group_id`), and Stage 5 reached this same      ║
 * ║ conclusion for ContentItemResource / PublicContentItemResource.          ║
 * ║                                                                          ║
 * ║ WHY NOT `$this->when($entitled, …)`. A conditional payload leaks the     ║
 * ║ moment its condition is evaluated wrongly — a stale flag, a truthiness   ║
 * ║ inversion, a Resource re-used on a route nobody threaded the flag        ║
 * ║ through. Three classes make the failure mode STRUCTURAL: this one has no ║
 * ║ code path — none, not one branch — that can reach `pass_score`,          ║
 * ║ `total_score`, `allowed_attempts`, `show_answers`,                       ║
 * ║ `randomize_questions`, `lock_content` or `question_group_id`. Those keys ║
 * ║ cannot appear because there is no line of code that could write them.    ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── WHAT IS EXPOSED, AND WHY EACH ONE IS SAFE. ────────────────────────────┐
 * │ id / content_item_id  opaque UUIDs the caller already holds or can       │
 * │                       correlate from the public syllabus.                │
 * │ title / course_id     already published by GET /courses/{id}/structure   │
 * │                       and by PublicContentItemResource.                  │
 * │ duration_minutes      "this exam lasts 45 minutes" is storefront copy —  │
 * │                       a purchase incentive, not paid content.            │
 * │                                                                          │
 * │ EVERYTHING ELSE IS GRADING CONFIGURATION and stays out: `pass_score` and │
 * │ `total_score` describe how a paying student is assessed, `show_answers`  │
 * │ and `allowed_attempts` describe the exam-taking contract, and            │
 * │ `question_group_id` names the instructor's private bank.                 │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * SELECTION IS NOT MADE HERE. App\Http\Resources\ExamResourceFactory is the one
 * place that chooses between the three, so the entitlement question is asked
 * identically at every call site — see that class.
 *
 * @mixin Exam
 */
final class PublicExamResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Exam $exam */
        $exam = $this->resource;

        $item = $exam->contentItem;

        return [
            'id' => $exam->getKey(),
            'content_item_id' => $exam->getAttribute('content_item_id'),
            // From `content_items`, not from `exams` — the exam row has no
            // title of its own. Null only for the soft-deleted-course orphan
            // case, which every read route 404s one rung earlier anyway.
            'title' => $item?->getAttribute('title'),
            'course_id' => $item?->getAttribute('course_id'),
            // (int): the column is NOT NULL with DEFAULT 30, and
            // ExamService::present() casts it. See EnrolledExamResource for
            // why the numeric casts here differ from ContentItemResource's
            // historical decimal-as-string shape.
            'duration_minutes' => (int) $exam->getAttribute('duration_minutes'),
        ];
    }
}
