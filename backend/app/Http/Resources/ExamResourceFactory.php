<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Exam;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ONE PLACE THE THREE-WAY EXAM PAYLOAD DECISION IS MADE.              ║
 * ║ EVERY ROUTE THAT SERIALISES AN EXAM GOES THROUGH IT — INCLUDING         ║
 * ║ ContentItemResource's `detail` KEY. DO NOT INLINE IT BACK.              ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Four routes serialise an exam today:
 *
 *   GET /exams/{examId}                    ExamController::show
 *   GET /courses/{courseId}/exams          ExamController::indexByCourse
 *   GET /content-items/{id}                ContentItemResource::detail  ← the
 *   GET /sections/{sectionId}/content-items ContentItemResource::detail ← two
 *                                                                 BACKDOORS
 *
 * ┌── THE BACKDOOR, AND WHY CLOSING IT IS PART OF THIS SESSION. ─────────────┐
 * │ Before this session ContentItemResource wrote the `exams` columns out    │
 * │ BY HAND — `question_group_id` included — for anyone who passed           │
 * │ `consumeContent` on the course. So a subscribed student who fetched the  │
 * │ exam through /content-items/{id} received a field that approved decision │
 * │ #3 removes from /exams/{id}. Two doors to one row with two different key │
 * │ sets is not a "minor inconsistency": it is the SHAPE OF EVERY PAYLOAD    │
 * │ LEAK — the tightened route gets the review, the older one keeps          │
 * │ shipping. Routing the `detail` key through this factory makes the two    │
 * │ doors the same door.                                                     │
 * │                                                                          │
 * │ Pinned by ExamPayloadSplitTest::test_the_same_exam_has_the_same_key_set_ │
 * │ through_all_four_read_paths, which asks all four routes as the SAME      │
 * │ actor and compares key sets — for a guest, a subscriber, the owner and   │
 * │ an admin.                                                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── `Gate::forUser($user)->allows(…)`, AND **NEVER** `$user?->can(…)`. ────┐
 * │ OPEN DECISION 3.7, and the established convention for every DEGRADE row.│
 * │ `?->` short-circuits on the USER, not on the ability, so a logged-out    │
 * │ caller answers false BEFORE any policy is consulted. Both abilities used │
 * │ below happen to be owner-typed (`User`, non-nullable), so the two idioms │
 * │ agree HERE — which is exactly why the correct idiom must be written      │
 * │ anyway: a later widening of either signature to `?User` would otherwise  │
 * │ break this file silently and fail CLOSED, i.e. unnoticed.                │
 * │                                                                          │
 * │ Note the guard is `$user !== null && …` rather than relying on the Gate  │
 * │ alone. Gate::forUser(null) is legal and returns false for an owner-typed │
 * │ ability, so the guard is belt-and-braces — but it also documents that a  │
 * │ guest reaching this factory is an EXPECTED state, not an error.          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ORDER MATTERS AND IT IS STAFF-FIRST. The owning instructor passes BOTH
 * abilities (manage implies consumeContent for them), and an admin passes both
 * via Gate::before. Asking viewEnrolledDetail first would hand the owner the
 * student's shape and quietly drop `question_group_id` from the management UI
 * that needs it to prepopulate its bank picker.
 *
 * THIS FACTORY NEVER REFUSES. The un-entitled answer is a SMALLER 200, not a
 * 403 — rows 88/89 are DEGRADE rows and a storefront visitor is their intended
 * user. Existence (and the draft-course 404) is a SEPARATE question, asked
 * earlier by App\Support\AssessmentAccess::assertExamVisible().
 */
final class ExamResourceFactory
{
    /**
     * Pick the Resource CLASS this caller is entitled to for this exam.
     *
     * @param  \App\Models\User|null  $user  the acting user, or null for a guest
     */
    public static function for(?\App\Models\User $user, Exam $exam): JsonResource
    {
        if ($user !== null && Gate::forUser($user)->allows('viewStaffDetail', $exam)) {
            return new StaffExamResource($exam);
        }

        if ($user !== null && Gate::forUser($user)->allows('viewEnrolledDetail', $exam)) {
            return new EnrolledExamResource($exam);
        }

        return new PublicExamResource($exam);
    }
}
