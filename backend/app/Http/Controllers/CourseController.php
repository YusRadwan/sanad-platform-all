<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Course\CreateCourse;
use App\Actions\Course\UpdateCourse;
use App\Enums\UserRole;
use App\Http\Requests\Course\DeleteCourseRequest;
use App\Http\Requests\Course\StoreCourseRequest;
use App\Http\Requests\Course\UpdateCourseRequest;
use App\Http\Resources\CourseGroupStructureResource;
use App\Http\Resources\CourseResource;
use App\Http\Resources\CourseStructureResource;
use App\Http\Responses\ApiResponse;
use App\Models\ContentItem;
use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\Section;
use App\Models\User;
use App\Support\ContentItemAccess;
use App\Support\CourseAccess;
use App\Support\Pagination;
use App\Support\SecurityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Courses — freeze-table rows 8, 9, 11, 12, 13, 14, 31 and 41.
 *
 * Port of backend/app/Http/Controllers/CourseController.php +
 * CourseService's read/write halves. Thin by design: shape validation and the
 * refusal ladder in the Form Requests, field semantics in
 * App\Actions\Course\ApplyCourseAttributes, response shape in CourseResource,
 * the four refusal strings in App\Support\CourseAccess. What remains here is
 * HTTP wiring, the list scopes, and the Arabic success messages — which ARE a
 * contract and are therefore all visible on one screen.
 */
final class CourseController extends Controller
{
    /**
     * GET /api/v1/courses — the PUBLIC storefront catalogue. Row 8.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE SCOPE FILTERS. THE ABILITY DOES NOT. AND `view` IS NEVER CALLED  ║
     * ║ PER ROW HERE.                                                        ║
     * ║                                                                      ║
     * ║ CoursePolicy::viewAny() returns true for everyone including guests —  ║
     * ║ an ability is a yes/no question about an ACTION and cannot express    ║
     * ║ "these rows but not those". Evaluating `view` per row would also be   ║
     * ║ an N+1 policy call on the busiest read in the application.            ║
     * ║                                                                      ║
     * ║ So Course::scopePublished() carries the rule instead — which means    ║
     * ║ the same rule now exists in two places, and the ONLY thing keeping    ║
     * ║ them honest is CourseVisibilityTest::test_the_list_and_the_single_    ║
     * ║ read_never_disagree: anything this list omits must also 404 at        ║
     * ║ GET /courses/{id}, and anything it shows must be individually         ║
     * ║ readable by the same caller.                                         ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ┌── DEFAULT per_page IS 12, NOT Pagination::DEFAULT_PER_PAGE (20). ────┐
     * │ backend/CourseController::index() clamps                             │
     * │   min(100, max(1, (int) $request->query('per_page', 12)))            │
     * │ — twelve, because the storefront grid is 3x4. Pagination::fromRequest│
     * │ hard-codes the ADMIN default of 20, so this route passes its own.    │
     * │ The CLAMP (the security property) is shared; only the default is not.│
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', '1'));
        $perPage = min(Pagination::MAX_PER_PAGE, max(1, (int) $request->query('per_page', '12')));

        $query = $this->withCourseRelations(Course::query()->published())
            ->orderByDesc('created_at');

        $total = (clone $query)->getQuery()->getCountForPagination();

        return ApiResponse::success([
            'items' => CourseResource::collection($query->forPage($page, $perPage)->get()),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * GET /api/v1/courses/{id} — PUBLIC. Row 11, and the home of DECISION 3.6.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ AN UNPUBLISHED COURSE IS **404** HERE FOR EVERYONE BUT ITS OWNER AND ║
     * ║ AN ADMIN. THIS IS A DELIBERATE, USER-APPROVED TIGHTENING OF          ║
     * ║ backend/'s BEHAVIOUR — NOT A PORT.                                    ║
     * ║                                                                      ║
     * ║ backend/CourseService::find() → CourseRepository::findById() queries  ║
     * ║ `WHERE c.id = :id AND c.deleted_at IS NULL` with NO mention of        ║
     * ║ `is_published`, on a route with no auth middleware. Today, therefore, ║
     * ║ ANY visitor holding the id of an unreleased course reads the whole    ║
     * ║ row: title, description, price, teacher. Open decision 3.6 recorded   ║
     * ║ that and asked for an explicit choice; the choice was (أ), tighten.   ║
     * ║                                                                      ║
     * ║ The refusal is 404 rather than 403 because a 403 on a guessed uuid    ║
     * ║ confirms the id names a real unreleased course — a positive oracle    ║
     * ║ for enumerating a competitor's catalogue. See                         ║
     * ║ App\Support\CourseAccess::assertVisible(), which also explains why    ║
     * ║ the check is `Gate::forUser(...)->allows()` and never                 ║
     * ║ `$request->user()?->can(...)` (open decision 3.7).                    ║
     * ║                                                                      ║
     * ║ COST, stated plainly: a shared link to a draft course now 404s        ║
     * ║ instead of rendering. That is the accepted price.                     ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $course = CourseAccess::findOrFail($id);

        CourseAccess::assertVisible($request->user(), $course);

        return ApiResponse::success(new CourseResource($this->loadRelations($course)));
    }

    /**
     * GET /api/v1/courses/{id}/structure — PUBLIC. Row 10.
     *
     * The course TABLE OF CONTENTS: `{ sections: [ { id, title, order_index,
     * isHidden, lessons: [...] } ] }`. CourseDetailsPage.tsx and
     * CourseStructureManagement.tsx both transform it as
     * `sections.map(s => ({ id, title, isHidden, items: s.lessons }))`, so the
     * two-level envelope and the `lessons` key are contract.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ NO `detail` KEY, FOR ANYBODY — NOT THE OWNER, NOT AN ADMIN.          ║
     * ║ No video_url, no note body, no link target, no exam configuration.   ║
     * ║ The argument is on CourseStructureResource, which is a THIRD         ║
     * ║ Resource class for exactly this reason and must stay one.            ║
     * ║                                                                      ║
     * ║ Playback still has to go through GET /lessons/{id}/video, which      ║
     * ║ enforces the subscription gate. This endpoint hands out titles.      ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ┌── ONE QUERY FOR THE WHOLE TREE. THIS IS A PORTED OPTIMISATION. ──────┐
     * │ backend/ went out of its way to avoid the obvious loop — its comment │
     * │ says "One query for the whole course instead of one per section —    │
     * │ this endpoint is public and hit on every course page view."          │
     * │ listByCourseGroupedBySection() fetches every item once and buckets   │
     * │ them in PHP.                                                         │
     * │                                                                      │
     * │ The Eloquent equivalent is nested eager loading: three queries TOTAL │
     * │ (course, sections, items) regardless of how many sections exist. The │
     * │ naive `foreach ($course->sections as $s) { $s->contentItems }` is    │
     * │ 2 + N and looks identical at a glance.                               │
     * │                                                                      │
     * │ Pinned by CourseStructureTest::test_the_whole_tree_costs_a_constant_ │
     * │ number_of_queries, which builds SIX sections and asserts the count   │
     * │ does not move.                                                       │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ┌── GROUPS ARE A SEPARATE `groups` ARRAY, NOT MERGED INTO `sections`. ──┐
     * │ Open decision 10.21 REVERSES the prior "groups never appear" rule —  │
     * │ see the class-level history below — but they still are not section   │
     * │ rows: `course_groups` has a REAL `is_hidden`/`start_at`/`end_at`,     │
     * │ while a section's `isHidden` is synthetic. Merging the two shapes     │
     * │ into one array would either fabricate scheduling for sections or      │
     * │ silently drop the group's real gate columns from the response.       │
     * │ CourseGroupStructureResource is `CourseStructureResource`'s written-  │
     * │ out-literally twin for exactly this reason.                          │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ┌── A GROUP'S OWN VISIBILITY IS `isCurrentlyVisible()`, NOT JUST ──────┐
     * │ `maySeeHidden()`. A section has no equivalent gate at all (there is  │
     * │ no `sections.is_hidden`), so `maySeeHidden()` alone was always       │
     * │ enough for the lesson-level filter. A group additionally carries a   │
     * │ REAL schedule, so an owner/admin bypasses the whole check (they may  │
     * │ always preview a hidden/scheduled group, same privilege as a hidden  │
     * │ lesson), and everyone else only sees a group that is unhidden AND    │
     * │ inside its start/end window RIGHT NOW. See CourseGroup::             │
     * │ isCurrentlyVisible().                                                │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * DECISION 3.6 CASCADES: a draft course's outline is 404 for anyone but
     * its owner and an admin — the same assertVisible() as show() above, and
     * the same reason. Hiding the course row while its syllabus stays readable
     * would hide nothing.
     *
     * PUBLIC and therefore `authorize()` IS FORBIDDEN (a DEGRADE row), and the
     * hidden-item filter uses `Gate::forUser()` via ContentItemAccess, never
     * `?->can()` — decision 3.7.
     *
     * HIDDEN ITEMS ARE FILTERED, NOT REFUSED, exactly as in the section
     * listing — and a SUBSCRIBED STUDENT IS FILTERED TOO, because
     * maySeeHidden() is owner/admin only.
     */
    public function structure(Request $request, string $id): JsonResponse
    {
        $course = CourseAccess::findOrFail($id);

        CourseAccess::assertVisible($request->user(), $course);

        $course->load(['sections.contentItems', 'groups.contentItems']);

        $maySeeHidden = ContentItemAccess::maySeeHidden($request->user(), $course);

        $sections = $course->sections->map(
            static fn (Section $section): CourseStructureResource => new CourseStructureResource(
                $section,
                $section->contentItems
                    ->filter(static fn (ContentItem $item): bool => $maySeeHidden
                        || !$item->getAttribute('is_hidden'))
                    ->values()
                    ->all()
            )
        );

        $groups = $course->groups
            ->filter(static fn (CourseGroup $group): bool => $maySeeHidden || $group->isCurrentlyVisible())
            ->values()
            ->map(
                static fn (CourseGroup $group): CourseGroupStructureResource => new CourseGroupStructureResource(
                    $group,
                    $group->contentItems
                        ->filter(static fn (ContentItem $item): bool => $maySeeHidden
                            || !$item->getAttribute('is_hidden'))
                        ->values()
                        ->all()
                )
            );

        return ApiResponse::success(['sections' => $sections, 'groups' => $groups]);
    }

    /**
     * GET /api/v1/courses/my-courses — row 9. THREE ROLES, THREE MEANINGS.
     *
     * courseService.getMyCourses() is called from two places with two
     * different intents, which is why one endpoint branches instead of two
     * endpoints existing:
     *
     *   student     CourseCard.tsx asks "am I subscribed to this course?" →
     *               their ENROLLED courses, via Course::scopeSubscribedBy(),
     *               which composes Subscription::scopeActive() and never
     *               re-writes that predicate inline.
     *   instructor  CourseStructureManagement.tsx picks a course to EDIT →
     *               the courses they OWN.
     *   admin       every course, unpublished included, capped at 500.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE INSTRUCTOR AND ADMIN BRANCHES INCLUDE **DRAFTS**, ALWAYS.        ║
     * ║ Decision 3.6 hides unpublished courses from OTHERS — never from the   ║
     * ║ owner, and never from an admin. Filtering these two branches through  ║
     * ║ anything visibility-shaped would make an instructor's dashboard read  ║
     * ║ "0 courses" the moment they unpublish one. Hence                      ║
     * ║ Course::scopeOwnedBy() (ownership) and NOT a visibility scope — the   ║
     * ║ distinction Course's own docblock draws in a box.                     ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * THE IDENTITY COMES FROM THE SESSION AND NOWHERE ELSE. There is no id in
     * the URL and none is read from the request, so one user can never
     * enumerate another's courses — the same structural property as Stage 4's
     * self-service routes.
     */
    public function myCourses(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = $this->withCourseRelations(Course::query())->orderByDesc('created_at');

        $courses = match ($user->role) {
            // "تفعيل كورس سنتر" (decision 10.35) — a course activated for
            // this student must open exactly like a subscribed one, so the
            // student's own course list is subscription OR activation, not
            // subscription alone. Wrapped in one `where` so the OR does not
            // leak out and loosen anything else already applied to $query.
            UserRole::Student => $query->where(
                fn (Builder $q) => $q->subscribedBy($user)->orWhere(
                    fn (Builder $q2) => $q2->activatedFor($user)
                )
            )->get(),
            // backend/ reuses its paginated listing with per_page 500 here;
            // the cap is preserved so an admin with a large catalogue cannot
            // turn this into an unbounded read.
            UserRole::Admin => $query->limit(500)->get(),
            default => $query->ownedBy($user)->get(),
        };

        return ApiResponse::success(['courses' => CourseResource::collection($courses)]);
    }

    /**
     * GET /api/v1/codes/student-courses — row 31.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ A LITERAL DUPLICATE OF myCourses(), REGISTERED TWICE ON PURPOSE.     ║
     * ║ DO NOT CONSOLIDATE OR REDIRECT EITHER PATH.                          ║
     * ║                                                                      ║
     * ║ Despite the "codes" segment there is no access-code table or flow     ║
     * ║ anywhere in this schema — backend/'s own controller comment says so   ║
     * ║ and the migrations agree. It is a LEGACY NAME for the student's       ║
     * ║ enrolment list, and CourseDetailsPage.tsx calls it (as                ║
     * ║ getMyEnrolledCourses) while CourseCard.tsx calls /courses/my-courses. ║
     * ║ Both paths are live in the shipped frontend.                          ║
     * ║                                                                      ║
     * ║ It delegates rather than duplicating the branch so the two can never  ║
     * ║ disagree about what a student is enrolled in — which is exactly the   ║
     * ║ reason backend/ served them from one method too. The only difference  ║
     * ║ is the route's middleware: this one is `role:student`.                ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function studentCourses(Request $request): JsonResponse
    {
        return $this->myCourses($request);
    }

    /**
     * GET /api/v1/admin/teachers/{id}/courses — row 41, DEFERRED FROM STAGE 4
     * and landed here.
     *
     * Stage 4 left a comment in routes/api.php explaining the deferral: the
     * handler is course logic, not teacher logic, and it must return the
     * teacher's DRAFTS — a decision that belongs with the rest of the course
     * surface.
     *
     * Deliberately a separate method from myCourses(): that one derives its
     * subject from the SESSION, which is the property that keeps it safe. This
     * one takes an explicit id and is therefore mounted only inside the
     * admin-only route group.
     *
     * DRAFTS INCLUDED, by Course::scopeOwnedBy() — an admin must be able to
     * issue an enrolment code for a course that is not published yet.
     */
    public function byTeacher(string $id): JsonResponse
    {
        $courses = $this->withCourseRelations(Course::query())
            // The id is a `teachers.user_id`; scopeOwnedBy takes a User, so the
            // column is compared directly rather than hydrating an account
            // that may not be an instructor at all. An unknown id yields an
            // empty list, exactly as backend/'s listByTeacher() did — never a
            // 404, so the admin picker renders an empty dropdown instead of
            // erroring.
            ->where('teacher_id', $id)
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success(['courses' => CourseResource::collection($courses)]);
    }

    /** POST /api/v1/courses -> 201. Row 12. */
    public function store(StoreCourseRequest $request, CreateCourse $create): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $course = $create($request, $actor);

        return ApiResponse::created(
            new CourseResource($this->loadRelations($course)),
            'تم إنشاء الكورس بنجاح'
        );
    }

    /**
     * PUT /api/v1/courses/{id}. Row 13.
     *
     * The course is taken from the Form Request, which already resolved it
     * (404) and gated it (403) before validation ran — see
     * UpdateCourseRequest. Re-fetching here would be a second query for a row
     * already in memory.
     */
    public function update(UpdateCourseRequest $request, UpdateCourse $update): JsonResponse
    {
        $course = $update($request, $request->course());

        return ApiResponse::success(
            new CourseResource($this->loadRelations($course)),
            'تم تحديث الكورس بنجاح'
        );
    }

    /**
     * DELETE /api/v1/courses/{id} — SOFT. Row 14.
     *
     * ┌── THE ONLY AUDITED DELETE IN THIS SESSION, AND THAT ASYMMETRY IS ────┐
     * │ PORTED. backend/CourseService::delete() calls                         │
     * │ Logger::critical_operation('Course deleted', ['event' =>              │
     * │ 'course.deleted', …]) while SectionService::delete() and              │
     * │ GroupService::delete() write nothing at all. A course is the          │
     * │ tenant-visible asset; a section or group is an editing operation      │
     * │ inside one. Adding audit lines to the other two would look like an    │
     * │ improvement and would silently change what an auditor reading the     │
     * │ security channel sees.                                                │
     * │                                                                      │
     * │ SecurityLog::criticalOperation() writes to BOTH the app and security  │
     * │ channels, which is what Logger::critical_operation did.               │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * Sections and groups SURVIVE this delete on purpose — see
     * DeleteCourseRequest's docblock.
     */
    public function destroy(DeleteCourseRequest $request, string $id): JsonResponse
    {
        $course = $request->course();

        $course->delete();

        SecurityLog::criticalOperation('Course deleted', [
            'event' => 'course.deleted',
            'course_id' => $course->getKey(),
            'actor_role' => $request->user()?->getAttribute('role')?->value,
        ]);

        return ApiResponse::success(null, 'تم حذف الكورس بنجاح');
    }

    /**
     * The three relations CourseResource reads.
     *
     * Eager-loaded on EVERY course read in this controller, because the
     * resource dereferences them directly rather than through whenLoaded() —
     * see its docblock for why a forgotten load must become a slow query and
     * not a silently missing `grade_name`.
     *
     * `teacher.user` is two hops: `courses.teacher_id` → `teachers.user_id` →
     * `users`, and the display name/avatar live on the last one.
     *
     * @param Builder<Course> $query
     * @return Builder<Course>
     */
    private function withCourseRelations(Builder $query): Builder
    {
        return $query->with(['teacher.user', 'grade', 'subject']);
    }

    /** The same three relations, for a model already in hand. */
    private function loadRelations(Course $course): Course
    {
        return $course->load(['teacher.user', 'grade', 'subject']);
    }
}
