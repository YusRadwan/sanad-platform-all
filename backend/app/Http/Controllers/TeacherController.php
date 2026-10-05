<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Teacher\CreateTeacher;
use App\Actions\Teacher\TeacherStatistics;
use App\Actions\Teacher\UpdateTeacher;
use App\Exceptions\NotFoundException;
use App\Http\Requests\Teacher\DeleteTeacherRequest;
use App\Http\Requests\Teacher\StoreTeacherRequest;
use App\Http\Requests\Teacher\UpdateTeacherRequest;
use App\Http\Resources\PublicTeacher;
use App\Http\Resources\TeacherResource;
use App\Http\Responses\ApiResponse;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Pagination;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher CRUD (admin), the public instructor directory, and the instructor's
 * own statistics.
 *
 * Port of backend/app/Http/Controllers/TeacherController.php. The controller is
 * deliberately THIN: shape validation in the Form Requests, business rules in
 * the Actions, response shape in the Resources. What remains here is HTTP
 * wiring and the exact Arabic success messages, which ARE a contract and are
 * therefore all visible on one screen.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THERE IS NO TeacherPolicy, AND THAT IS THE DESIGN.                      │
 * │                                                                          │
 * │ The seven admin actions here sit behind `role:admin`. A Policy for them  │
 * │ could only ever say `return $user->isAdmin()` — and it would not even    │
 * │ run, because Stage 3's Gate::before returns true for an admin BEFORE any │
 * │ Policy is consulted. It would be pure ceremony with zero security value, │
 * │ and it would move the access rule OUT of routes/api.php, where anyone    │
 * │ asking "who can call this?" actually looks. The freeze table records the │
 * │ same conclusion for all 25 rows of the /admin group.                     │
 * │                                                                          │
 * │ The three non-admin actions need no Policy for a different reason: there │
 * │ is no resource the CALLER NAMES. publicIndex() takes no id; statistics() │
 * │ derives its subject from the session. A Policy answers "may this user    │
 * │ act on THIS row" — a question neither route asks.                        │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class TeacherController extends Controller
{
    /**
     * GET /api/v1/admin/teachers — paginated admin listing.
     *
     * `socialLinks` IS EAGER-LOADED FOR THE WHOLE PAGE, not fetched per row.
     * ManageTeachers.tsx hands the row it rendered straight to AddTeacher.tsx
     * as `location.state.editTeacher`, and that form restores its social-link
     * toggles from `editTeacher.socialLinks` — so the LISTING has to carry
     * them. backend/ solved the same N+1 with a bulk `WHERE teacher_id IN (…)`
     * query; `with()` is the framework's version of exactly that.
     */
    public function index(Request $request): JsonResponse
    {
        [$page, $perPage] = Pagination::fromRequest($request);

        $query = Teacher::query()
            ->withLiveUser()
            ->with(['user', 'socialLinks'])
            // ORDER BY u.created_at DESC. The teachers table has its own
            // created_at, but backend/ ordered by the USER's — the account's
            // birth date, which is what "newest teacher" means to an admin.
            ->join('users', 'users.id', '=', 'teachers.user_id')
            ->orderByDesc('users.created_at')
            ->select('teachers.*');

        $total = (clone $query)->getQuery()->getCountForPagination();

        $items = $query->forPage($page, $perPage)->get();

        return ApiResponse::success([
            'items' => TeacherResource::collection($items),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * GET /api/v1/teachers — PUBLIC instructor directory. No auth, by design:
     * CourseDetailsPage.tsx resolves a course's teacher to a profile link for
     * logged-out visitors.
     *
     * Distinct from index() above, which is the admin CRUD listing. The
     * difference that matters is not the presenter but the QUERY:
     * scopePubliclyVisible() never selects email or phone, so those columns
     * cannot be serialised by any presenter, present or future. Read that
     * scope's docblock before touching this method.
     *
     * NO PAGINATION, matching backend/'s publicList(): the frontend's
     * useTeachers.ts loads the whole directory once and filters client-side.
     */
    public function publicIndex(): JsonResponse
    {
        $teachers = Teacher::query()
            ->publiclyVisible()
            ->with('socialLinks')
            ->get();

        return ApiResponse::success([
            'teachers' => PublicTeacher::collection($teachers),
        ]);
    }

    /**
     * GET /api/v1/teachers/statistics — the CALLING instructor's own numbers.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE IDENTITY COMES FROM THE SESSION AND NOWHERE ELSE.                │
     * │                                                                      │
     * │ Note what this method does NOT do: it never reads $request->query()  │
     * │ or any input at all. A caller may append `?teacher_id=<someone      │
     * │ else>` and it has literally no effect — there is no code path that   │
     * │ could observe it. That is stronger than validating and rejecting the │
     * │ parameter, and it is pinned by a test that sends one and asserts the │
     * │ response is identical.                                               │
     * │                                                                      │
     * │ Do not add "admin flexibility" here. Platform-wide numbers already   │
     * │ live behind GET /admin/dashboard.                                    │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function statistics(Request $request, TeacherStatistics $statistics): JsonResponse
    {
        /** @var User $instructor */
        $instructor = $request->user();

        return ApiResponse::success($statistics($instructor));
    }

    /** POST /api/v1/admin/teachers -> 201. */
    public function store(StoreTeacherRequest $request, CreateTeacher $create): JsonResponse
    {
        $teacher = $create($request->validated());

        return ApiResponse::created(
            new TeacherResource($this->present($teacher)),
            'تم إضافة المدرس بنجاح'
        );
    }

    /** GET /api/v1/admin/teachers/{id}. */
    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new TeacherResource($this->present($this->findTeacher($id))));
    }

    /**
     * PATCH | PUT /api/v1/admin/teachers/{id} — BOTH VERBS, one action.
     *
     * The duplication is deliberate and is preserved from backend/: the
     * frontend uses PUT for its تفعيل/إيقاف toggle flow and PATCH for the
     * general edit form. Both send partial payloads and both mean the same
     * thing here, so mapping them to one handler is honest rather than
     * sloppy — see routes/api.php.
     */
    public function update(UpdateTeacherRequest $request, UpdateTeacher $update, string $id): JsonResponse
    {
        $teacher = $update($request, $this->findTeacher($id));

        return ApiResponse::success(
            new TeacherResource($this->present($teacher)),
            'تم تحديث بيانات المدرس بنجاح'
        );
    }

    /**
     * DELETE /api/v1/admin/teachers/{id}.
     *
     * The admin's own password is re-verified by DeleteTeacherRequest before
     * this method is entered — a 401, not a 422. See
     * App\Http\Requests\Concerns\RequiresAdminReauth.
     *
     * SOFT DELETE ON `users` ONLY. The `teachers` profile row is deliberately
     * left intact, exactly as backend/ left it, so historical courses keep a
     * resolvable owner. That is also why this is a single-table write with
     * nothing to make atomic.
     */
    public function destroy(DeleteTeacherRequest $request, string $id): JsonResponse
    {
        $teacher = $this->findTeacher($id);

        /** @var User $user */
        $user = $teacher->getRelationValue('user');
        $user->delete();

        SecurityLog::warning('Teacher deleted', [
            'event' => 'teacher.deleted',
            'target_user_id' => $teacher->getKey(),
        ]);

        return ApiResponse::success(null, 'تم حذف المدرس بنجاح');
    }

    /**
     * Resolve {id} to a teacher whose ACCOUNT IS STILL LIVE.
     *
     * withLiveUser() is what makes a soft-deleted teacher a 404 here rather
     * than a 200 describing a deleted account: the `teachers` row survives the
     * delete, so a bare find() would happily return it and the presenter would
     * then dereference a null `user`. See Teacher::user()'s docblock.
     *
     * NOT implicit route-model binding: the message below is part of the
     * contract (freeze-table row 42) and binding's own 404 would be
     * genericised by App\Exceptions\Handler.
     */
    private function findTeacher(string $id): Teacher
    {
        return Teacher::query()
            ->withLiveUser()
            ->with(['user', 'socialLinks'])
            ->whereKey($id)
            ->first()
            ?? throw new NotFoundException('Teacher not found');
    }

    /**
     * Re-reads the relations a write may have invalidated, so the response
     * reflects the row as it now stands rather than as it was loaded.
     *
     * refresh() alone would not do: it reloads attributes but keeps stale
     * relations, and both create and update can have replaced the entire
     * social-link set underneath.
     */
    private function present(Teacher $teacher): Teacher
    {
        return $teacher->load(['user', 'socialLinks']);
    }
}
