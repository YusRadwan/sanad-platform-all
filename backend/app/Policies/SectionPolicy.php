<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Section;
use App\Models\User;
use App\Policies\Concerns\ResolvesCourseOwnership;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ IMPLEMENTED IN STAGE 5 / SESSION A. This was a throwing skeleton until   ║
 * ║ App\Models\Section became a real model.                                  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Ported shape from backend/app/Services/SectionService::assertOwnsCourse()
 * (~lines 80-89): the parent course must exist (404) and, for non-admins, must
 * belong to the caller (403 — never 404, so an existing section owned by
 * someone else is reported as forbidden rather than missing).
 *
 * ADMINS ARE ABSENT FROM THIS FILE, as from every policy: Gate::before
 * (App\Providers\AuthServiceProvider) has already returned true for them.
 *
 * ┌── THE REJECTION TEXT DOES NOT LIVE HERE — OPEN DECISION 3.4. ────────────┐
 * │ backend/ answers 'You do not own this course' from SectionService's own  │
 * │ guard, and the section-specific 'You do not own this section' only from  │
 * │ ContentItemService's section-scoped path. The freeze table (rows 17, 19) │
 * │ assigns the COURSE wording to this session's four section routes, and    │
 * │ App\Http\Controllers\SectionController throws                            │
 * │ App\Exceptions\ForbiddenException with that literal string. A policy     │
 * │ returning `false` produces Laravel's own AuthorizationException, which   │
 * │ App\Exceptions\Handler flattens to 'Forbidden' — correct as a status,    │
 * │ wrong as a contract. Session B inherits the other wording for            │
 * │ content-item routes; the two must not be unified on a whim.              │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
class SectionPolicy
{
    use ResolvesCourseOwnership;

    /**
     * Create/rename/reorder/delete a section. Owner of the parent COURSE only.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE NULL-COURSE BRANCH IS THE WHOLE REASON THIS METHOD IS NOT A      │
     * │ ONE-LINER. IT FAILS **CLOSED** AND MUST STAY THAT WAY.               │
     * │                                                                      │
     * │ `sections` has no `deleted_at` and no `teacher_id`. Soft-deleting a  │
     * │ course leaves every one of its sections in place (deliberate — see   │
     * │ Section::course()), and Course's SoftDeletes global scope then makes │
     * │ `$section->course` resolve to **null**. Without this guard the very  │
     * │ next line hands null to ownsCourse(), whose signature is             │
     * │ non-nullable, and the request dies as a TypeError — a 500 leaking a  │
     * │ stack trace where the contract owes a clean refusal.                 │
     * │                                                                      │
     * │ Returning false (not throwing, not "allow because there is nothing   │
     * │ to protect") is the fail-closed answer: an orphaned section belongs  │
     * │ to nobody, so nobody may manage it. The controller reaches the same  │
     * │ conclusion one layer up and turns it into backend/'s 404             │
     * │ 'Course not found'; this method is the second, independent lock —    │
     * │ correct even if a future call site forgets the first.                │
     * │                                                                      │
     * │ Pinned by SectionCrudTest::test_a_section_under_a_soft_deleted_      │
     * │ course_is_404_not_500.                                               │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function manage(User $user, Section $section): bool
    {
        $course = $section->course;

        if ($course === null) {
            return false;
        }

        return $this->ownsCourse($user, $course);
    }
}
