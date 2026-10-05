<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE FOUR REFUSAL SHAPES OF THE COURSE SURFACE, WRITTEN ONCE.            ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Stage 5 lands sixteen routes that between them ask the same four questions
 * over and over:
 *
 *   findOrFail()        does this course exist and is it not trashed?
 *   assertManageable()  may the caller WRITE to it?
 *   assertVisible()     may the caller SEE it at all? (decision 3.6)
 *   parentCourseOf()    which course owns this section / group?
 *
 * backend/ answers them in NINE hand-written copies across CourseService,
 * SectionService and GroupService — the duplication Stage 3's
 * ResolvesCourseOwnership already ended for the POLICY half. This class is the
 * HTTP half: the policy decides yes/no, this decides which exception carries
 * which literal string, and the freeze table is the specification for both.
 *
 * ┌── WHY NOT $this->authorize(), THE OBVIOUS LARAVEL ANSWER ────────────────┐
 * │ Two independent reasons, and both are contract-level:                    │
 * │                                                                          │
 * │ 1. WRONG MESSAGE. authorize() raises AuthorizationException, which       │
 * │    App\Exceptions\Handler flattens to the generic 'Forbidden' — right    │
 * │    status, wrong body. Freeze-table rows 13-19 and 75-77 pin the literal │
 * │    'You do not own this course'. Only an exception implementing          │
 * │    App\Exceptions\PublicMessage survives Handler, and                     │
 * │    App\Exceptions\ForbiddenException is that.                             │
 * │                                                                          │
 * │ 2. WRONG STATUS ON THE PUBLIC ROUTES. Rows 8, 11 and 15 are DEGRADE      │
 * │    rows, and the freeze table forbids authorize() on every one of them   │
 * │    in bold: it throws 403 at a logged-out storefront visitor, which is   │
 * │    not "protection" but an outage of the public catalogue.               │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class CourseAccess
{
    /** backend/'s literal strings. All four are part of the API contract. */
    public const COURSE_NOT_FOUND = 'Course not found';

    public const SECTION_NOT_FOUND = 'Section not found';

    public const GROUP_NOT_FOUND = 'Group not found';

    public const NOT_OWNER = 'You do not own this course';

    /**
     * Resolve a course id, or 404 with backend/'s message.
     *
     * NOT implicit route-model binding: binding's own NotFoundHttpException
     * carries "No query results for model [App\Models\Course] {id}", which
     * Handler genericises to 'Not found' precisely because it discloses
     * internals — so the contract string would be lost either way. Stage 4's
     * TeacherController resolves by hand for the same reason.
     *
     * SOFT DELETES NEED NO CLAUSE HERE: Course's global scope already excludes
     * `deleted_at IS NOT NULL`, which is exactly the `AND c.deleted_at IS NULL`
     * backend/CourseRepository::findById() had to remember to write.
     */
    public static function findOrFail(?string $id): Course
    {
        return Course::query()->whereKey((string) $id)->first()
            ?? throw new NotFoundException(self::COURSE_NOT_FOUND);
    }

    /**
     * The WRITE gate — 403 'You do not own this course'.
     *
     * `Gate::denies` keeps Stage 3's admin bypass (Gate::before) in play. A
     * hand-written `$course->teacher_id !== $user->id` comparison would lose
     * it silently, which is exactly the nine-copies problem this stage is
     * closing rather than reopening.
     *
     * The ability is CoursePolicy::manage, declared `User $user` — non-nullable
     * — so a guest is structurally denied. Every route that calls this also
     * carries `auth`, so that path is unreachable in practice; the type is the
     * belt to the middleware's braces.
     */
    public static function assertManageable(Course $course): void
    {
        if (Gate::denies('manage', $course)) {
            throw new ForbiddenException(self::NOT_OWNER);
        }
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ DECISION 3.6, IMPLEMENTED. THE READ GATE FOR THE PUBLIC SURFACE.    ║
     * ║                                                                      ║
     * ║ An unpublished course is invisible to everyone except its owner and  ║
     * ║ an admin — and the refusal is **404, never 403**.                    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ┌── WHY 404 AND NOT 403 ───────────────────────────────────────────────┐
     * │ 403 on a guessed id is a POSITIVE ORACLE: it confirms the id names a │
     * │ real, unreleased course, which lets a competitor enumerate an        │
     * │ instructor's unpublished catalogue one id at a time without ever      │
     * │ seeing its contents. 404 is indistinguishable from a wrong guess.     │
     * │ Same argument, same mechanism, as App\Support\Authorization\DenyMode  │
     * │ — and DenyMode's own class docblock is where it is argued at length.  │
     * │                                                                      │
     * │ DenyMode is not CALLED here on purpose. Its docblock is explicit that │
     * │ it must be reached only through authorize(), because the exception    │
     * │ escapes Gate::allows() (open decision 3.5) — and this method's whole  │
     * │ point is that it does NOT use authorize(). The refusal is therefore   │
     * │ raised directly, with the same NotFoundException DenyMode itself      │
     * │ throws, so the wire behaviour is identical and the structural         │
     * │ constraint 3.5 records is respected rather than worked around.        │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ┌── `Gate::forUser(...)->allows()`, AND **NEVER** `?->can() ?? false`. ┐
     * │ OPEN DECISION 3.7, AND THE REASON IT IS AN OPEN DECISION.            │
     * │                                                                      │
     * │ The two are not synonyms. `?->` short-circuits on the **USER**, not  │
     * │ on the ability: for a logged-out caller `$request->user()?->can(…)`  │
     * │ answers false BEFORE any policy is consulted — even though           │
     * │ CoursePolicy::view() is declared `?User $user` and structurally       │
     * │ admits guests. The result on a PUBLISHED course would be a 404 for    │
     * │ every logged-out visitor: a silent, fail-closed outage of the entire  │
     * │ public catalogue that no test written for an authenticated actor      │
     * │ would ever catch.                                                    │
     * │                                                                      │
     * │ Measured, not assumed:                                               │
     * │ CourseIsolationMatrixTest::test_the_null_safe_can_idiom_silently_    │
     * │ denies_a_guest_a_published_course pins the difference and fails if    │
     * │ the two idioms ever agree.                                           │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ┌── THIS RULE CASCADES, AND SESSION A APPLIES IT TO SECTIONS TOO. ─────┐
     * │ Hiding a draft course while its syllabus stays readable at           │
     * │ GET /courses/{id}/sections would hide nothing. Session C inherits the │
     * │ same obligation for the /videos and /content-items families.          │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public static function assertVisible(?User $user, Course $course): void
    {
        if (!Gate::forUser($user)->allows('view', $course)) {
            throw new NotFoundException(self::COURSE_NOT_FOUND);
        }
    }

    /**
     * A section's or group's parent course — or 404 'Course not found'.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THIS IS THE SOFT-DELETED-COURSE EDGE CASE, AND IT IS WHY THE METHOD  ║
     * ║ EXISTS AT ALL RATHER THAN BEING `$section->course` AT EACH CALL SITE.║
     * ║                                                                      ║
     * ║ Deleting a course writes `courses.deleted_at` and DELIBERATELY LEAVES ║
     * ║ its sections and groups in place — backend/CourseService::delete()    ║
     * ║ says so ("sections and content items are intentionally left in        ║
     * ║ place"), and Stage 5 preserves it rather than adding a cascade.       ║
     * ║                                                                      ║
     * ║ Consequence: `$section->course` is **null** for those rows, because   ║
     * ║ Course's SoftDeletes global scope applies to the relation too. A call ║
     * ║ site that dereferences it gets a TypeError — a 500 with a stack trace ║
     * ║ — where backend/SectionService::assertOwnsCourse() answered a clean   ║
     * ║ 404 'Course not found' (its `findById` filtered `deleted_at IS NULL`  ║
     * ║ and it threw NotFoundException on the miss).                          ║
     * ║                                                                      ║
     * ║ Pinned by SectionCrudTest::test_a_section_under_a_soft_deleted_       ║
     * ║ course_is_404_not_500 and its CourseGroup twin.                       ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public static function parentCourseOf(Section|CourseGroup $child): Course
    {
        return $child->course ?? throw new NotFoundException(self::COURSE_NOT_FOUND);
    }
}
