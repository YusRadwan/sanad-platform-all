<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CourseGroup;
use App\Models\User;
use App\Policies\Concerns\ResolvesCourseOwnership;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ IMPLEMENTED IN STAGE 5 / SESSION A, alongside the real                   ║
 * ║ App\Models\CourseGroup.                                                  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Ported shape from backend/app/Services/GroupService::assertOwnsCourse()
 * (~lines 78-86): a null course is 404, a foreign course is 403.
 *
 * REJECTION TEXT, verbatim from backend/: GroupService throws
 * 'You do not own this course' for EVERY group mutation — not
 * 'You do not own this group', which appears only on the content-item routes
 * that hang off a group (freeze-table rows 78-79, Session B's scope). The
 * controller throws App\Exceptions\ForbiddenException with the course wording;
 * see SectionPolicy's docblock for why the string is not produced here.
 */
class CourseGroupPolicy
{
    use ResolvesCourseOwnership;

    /**
     * Create/rename/reorder/delete/hide a course group. Owner of the parent
     * COURSE only — `course_groups` carries no owner column of its own.
     *
     * The null-course branch is identical in shape and in reasoning to
     * SectionPolicy::manage()'s; read that one. Same schema fact underneath:
     * a soft-deleted course leaves its groups in place, so the relation is
     * legitimately null and an orphaned group belongs to nobody.
     *
     * The `view` ability Session A deferred now exists below — see it for why
     * it is a separate method even though the two bodies agree today.
     */
    public function manage(User $user, CourseGroup $group): bool
    {
        $course = $group->course;

        if ($course === null) {
            return false;
        }

        return $this->ownsCourse($user, $course);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ ADDED IN STAGE 5 / SESSION B, FOR FREEZE-TABLE ROW 78 ONLY:          ║
     * ║ `GET /groups/{groupId}/content-items`.                               ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * Session A declined to guess this signature ("Session B … will declare
     * the signature its own payload requires"). The payload turned out to be
     * the FULL, ENTITLED content-item list — `detail` and all, including
     * video_url and note bodies — because backend/ContentItemService::
     * listByGroup() calls presentMany() with the default `$entitled = true`
     * and never consults a subscription. So the question is "may this caller
     * read the instructor's own working set for this group", and today the
     * only correct answer is the owner (plus admins, upstream).
     *
     * ┌── WHY NOT JUST CALL manage()? ───────────────────────────────────────┐
     * │ The bodies are identical and the QUESTIONS ARE NOT. Row 78 is the    │
     * │ one group route that stays admin/instructor even in the FINAL        │
     * │ contract, but `course_groups.is_hidden` and the `is_hidden` flag on  │
     * │ each item make this a read surface whose rule could legitimately      │
     * │ widen (Session C weighs a subscriber's view of an unhidden group);    │
     * │ the WRITE rule never can. Collapsing them would mean a future         │
     * │ widening of the read silently widens delete.                          │
     * │                                                                      │
     * │ It is written out rather than `return $this->manage(...)` so that a   │
     * │ reader auditing "who may read a group's contents" finds the answer    │
     * │ here instead of a redirect.                                           │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * `User` and not `?User`: this route carries `auth` and always will in
     * this session. A guest is denied by the type signature, before any body
     * exists to be reasoned about.
     *
     * Rejection message, verbatim from backend/GroupService's content-item
     * path: 'You do not own this group' — NOT the course wording. See
     * App\Support\ContentItemAccess.
     */
    public function view(User $user, CourseGroup $group): bool
    {
        $course = $group->course;

        if ($course === null) {
            return false;
        }

        return $this->ownsCourse($user, $course);
    }
}
