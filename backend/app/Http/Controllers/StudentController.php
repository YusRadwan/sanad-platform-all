<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\StartImpersonation;
use App\Actions\Student\StudentDashboard;
use App\Actions\Student\StudentExamAttempts;
use App\Actions\Student\StudentWatchedVideos;
use App\Actions\Student\UpdateStudent;
use App\Enums\SubscriptionStatus;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Requests\Student\DeleteStudentRequest;
use App\Http\Requests\Student\LookupStudentCodeRequest;
use App\Http\Requests\Student\UpdateStudentRequest;
use App\Http\Resources\PublicUser;
use App\Http\Resources\StudentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Pagination;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student CRUD (admin) and the student's own dashboard.
 *
 * Port of backend/app/Http/Controllers/StudentController.php. As with
 * TeacherController there is NO StudentPolicy: the admin actions are a flat
 * `role:admin` that Gate::before would short-circuit anyway, and dashboard()
 * names no resource at all.
 */
final class StudentController extends Controller
{
    /**
     * GET /api/v1/admin/students — paginated admin listing.
     *
     * FIVE OPTIONAL FILTERS (open decision 10.17), all ANDed, all `LIKE`
     * partial matches — same search-box convention as the enrollment-code and
     * subscription filter screens: `student_code`, `name` (through the joined
     * `users.full_name`), `phone`, `parent_phone`, `governorate`.
     *
     * `?all=1` BYPASSES PAGINATION ENTIRELY (the "عرض جميع الطلاب" button) —
     * capped at ALL_LIMIT as a safety ceiling, not a page size; `page`/`per_page`
     * are ignored when present. Ordinary pagination (`Pagination::fromRequest`)
     * is unchanged for every other caller.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Student::query()
            ->withLiveUser()
            ->with('user')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->when(
                trim((string) $request->query('student_code', '')) !== '',
                fn ($q) => $q->where('students.student_code', 'like', '%' . trim((string) $request->query('student_code')) . '%')
            )
            ->when(
                trim((string) $request->query('name', '')) !== '',
                fn ($q) => $q->where('users.full_name', 'like', '%' . trim((string) $request->query('name')) . '%')
            )
            ->when(
                trim((string) $request->query('phone', '')) !== '',
                fn ($q) => $q->where('students.phone', 'like', '%' . trim((string) $request->query('phone')) . '%')
            )
            ->when(
                trim((string) $request->query('parent_phone', '')) !== '',
                fn ($q) => $q->where('students.parent_phone', 'like', '%' . trim((string) $request->query('parent_phone')) . '%')
            )
            ->when(
                trim((string) $request->query('governorate', '')) !== '',
                fn ($q) => $q->where('students.governorate', 'like', '%' . trim((string) $request->query('governorate')) . '%')
            )
            ->orderByDesc('users.created_at')
            ->select('students.*');

        $total = (clone $query)->getQuery()->getCountForPagination();

        if ($request->boolean('all')) {
            return ApiResponse::success([
                'items' => StudentResource::collection($query->limit(self::ALL_LIMIT)->get()),
                'total' => $total,
                'page' => 1,
                'per_page' => self::ALL_LIMIT,
            ]);
        }

        [$page, $perPage] = Pagination::fromRequest($request);

        return ApiResponse::success([
            'items' => StudentResource::collection($query->forPage($page, $perPage)->get()),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /** Safety ceiling for `?all=1` — see index(). */
    private const ALL_LIMIT = 5000;

    /** GET /api/v1/admin/students/{id}. */
    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new StudentResource($this->findStudent($id)));
    }

    /**
     * GET /api/v1/admin/students/by-code/{code} — the admin
     * "تفاصيل كود الطالب" lookup.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ STAGE 4 DECISION #4 — NORMALISE, THEN MATCH EXACTLY.                 │
     * │                                                                      │
     * │ backend/ ran `student_code LIKE CONCAT('%', :code)`. This runs a     │
     * │ plain equality against the UNIQUE column after folding both accepted │
     * │ input shapes ('510078' and 'STU510078', any casing) onto the one     │
     * │ stored spelling. Index seek instead of a leading-wildcard scan, and  │
     * │ exactly one row can match instead of "any code ending in these       │
     * │ digits". Admin input flexibility is unchanged.                       │
     * │                                                                      │
     * │ The proof that the stored format is invariably STU + six digits is   │
     * │ in App\Support\StudentCode's docblock — it is an argument about the  │
     * │ single writer, not an appeal to the current table contents.          │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * TWO DISTINCT FAILURES, DELIBERATELY NOT COLLAPSED. A malformed code is a
     * 422 raised by LookupStudentCodeRequest BEFORE any query is built; a
     * well-formed but unknown code is a 404. Route model binding cannot express
     * that difference — see that request's docblock.
     *
     * EVERY subscription is returned, whatever its status: the point of the
     * page is the student's whole history, so Subscription::scopeActive() is
     * deliberately NOT applied here. That is not a missing filter.
     */
    public function showByCode(LookupStudentCodeRequest $request): JsonResponse
    {
        $student = Student::query()
            ->withLiveUser()
            ->with('user')
            ->where('student_code', $request->normalisedCode())
            ->first()
            ?? throw new NotFoundException('لا يوجد طالب بهذا الكود');

        /** @var User $user */
        $user = $student->getRelationValue('user');

        return ApiResponse::success([
            'student' => (new StudentResource($student))->toArray($request) + [
                'id' => $student->getKey(),
                'email' => $user->getAttribute('email'),
                'gender' => $student->getAttribute('gender'),
                'educationType' => $student->getAttribute('education_type'),
                'registeredAt' => $user->created_at?->format('Y-m-d H:i:s'),
                'isActive' => (bool) $user->getAttribute('is_active'),
            ],
            'subscriptions' => $this->subscriptionsFor($student),
        ]);
    }

    /**
     * GET /api/v1/students/dashboard — the CALLING student's own data.
     *
     * IDENTITY FROM THE SESSION ONLY. Like TeacherController::statistics(),
     * this method reads no request input whatsoever, so a supplied
     * `?student_id=` has no code path that could observe it. Pinned by a test.
     */
    public function dashboard(Request $request, StudentDashboard $dashboard): JsonResponse
    {
        /** @var User $student */
        $student = $request->user();

        return ApiResponse::success($dashboard($student));
    }

    /**
     * GET /api/v1/students/watched-videos — the CALLING student's own watch
     * history. Same identity-from-session rule as dashboard() above.
     */
    public function watchedVideos(Request $request, StudentWatchedVideos $watchedVideos): JsonResponse
    {
        /** @var User $student */
        $student = $request->user();

        return ApiResponse::success($watchedVideos($student));
    }

    /**
     * GET /api/v1/students/exam-attempts — the CALLING student's own full
     * exam record. Same identity-from-session rule as dashboard() above.
     */
    public function examAttempts(Request $request, StudentExamAttempts $examAttempts): JsonResponse
    {
        /** @var User $student */
        $student = $request->user();

        return ApiResponse::success($examAttempts($student));
    }

    /**
     * PATCH | PUT /api/v1/admin/students/{id} — both verbs, one action, for
     * the same reason as the teacher update (see routes/api.php).
     */
    public function update(UpdateStudentRequest $request, UpdateStudent $update, string $id): JsonResponse
    {
        $student = $update($request, $this->findStudent($id));

        return ApiResponse::success(
            new StudentResource($student->load('user')),
            'تم التحديث بنجاح'
        );
    }

    /**
     * DELETE /api/v1/admin/students/{id}.
     *
     * STAGE 4 DECISION #1: the admin's own password is now re-verified by
     * DeleteStudentRequest — new behaviour, backend/ required nothing.
     *
     * NEW (open decision 10.19): A STUDENT WITH AT LEAST ONE ACTIVE
     * SUBSCRIPTION CANNOT BE DELETED. The admin must cancel the enrollment
     * first (PUT /admin/enrollments/toggle, isActive=false — the same
     * "إيقاف" button ManageSubscriptions.tsx already exposes), same
     * referential-integrity-guard shape as CatalogController's
     * ConflictException guards. `status` is compared to the STORED
     * SubscriptionStatus::Active value, not Subscription::scopeActive()'s
     * expiry-aware definition — consistent with DashboardStats (decision
     * 10.16) and with what the admin UI's own "نشط/موقوف" badge reads.
     *
     * Soft delete on `users` only; the `students` profile row is left intact so
     * historical subscriptions keep a resolvable owner.
     */
    public function destroy(DeleteStudentRequest $request, string $id): JsonResponse
    {
        $student = $this->findStudent($id);

        $activeSubscriptions = Subscription::query()
            ->where('student_id', $student->getKey())
            ->where('status', SubscriptionStatus::Active)
            ->count();

        if ($activeSubscriptions > 0) {
            throw new ConflictException(
                "لا يمكن حذف هذا الطالب: لديه {$activeSubscriptions} اشتراك/اشتراكات فعّالة. "
                . 'يرجى إلغاء اشتراكاته في الكورسات أولاً من صفحة جداول الاشتراكات.'
            );
        }

        /** @var User $user */
        $user = $student->getRelationValue('user');
        $user->delete();

        SecurityLog::warning('Student deleted', [
            'event' => 'student.deleted',
            'target_user_id' => $student->getKey(),
        ]);

        return ApiResponse::success(null, 'تم الحذف بنجاح');
    }

    /**
     * POST /api/v1/admin/students/{id}/impersonate — "تحويل إلى حساب الطالب"
     * (open decision 10.18). `findStudent()` already 404s on an unknown or
     * soft-deleted student; App\Actions\Auth\StartImpersonation is what
     * additionally refuses a SUSPENDED-but-not-deleted one
     * (`withLiveUser()` alone would let that through — see its own scope).
     */
    public function impersonate(Request $request, StartImpersonation $start, string $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $student = $this->findStudent($id);

        /** @var User $target */
        $target = $student->getRelationValue('user');

        $start($request, $admin, $target);

        return ApiResponse::success(['user' => new PublicUser($target)], 'تم الدخول لحساب الطالب بنجاح');
    }

    /**
     * Every subscription for one student with its course and teacher resolved.
     *
     * The teacher name is read through the course's `teacher` relation and then
     * that teacher's `user` — the three-hop path that asserts "an instructor
     * PROFILE owns this course", rather than the two-hop shortcut from
     * courses.teacher_id straight to users.id which happens to return the same
     * rows. See App\Models\Course's docblock.
     *
     * @return list<array<string, mixed>>
     */
    private function subscriptionsFor(Student $student): array
    {
        return Subscription::query()
            ->where('student_id', $student->getKey())
            ->with(['course.teacher.user'])
            ->orderByDesc('created_at')
            ->get()
            ->map(static function (Subscription $subscription): array {
                $course = $subscription->getRelationValue('course');
                $teacherUser = $course?->getRelationValue('teacher')?->getRelationValue('user');

                return [
                    'id' => $subscription->getKey(),
                    'course_id' => $subscription->getAttribute('course_id'),
                    'course_title' => $course?->getAttribute('title'),
                    'course_price' => (float) ($course?->getAttribute('price') ?? 0),
                    'teacher_id' => $course?->getAttribute('teacher_id'),
                    'teacher_name' => $teacherUser?->getAttribute('full_name'),
                    'status' => $subscription->getAttribute('status')?->value,
                    'expires_at' => $subscription->expires_at?->format('Y-m-d H:i:s'),
                    'subscribed_at' => $subscription->created_at?->format('Y-m-d H:i:s'),
                ];
            })
            ->all();
    }

    /**
     * Resolve {id} to a student whose ACCOUNT IS STILL LIVE — 404 otherwise.
     * Same reasoning as TeacherController::findTeacher(); the message is the
     * contract recorded in freeze-table row 48.
     */
    private function findStudent(string $id): Student
    {
        return Student::query()
            ->withLiveUser()
            ->with('user')
            ->whereKey($id)
            ->first()
            ?? throw new NotFoundException('Student not found');
    }
}
