<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;

/**
 * The ONE policy Stage 8 builds — and the last skeleton in the authorization
 * surface to graduate.
 *
 * Ported from backend/app/Services/AnnouncementService::assertOwns()
 * (~lines 122-130).
 *
 * ┌── NO ResolvesCourseOwnership, DELIBERATELY. ─────────────────────────────┐
 * │ Ownership is `announcements.teacher_id` DIRECTLY. `announcements.        │
 * │ course_id` exists and is nullable, but it scopes the AUDIENCE, not the   │
 * │ owner: a course-scoped announcement authored by teacher A does not       │
 * │ become teacher B's because the course moved. Same shape as               │
 * │ QuestionGroupPolicy.                                                     │
 * │                                                                          │
 * │ Course ownership IS still checked when a course_id is supplied on        │
 * │ create — but as a SEPARATE question, asked of CoursePolicy by            │
 * │ StoreAnnouncementRequest. Two owners, two checks, two failure modes      │
 * │ (404 'Course not found' / 403 'You do not own this course'), neither     │
 * │ folded into the other.                                                   │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class AnnouncementPolicy
{
    /**
     * Edit/delete an announcement. Its author only.
     *
     * Rejection message: 'Not your announcement'.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE NULL BRANCH IS THE DECISION (approved decision 3.2 / open        ║
     * ║ decision 8.3), AND IT IS WRITTEN OUT RATHER THAN INHERITED.          ║
     * ║                                                                      ║
     * ║ `announcements.teacher_id` IS NULLABLE, and backend-v2 writes NULL    ║
     * ║ on purpose when an ADMIN authors one — a PLATFORM ANNOUNCEMENT. (It   ║
     * ║ has to: the column is a FK into `teachers.user_id` and an admin has   ║
     * ║ no `teachers` row, which is why backend/ 500s on this exact request   ║
     * ║ today.)                                                              ║
     * ║                                                                      ║
     * ║ backend/ compares `$announcement['teacher_id'] !== $userId` with NO   ║
     * ║ null branch. That happens to 403 a non-admin — fail-closed, but by    ║
     * ║ accident. Here the accident is replaced by a statement:               ║
     * ║                                                                      ║
     * ║     teacher_id === null  ->  FALSE, for everyone who reaches this.    ║
     * ║                                                                      ║
     * ║ …and `false` is the whole answer precisely BECAUSE `Gate::before`     ║
     * ║ already returned `true` for an admin and this body was never          ║
     * ║ entered for them. "Admin-only" is therefore expressed by returning    ║
     * ║ false to everybody — the readable form of it, given the bypass. An    ║
     * ║ `if ($user->role === Admin) return true;` here would be dead code     ║
     * ║ that LOOKS like the security control, which is worse than none.       ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * The `(string)` casts on BOTH sides are the house rule since Stage 3:
     * both values are CHAR(36) uuids, but one arrives from an Eloquent
     * attribute and the other from the authenticated user, and `===` between
     * a string and anything else is silently false — which fails OPEN nowhere
     * but fails CLOSED everywhere, i.e. an owner locked out of their own row
     * with no error to read.
     */
    public function manage(User $user, Announcement $announcement): bool
    {
        $teacherId = $announcement->getAttribute('teacher_id');

        if ($teacherId === null) {
            return false;
        }

        return (string) $teacherId === (string) $user->getKey();
    }
}
