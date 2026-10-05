<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Course;
use App\Models\User;
use App\Policies\Concerns\ResolvesCourseOwnership;

/**
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ FOUR ABILITIES, BECAUSE THERE ARE FOUR DIFFERENT QUESTIONS.             │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * The temptation is to collapse manage / consumeContent / viewHidden into one
 * `view`. They are not the same question, and the proof is a single actor: a
 * student with a live subscription.
 *
 *   ability          owner  subscribed student  other instructor  guest
 *   ───────────────  ─────  ──────────────────  ────────────────  ─────
 *   viewAny          yes    yes                 yes               yes
 *   view (published) yes    yes                 yes               yes
 *   view (draft)     yes    NO                  NO                NO
 *   consumeContent   yes    YES                 no                n/a
 *   viewHidden       yes    NO                  no                n/a
 *   manage           yes    no                  no                n/a
 *
 * The subscribed-student column is the whole argument: consumeContent=yes,
 * viewHidden=no, manage=no — three different answers for one actor on one
 * course. Any merge of the three abilities has to pick one of those answers
 * and is therefore wrong twice. Pinned by
 * tests/Unit/Authorization/CoursePolicyTest::
 * test_the_three_abilities_are_independently_distinguishable_for_a_subscribed_student.
 *
 * ADMINS ARE ABSENT FROM THIS FILE. Gate::before (App\Providers\
 * AuthServiceProvider) has already returned true for them before any method
 * here is entered. Adding `if ($user->role === UserRole::Admin)` anywhere below
 * would be a second copy of a security rule that must have exactly one.
 *
 * NO 404-MODE DENIALS HERE, deliberately. App\Support\Authorization\DenyMode
 * exists for resources whose very EXISTENCE is a secret. A course is not one:
 * an unpublished course belongs to a known instructor and is reached by an id
 * the caller already had, so 403 is the honest answer and reveals nothing that
 * the public catalogue does not. Hidden CONTENT ITEMS (Stage 5) are the real
 * DenyMode case.
 */
class CoursePolicy
{
    use ResolvesCourseOwnership;

    /**
     * May the caller ask for a LIST of courses at all?
     *
     * ALWAYS TRUE, INCLUDING FOR GUESTS — and written out rather than omitted,
     * because an absent `viewAny` is indistinguishable from a forgotten one at
     * review time, while an explicit `return true` carrying this docblock is a
     * decision on the record.
     *
     * ┌── THIS IS NOT THE FILTERING MECHANISM. ──────────────────────────────┐
     * │ WHICH courses a caller sees is decided by a QUERY SCOPE on the        │
     * │ listing (published-only for the public catalogue, own-courses for an  │
     * │ instructor's dashboard), never by this ability. An ability is         │
     * │ yes/no about an ACTION; it cannot express "these rows but not those". │
     * │ Trying to make viewAny do the filtering is how a listing endpoint     │
     * │ ends up returning every teacher's drafts to whoever passed the gate.  │
     * │ Stage 4 owns that scope; Session 2's freeze table records which       │
     * │ listing routes rely on it.                                            │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * `?User $user` — the nullable hint is what tells Laravel's Gate this
     * ability is reachable by a guest (Gate::canBeCalledWithUser() consults the
     * parameter's nullability via methodAllowsGuests()). Without it the Gate
     * short-circuits to false for an unauthenticated caller instead of calling
     * this method, and the public catalogue 403s.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * May the caller see this ONE course's record?
     *
     * Published is public — the storefront course page renders logged-out, the
     * same rule routes/api.php already applies to /curricula. Everything else
     * is the owner alone (plus admins, upstream).
     *
     * ORDER MATTERS AND IS FAIL-CLOSED: the published check comes first because
     * it is the only branch that may admit a guest; the explicit `$user ===
     * null` rejection then runs BEFORE ownsCourse() is ever reached, so that
     * method keeps its non-nullable User parameter and there is no path on
     * which a null user reaches an ownership comparison.
     *
     * SOFT-DELETED COURSES NEVER GET HERE. Course uses SoftDeletes, so a
     * trashed row is not resolvable by route-model binding or an ordinary
     * query and the request is a 404 before authorization runs. If a future
     * caller deliberately uses withTrashed(), it owes its own check — this
     * policy does not look at deleted_at and must not be assumed to.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE PUBLIC BRANCH IS `isPubliclyVisible()`, **NOT** `is_published`.  ║
     * ║ A SUSPENDED INSTRUCTOR'S COURSE IS NOT PUBLIC.                       ║
     * ║                                                                      ║
     * ║ Suspending a teacher (`users.is_active = 0`) removed them from the   ║
     * ║ public directory via Teacher::scopePubliclyVisible() while leaving    ║
     * ║ every published course of theirs listed and individually readable,    ║
     * ║ carrying their name and avatar. Course::isPubliclyVisible() is the    ║
     * ║ row-at-a-time twin of Course::scopePublished(), so the list and this  ║
     * ║ single read still express ONE rule — the property                     ║
     * ║ CourseVisibilityTest::test_the_list_and_the_single_read_never_        ║
     * ║ disagree exists to hold.                                              ║
     * ║                                                                      ║
     * ║ IT IS CONFINED TO THIS BRANCH ON PURPOSE. The two lines below are     ║
     * ║ untouched, so the suspended instructor still reads their OWN course   ║
     * ║ through ownsCourse(), and an admin never arrives here at all —        ║
     * ║ Gate::before answered true first. Pushing the account check any       ║
     * ║ higher would lock a teacher out of the material an admin is           ║
     * ║ reviewing, which is a worse bug than the one it fixes.                ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function view(?User $user, Course $course): bool
    {
        if ($course->isPubliclyVisible()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        if ($this->ownsCourse($user, $course)) {
            return true;
        }

        /*
         * Center System: a `center`-visibility course is deliberately never
         * "publicly visible" (see Course::isPubliclyVisible()), so its own
         * center students need this explicit branch to open the course page
         * at all — same hasCenterAccess() the content-consumption gate uses.
         */
        if ($this->hasCenterAccess($user, $course)) {
            return true;
        }

        /*
         * "تفعيل كورس سنتر" (decision 10.30/10.35) — an activation grant is a
         * FOURTH, additive way into a course (see hasCourseActivation()'s own
         * docblock) and is NOT limited to `center`/`both` visibility courses.
         * Without this branch an activated PLATFORM course's `view` fails
         * before consumeContent() is ever reached — the student's very first
         * request (opening the course page) 404s, making the grant
         * unreachable even by direct URL, let alone actually "open".
         */
        return $this->hasCourseActivation($user, $course);
    }

    /**
     * May the caller EDIT, publish, delete or otherwise administer this course,
     * and everything hanging off it?
     *
     * Owner only. Rejection message for the eventual controller/handler:
     * 'You do not own this course'.
     *
     * `User` NOT `?User` — a guest has no business reaching a write ability,
     * and the non-nullable hint makes Laravel's Gate deny an unauthenticated
     * caller before the method body exists to be reasoned about. Fail closed by
     * type signature, not by an `if`.
     */
    public function manage(User $user, Course $course): bool
    {
        return $this->ownsCourse($user, $course);
    }

    /**
     * May the caller open the course's ordinary content — play a video, read a
     * note, sit an exam?
     *
     * Owner, or a STUDENT with a live subscription. Rejection message:
     * 'You are not enrolled in this course'.
     *
     * NOTE this is the paying student's ability and it is genuinely wider than
     * manage(). See canConsume() for why the role is re-checked alongside the
     * subscription row.
     */
    public function consumeContent(User $user, Course $course): bool
    {
        return $this->canConsume($user, $course);
    }

    /**
     * May the caller see the instructor's HIDDEN/draft material inside this
     * course?
     *
     * OWNER ONLY — a fully paid-up, actively subscribed student MUST fail this
     * even though consumeContent() says yes for the same actor on the same
     * course. See ResolvesCourseOwnership::canSeeHidden() for the reasoning and
     * the test that pins it.
     */
    public function viewHidden(User $user, Course $course): bool
    {
        return $this->canSeeHidden($user, $course);
    }
}
