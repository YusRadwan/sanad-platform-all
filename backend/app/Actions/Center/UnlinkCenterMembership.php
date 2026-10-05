<?php

declare(strict_types=1);

namespace App\Actions\Center;

use App\Models\CenterCourseActivation;
use App\Models\CenterMembership;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * "فك الربط" — the ONE place a center membership is ever deleted, called by
 * both the admin route (CenterCodeController::unlinkMembership) and the
 * teacher-scoped twin (CenterReportController::unlinkStudent).
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ DELETING THE MEMBERSHIP ROW ALONE IS NOT ENOUGH.                        │
 * │                                                                          │
 * │ `center_course_activations` (open decision 10.30, "تفعيل كورس سنتر") is  │
 * │ a SEPARATE, additive grant — App\Policies\Concerns\ResolvesCourseOwnership│
 * │ ::hasCourseActivation() checks it directly and does not care whether a   │
 * │ `center_memberships` row still exists. Before this action existed, both  │
 * │ controllers only ran `$membership->delete()`: the student vanished from  │
 * │ every LISTING built by iterating memberships (my-centers, reports), but  │
 * │ any course activation this teacher had already granted them kept working│
 * │ — a silent access leak, not a display bug. Deleting both rows in the     │
 * │ same transaction is what actually "cancels the student from the center  │
 * │ system" (open decision 10.34), matching JoinCenter's own guarantee that  │
 * │ a fresh join starts from a clean slate.                                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Scoped by `teacher_id` from the membership row itself, never the caller's
 * input — a teacher's other memberships/activations (with OTHER teachers)
 * are untouched, exactly as CenterMembership's own UNIQUE(student_id,
 * teacher_id) implies.
 */
final class UnlinkCenterMembership
{
    public function __invoke(CenterMembership $membership): void
    {
        $studentId = $membership->student_id;
        $teacherId = $membership->teacher_id;

        DB::transaction(function () use ($membership, $studentId, $teacherId): void {
            CenterCourseActivation::query()
                ->where('teacher_id', $teacherId)
                ->where('student_id', $studentId)
                ->delete();

            $membership->delete();
        });

        SecurityLog::warning('Center membership unlinked', [
            'event' => 'center_membership.unlinked',
            'teacher_id' => $teacherId,
            'student_id' => $studentId,
        ]);
    }
}
