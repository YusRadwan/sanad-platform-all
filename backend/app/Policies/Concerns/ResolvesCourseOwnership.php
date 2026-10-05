<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\UserRole;
use App\Models\CenterCourseActivation;
use App\Models\CenterMembership;
use App\Models\Course;
use App\Models\Subscription;
use App\Models\User;

/**
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE TENANT-ISOLATION ENGINE. FOUR QUESTIONS, ASKED IN EXACTLY ONE PLACE. │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * backend/ answers these four questions with NINE separate hand-written
 * implementations spread across its services — CourseService::assertOwnsCourse,
 * SectionService::assertOwnsCourse, GroupService::assertOwnsCourse,
 * ContentItemService::assertOwnsCourseId, LessonService::assertCanAccess,
 * ExamService::requireExamAccess, ExamService::isCourseStaff,
 * AssignmentService::requireOwnsCourse and ::requireCourseAccess. They agree
 * today by copy-paste and by luck; they are nine chances for one of them to
 * drift and leak one teacher's course to another. This trait is the end of
 * that.
 *
 * ┌── WHY A TRAIT AND NOT A BASE POLICY CLASS ───────────────────────────────┐
 * │ Not every policy routes through a Course. QuestionGroupPolicy owns via   │
 * │ `question_groups.teacher_id` and NotificationPolicy via                  │
 * │ `notifications.user_id` — neither has a Course anywhere in its chain.    │
 * │ A base class would hand both of them four methods they must never call   │
 * │ and an import of Course/Subscription they do not need. `use` is opt-in;  │
 * │ `extends` is not.                                                        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * EVERY METHOD HERE FAILS CLOSED. No nullable resource parameters, no default
 * arguments, no `?Course $course = null` that would let a caller accidentally
 * ask "does this user own nothing?" and get an answer. If a caller cannot
 * produce a real Course, it has no business asking these questions.
 *
 * ADMINS ARE NOT MENTIONED ANYWHERE IN THIS TRAIT — deliberately. The admin
 * bypass lives in exactly one place, `Gate::before()` in
 * App\Providers\AuthServiceProvider, and by the time any method below runs the
 * caller is known not to be an admin. Re-checking the role here would be a
 * second copy of a security rule, i.e. the exact problem this stage exists to
 * remove. (Read that provider's escape-hatch note before assuming the bypass
 * covers everything — it must NOT cover `submit`-type abilities.)
 */
trait ResolvesCourseOwnership
{
    /**
     * Is this user the instructor who owns this course?
     *
     * ┌── THE DOUBLE STRING CAST IS MANDATORY ON **BOTH** SIDES. ────────────┐
     * │                                                                      │
     * │ `courses.teacher_id` and `users.id` are both CHAR(36) UUIDs, so in   │
     * │ the happy path both sides are already strings and the casts look     │
     * │ redundant. They are not:                                             │
     * │                                                                      │
     * │  * `$user->id` is whatever the attribute currently holds. A model    │
     * │    hydrated from a route binding, a freshly `new User()` with an id  │
     * │    assigned by hand in a test, or a future model with a casted key   │
     * │    can each present a different PHP type.                            │
     * │                                                                      │
     * │  * `===` on mismatched types is ALWAYS false. Without the casts, a   │
     * │    genuine owner whose id arrived as an int, or as a Stringable      │
     * │    wrapper, is silently denied — a fail-CLOSED bug, but a real one.  │
     * │                                                                      │
     * │  * `==` would be the naive fix and is far worse: PHP's loose         │
     * │    comparison of a numeric-looking string against 0, or against      │
     * │    another numeric string, is the classic authentication-bypass      │
     * │    primitive. `===` on two explicit strings is the only form that is │
     * │    both type-safe and bypass-proof.                                  │
     * │                                                                      │
     * │ Proven in tests/Unit/Authorization/ResolvesCourseOwnershipTest.php   │
     * │ against string, int and Stringable ids on either side.               │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * NOTE `$course->teacher_id` is compared to `$user->id`, NOT to
     * `$user->teacher->user_id`. The two are the same value by schema
     * (teachers.user_id IS the PK and IS an FK to users.id — see the Course
     * model), and going through the relationship would add a query per check
     * without changing a single answer.
     */
    protected function ownsCourse(User $user, Course $course): bool
    {
        return (string) $course->teacher_id === (string) $user->id;
    }

    /**
     * Does this user hold a live enrollment in this course?
     *
     * The predicate itself is NOT written here — it is
     * Subscription::scopeActive(), the single canonical definition of
     * "subscribed" in this codebase. Read that scope's docblock before
     * touching this method; re-expressing the condition inline is precisely
     * the duplication Stage 3 exists to end.
     *
     * `exists()` and not `first()`/`count()`: it compiles to
     * `SELECT EXISTS(...)`, returns a bool, loads no row, and cannot tempt a
     * caller into reading subscription columns it was not authorised to see.
     *
     * The role is NOT checked here. This method answers a factual question
     * about a row; whether a non-student holding such a row should benefit
     * from it is canConsume()'s decision.
     */
    protected function hasActiveSubscription(User $user, Course $course): bool
    {
        return Subscription::query()
            ->where('student_id', $user->id)
            ->where('course_id', $course->id)
            ->active()
            ->exists();
    }

    /**
     * May this user consume the course's ordinary content — watch a lesson,
     * open a note, sit an exam?
     *
     * Two ways in, and only two:
     *   1. the owning instructor (previewing their own material), or
     *   2. a STUDENT with an active subscription.
     *
     * THE ROLE CHECK IN BRANCH 2 IS NOT DECORATION. `subscriptions.student_id`
     * is an FK into `students.user_id`, so only a real student profile can
     * legitimately hold a row — but an instructor account could in principle be
     * given one by a data-migration bug or a mis-scoped admin action. Requiring
     * the role as well means one bad row cannot promote an instructor into
     * another instructor's course.
     *
     * Admins never reach this method: Gate::before answered first.
     */
    protected function canConsume(User $user, Course $course): bool
    {
        if ($this->ownsCourse($user, $course)) {
            return true;
        }

        if ($user->role !== UserRole::Student) {
            return false;
        }

        return $this->hasActiveSubscription($user, $course)
            || $this->hasCenterAccess($user, $course)
            || $this->hasCourseActivation($user, $course);
    }

    /**
     * Center System — a third way in, alongside ownership and subscription.
     *
     * A student reaches a `center`/`both` course through a `center_memberships`
     * row with the course's OWNING teacher, no subscription/payment involved —
     * see Course::scopeCenterAccessibleBy(), which this re-expresses as an
     * `exists()` for the same reason hasActiveSubscription() does: no row is
     * loaded, only the fact is answered.
     */
    protected function hasCenterAccess(User $user, Course $course): bool
    {
        if (!\in_array($course->visibility, [Course::VISIBILITY_CENTER, Course::VISIBILITY_BOTH], true)) {
            return false;
        }

        return CenterMembership::query()
            ->where('student_id', $user->id)
            ->where('teacher_id', $course->teacher_id)
            ->exists();
    }

    /**
     * "تفعيل كورس سنتر" — a FOURTH, additive way in: a free, no-payment grant
     * of exactly this course to exactly this student, recorded by
     * App\Actions\Center\ActivateCourseForStudent/ActivateCourseForSession
     * (never by flipping `visibility`, which would open the course to EVERY
     * center student of that teacher, not just the ones actually granted
     * it). No visibility check here on purpose — the grant is course-
     * specific and stands on its own regardless of the course's visibility
     * value; ONLY the teacher who owns the course can create the row in the
     * first place (see those Actions), so this can never reach a foreign
     * teacher's course.
     */
    protected function hasCourseActivation(User $user, Course $course): bool
    {
        return CenterCourseActivation::query()
            ->where('student_id', $user->id)
            ->where('course_id', $course->id)
            ->exists();
    }

    /**
     * May this user see content the instructor has HIDDEN (`is_hidden = 1`),
     * and drafts that are not published?
     *
     * ┌── OWNERSHIP ONLY. A SUBSCRIBED STUDENT MUST FAIL THIS. ──────────────┐
     * │                                                                      │
     * │ This is the single most likely mistake in the whole authorization    │
     * │ layer, because canConsume() and canSeeHidden() are true for the same │
     * │ actor in the most common case (the owner) and differ only for the    │
     * │ paying student — who passes one and must fail the other.             │
     * │                                                                      │
     * │ Hidden is the instructor's WORKBENCH: a half-written lesson, an exam │
     * │ whose questions are not final, a section staged for next term.       │
     * │ Subscribing buys access to the published course, never to the drafts │
     * │ behind it. Widening this to canConsume() would expose unreleased     │
     * │ exam content to every enrolled student — quietly, with no error and  │
     * │ no log line.                                                         │
     * │                                                                      │
     * │ Pinned by                                                            │
     * │ ResolvesCourseOwnershipTest::test_a_fully_active_subscriber_still_   │
     * │ cannot_see_hidden_content, which is written specifically so that     │
     * │ "simplifying" this method to canConsume() fails the suite.           │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * It delegates to ownsCourse() rather than repeating the comparison, so
     * the string-cast rule above holds here too by construction.
     */
    protected function canSeeHidden(User $user, Course $course): bool
    {
        return $this->ownsCourse($user, $course);
    }
}
