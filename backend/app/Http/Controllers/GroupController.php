<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Group\ManageCourseGroupRequest;
use App\Http\Requests\Group\StoreCourseGroupRequest;
use App\Http\Resources\CourseGroupResource;
use App\Http\Responses\ApiResponse;
use App\Models\CourseGroup;
use App\Models\User;
use App\Support\CourseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Course content groups — freeze-table rows 74-77.
 *
 * `course_groups`, not `question_groups`: see App\Models\CourseGroup for why
 * the model is not called `Group`. backend/'s GroupService/GroupController are
 * this table, and frontend/src/services/groupApiService.ts is its consumer.
 *
 * ┌── THE RESPONSE ENVELOPES ARE NESTED, AND THAT IS THE CONTRACT. ──────────┐
 * │   GET    -> data: { groups: [ … ] }                                      │
 * │   POST   -> data: { group: { … } }                                       │
 * │   PATCH  -> data: { group: { … } }                                       │
 * │   DELETE -> data: {}                                                     │
 * │ backend/GroupController wraps every payload in that extra key. A bare    │
 * │ array would be a different body for the same request.                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class GroupController extends Controller
{
    /**
     * GET /api/v1/groups — row 74. A LIST SCOPE, not an ability.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ INSTRUCTORS SEE THEIR OWN GROUPS; ADMINS SEE ALL. THE BRANCH LIVES   ║
     * ║ HERE AND NOT INSIDE THE SCOPE.                                        ║
     * ║                                                                      ║
     * ║ CourseGroup::scopeOwnedBy() expresses ONE predicate — "this teacher's ║
     * ║ courses' groups". Teaching it to return EVERYTHING for one role would ║
     * ║ hide a privilege rule inside a query builder, where no one auditing   ║
     * ║ authorization would look for it. Stage 3's whole argument is that a   ║
     * ║ security rule must live in exactly one visible place.                 ║
     * ║                                                                      ║
     * ║ An ability cannot do this job at all: `viewAny` is a yes/no question  ║
     * ║ about an ACTION and cannot say "these rows but not those". The freeze ║
     * ║ table pins the same conclusion for all five list routes.              ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * Soft-deleted courses drop out of the instructor branch automatically —
     * scopeOwnedBy() runs its predicate against the Course MODEL, so
     * SoftDeletes supplies `deleted_at IS NULL` inside the subquery. The ADMIN
     * branch is unscoped, exactly as backend/GroupRepository::listAll() was, so
     * it does still include groups whose course was trashed; that is ported
     * behaviour, not an oversight, and it is what makes an admin able to see
     * orphans at all.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = CourseGroup::query()->orderBy('order_index')->orderBy('id');

        if ($user->role !== UserRole::Admin) {
            $query->ownedBy($user);
        }

        return ApiResponse::success([
            'groups' => CourseGroupResource::collection($query->get()),
        ]);
    }

    /**
     * POST /api/v1/groups -> 201. Row 75.
     *
     * ┌── THE GATE IS HERE, NOT IN THE FORM REQUEST, AND THAT IS ORDERING. ──┐
     * │ backend/GroupService::create() validates FIRST (the combined          │
     * │ 'course_id والعنوان مطلوبان' 422), then resolves the course (404),    │
     * │ then checks ownership (403). Laravel runs a Form Request's            │
     * │ authorize() BEFORE its rules, so gating in                            │
     * │ StoreCourseGroupRequest would invert the first two and turn a         │
     * │ malformed body from a foreign instructor into a 403 where it used to  │
     * │ be a 422.                                                             │
     * │                                                                      │
     * │ Contrast the SECTION routes, where backend/ checks ownership first    │
     * │ and the gate therefore belongs in the Form Request. The order is not  │
     * │ a house style; it is read off each legacy service, one at a time.     │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * `order_index` is assigned by the same unlocked MAX()+1 as sections —
     * approved decision #4 leaves the race alone. See SectionController.
     */
    public function store(StoreCourseGroupRequest $request): JsonResponse
    {
        $course = CourseAccess::findOrFail((string) $request->input('course_id'));

        CourseAccess::assertManageable($course);

        $group = DB::transaction(fn (): CourseGroup => CourseGroup::query()->create([
            'course_id' => $course->getKey(),
            'title' => (string) $request->input('title'),
            // `?? false` and not boolean(): backend/ passes
            // `$data['is_hidden'] ?? false` straight through, so an absent key
            // is false on CREATE — unlike on UPDATE, where absence means
            // "untouched". Creation has no previous value to leave alone.
            'is_hidden' => $request->boolean('is_hidden'),
            'order_index' => $this->nextOrderIndex($course->getKey()),
            // Open decision 10.21. Absent/null on CREATE just means "no
            // gate" — there is no previous value to preserve, so this needs
            // no has()-guard the way UPDATE below does.
            'start_at' => $request->input('start_at'),
            'end_at' => $request->input('end_at'),
        ]));

        return ApiResponse::created(
            ['group' => new CourseGroupResource($group)],
            'تم إنشاء المجموعة بنجاح'
        );
    }

    /**
     * PATCH /api/v1/groups/{id}. Row 76.
     *
     * `title`     has() && !== null  → '' is written (VARCHAR, legal)
     * `is_hidden` has() then boolean() → `false` is a real un-hide instruction
     * `start_at`/`end_at` has()-gated too (open decision 10.21) → an explicit
     *             `null` really clears the gate
     *
     * Never boolean() without has() in front: an absent key would otherwise
     * un-hide the group on every unrelated rename. Same rule as `is_published`
     * on a course and `isActive` on a teacher.
     */
    public function update(ManageCourseGroupRequest $request): JsonResponse
    {
        $group = $request->group();

        if ($request->has('title') && $request->input('title') !== null) {
            $group->setAttribute('title', (string) $request->input('title'));
        }

        if ($request->has('is_hidden')) {
            $group->setAttribute('is_hidden', $request->boolean('is_hidden'));
        }

        // Open decision 10.21 — has()-gated, same as is_hidden above: the
        // key must be PRESENT to change anything, so `start_at: null` really
        // clears the gate rather than being read as "field omitted".
        if ($request->has('start_at')) {
            $group->setAttribute('start_at', $request->input('start_at'));
        }

        if ($request->has('end_at')) {
            $group->setAttribute('end_at', $request->input('end_at'));
        }

        // Presence-based like is_hidden: order_index 0 is a real position,
        // not "absent". See ManageCourseGroupRequest.
        if ($request->has('order_index')) {
            $group->setAttribute('order_index', (int) $request->input('order_index'));
        }

        $group->save();

        return ApiResponse::success(
            ['group' => new CourseGroupResource($group)],
            'تم تحديث المجموعة بنجاح'
        );
    }

    /**
     * DELETE /api/v1/groups/{id} — HARD delete, NOT audited. Row 77.
     *
     * Its `content_items` go with it: fk_content_items_group is ON DELETE
     * CASCADE, which migration 000014 explains at length (SET NULL made group
     * deletion impossible under the single-parent CHECK). Session B owns those
     * rows; nothing here touches them.
     */
    public function destroy(ManageCourseGroupRequest $request): JsonResponse
    {
        $request->group()->delete();

        return ApiResponse::success(null, 'تم حذف المجموعة بنجاح');
    }

    /** `COALESCE(MAX(order_index), -1) + 1` — see SectionController's twin. */
    private function nextOrderIndex(string $courseId): int
    {
        $max = CourseGroup::query()->where('course_id', $courseId)->max('order_index');

        return $max === null ? 0 : ((int) $max) + 1;
    }
}
