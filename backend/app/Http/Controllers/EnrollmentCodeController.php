<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Enrollment\GenerateEnrollmentCode;
use App\Actions\Enrollment\RedeemEnrollmentCode;
use App\Exceptions\NotFoundException;
use App\Http\Requests\Enrollment\RedeemEnrollmentCodeRequest;
use App\Http\Requests\Enrollment\SearchEnrollmentCodesRequest;
use App\Http\Requests\Enrollment\ShowEnrollmentCodeRequest;
use App\Http\Requests\Enrollment\StoreEnrollmentCodeRequest;
use App\Http\Resources\CreatedEnrollmentCodeResource;
use App\Http\Resources\EnrollmentCodeDetailResource;
use App\Http\Resources\EnrollmentCodeResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Course;
use App\Models\EnrollmentCode;
use App\Models\User;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * نظام أكواد الاشتراك — freeze-table rows 57, 58, 59 (admin) and 60 (student).
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ ROLE SEPARATION IS DONE AT THE ROUTE, NOT IN THIS CLASS.                 ║
 * ║                                                                          ║
 * ║ store/index/show sit in the `role:admin` group; redeem sits in the       ║
 * ║ `role:student` group — an admin or instructor POSTing to /redeem gets    ║
 * ║ 403 from the middleware, never from a branch here. One controller with   ║
 * ║ two audiences is deliberate (backend/ did the same) and is safe          ║
 * ║ precisely because no method re-decides the question: there is no `if     ║
 * ║ ($user->isAdmin())` anywhere below that a future edit could invert.      ║
 * ║                                                                          ║
 * ║ NOTE THE ROLE GATE ON REDEEM IS **NOT** DECORATIVE. `subscriptions.      ║
 * ║ student_id` is an FK to `students.user_id`, and neither an admin nor an  ║
 * ║ instructor has a `students` row — so without `role:student` a redeeming  ║
 * ║ admin would reach the INSERT and fall over a raw FK violation, i.e. a    ║
 * ║ 500 where the contract says 403. That is the same class of hazard        ║
 * ║ open-decision 3.1 records for exam/assignment submission, avoided here   ║
 * ║ by the route rather than by an in-Action guard, because unlike those two ║
 * ║ this route has no legitimate non-student caller at all.                  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class EnrollmentCodeController extends Controller
{
    /**
     * POST /admin/enrollment-codes — row 57.
     *
     * THE COURSE IS RESOLVED HERE, AND ITS ABSENCE IS A **404** with backend/'s
     * literal 'الدورة غير موجودة'. An `exists:` validation rule would have been
     * shorter and would have answered 422 — a different status and a different
     * body on a contract the admin UI reads. See StoreEnrollmentCodeRequest.
     *
     * Course's SoftDeletes global scope means a trashed course is "not found"
     * here with no clause to remember: issuing codes for a deleted course
     * would mint tokens that redemption itself refuses (row 60's
     * 'الدورة المرتبطة بهذا الكود لم تعد متاحة').
     *
     * ┌── BULK GENERATION (open decision 10.15) — RESPONSE SHAPE CHANGED. ────┐
     * │ `count` (default 1, capped at StoreEnrollmentCodeRequest::MAX_COUNT)  │
     * │ generates that many codes in one request, each through its own call  │
     * │ to GenerateEnrollmentCode — its per-code collision retry is already   │
     * │ self-contained (see that class), so looping it needs no changes      │
     * │ there. Wrapped in DB::transaction so a failure partway (e.g. attempt  │
     * │ exhaustion on one code) leaves NONE of the batch committed rather     │
     * │ than a partial, silently-short batch the admin never asked for.      │
     * │                                                                      │
     * │ THE RESPONSE KEY IS NOW `codes` (a list), NEVER `enrollmentCode`      │
     * │ (singular) — even when `count=1`. This is a deliberate, documented    │
     * │ breaking change to the prior contract; the frontend was updated in   │
     * │ the same change to read `data.codes[0]` for the single-code case.    │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function store(StoreEnrollmentCodeRequest $request, GenerateEnrollmentCode $generate): JsonResponse
    {
        $course = Course::query()->whereKey($request->courseId())->first()
            ?? throw new NotFoundException('الدورة غير موجودة');

        /** @var User|null $admin */
        $admin = $request->user();
        $count = $request->count();

        $codes = DB::transaction(function () use ($generate, $course, $admin, $count): array {
            $created = [];

            for ($i = 0; $i < $count; $i++) {
                $created[] = $generate($course, $admin);
            }

            return $created;
        });

        return ApiResponse::created(
            ['codes' => array_map(
                static fn (EnrollmentCode $code) => new CreatedEnrollmentCodeResource($code, $course),
                $codes
            )],
            $count > 1 ? "تم توليد {$count} كود بنجاح" : 'تم توليد الكود بنجاح'
        );
    }

    /**
     * GET /admin/enrollment-codes[?course_id=] — row 58.
     *
     * `with()` IS THE WHOLE PERFORMANCE STORY. Each row renders its course
     * title, that course's teacher's name and (when redeemed) the student's
     * name — three relationship hops that, unloaded, are three queries PER
     * CODE. Eager-loading turns a 100-code page from ~300 queries into 5.
     *
     * THE LIMIT IS INHERITED AND IS NOT PAGINATION. backend/ capped this at
     * 100 rows with no cursor and no total; the admin screen has no pager to
     * drive one. Preserved as-is — adding pagination would change the payload
     * shape for a caller that does not ask for it.
     */
    public function index(Request $request): JsonResponse
    {
        $courseId = trim((string) $request->query('course_id', ''));

        $codes = EnrollmentCode::query()
            ->with(['course.teacher.user:id,full_name', 'redeemer.user:id,full_name'])
            ->when($courseId !== '', fn ($q) => $q->where('course_id', $courseId))
            ->newestFirst()
            ->limit(self::LIST_LIMIT)
            ->get();

        return ApiResponse::success([
            'enrollmentCodes' => EnrollmentCodeResource::collection($codes),
        ]);
    }

    /**
     * GET /admin/enrollment-codes/search — the admin review/filter screen.
     *
     * REGISTERED BEFORE THE `{code}` WILDCARD ROUTE — see the routes/api.php
     * comment right above the existing enrollment-codes group, which already
     * warned that any future literal segment under `/enrollment-codes/` must
     * be registered ahead of the wildcard or it would be swallowed by it.
     *
     * Six optional filters (SearchEnrollmentCodesRequest), all ANDed.
     * `teacher_id` is an EXACT match (the admin UI now sends a real teacher
     * id from a `<Select>`); the other five are `LIKE '%…%'` — a search box,
     * not an exact lookup. `teacher_name` and `student_name` filter through
     * the JOINed `users.full_name`;
     * `student_phone`/`parent_phone` filter through the redeeming student's
     * own profile columns, so they only ever match a code that has actually
     * been redeemed (an unused code has no redeemer to match against).
     *
     * SEARCH_LIMIT (500) is a safety ceiling, not pagination — this is a
     * comprehensive review screen (unlike index()'s 100-row "recent codes"
     * cap), so the client paginates/counts locally over the full filtered
     * set, same as ListSubscriptionsForAdmin does for /admin/subscriptions.
     */
    public function search(SearchEnrollmentCodesRequest $request): JsonResponse
    {
        $codes = EnrollmentCode::query()
            ->with(['course.teacher.user:id,full_name', 'redeemer.user:id,full_name'])
            ->when($request->code() !== '', fn ($q) => $q->where('code', 'like', '%' . $request->code() . '%'))
            ->when(
                $request->teacherId() !== '',
                fn ($q) => $q->whereHas(
                    'course',
                    fn ($q2) => $q2->where('teacher_id', $request->teacherId())
                )
            )
            ->when(
                $request->teacherName() !== '',
                fn ($q) => $q->whereHas(
                    'course.teacher.user',
                    fn ($q2) => $q2->where('full_name', 'like', '%' . $request->teacherName() . '%')
                )
            )
            ->when(
                $request->studentName() !== '',
                fn ($q) => $q->whereHas(
                    'redeemer.user',
                    fn ($q2) => $q2->where('full_name', 'like', '%' . $request->studentName() . '%')
                )
            )
            ->when(
                $request->studentPhone() !== '',
                fn ($q) => $q->whereHas(
                    'redeemer',
                    fn ($q2) => $q2->where('phone', 'like', '%' . $request->studentPhone() . '%')
                )
            )
            ->when(
                $request->parentPhone() !== '',
                fn ($q) => $q->whereHas(
                    'redeemer',
                    fn ($q2) => $q2->where('parent_phone', 'like', '%' . $request->parentPhone() . '%')
                )
            )
            ->newestFirst()
            ->limit(self::SEARCH_LIMIT)
            ->get();

        return ApiResponse::success([
            'enrollmentCodes' => EnrollmentCodeResource::collection($codes),
        ]);
    }

    /**
     * GET /admin/enrollment-codes/{code} — row 59. Read-only; nothing mutates.
     *
     * ┌── THE COURSE IS LOADED `withTrashed()`, AND ONLY ON THIS ROUTE. ─────┐
     * │ A soft-deleted course keeps its codes (the FK cascades on hard       │
     * │ deletes only). Resolving through the ordinary scope would make the   │
     * │ course come back null and this page would have to render a code      │
     * │ bound to nothing — so the admin is shown the course WITH             │
     * │ `is_deleted: true`, which is exactly what backend/ did and why its   │
     * │ query selected `c.deleted_at`. Every other route in this session     │
     * │ treats a trashed course as absent; this one is the documented        │
     * │ exception, because it is a forensic lookup rather than an action.    │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function show(ShowEnrollmentCodeRequest $request): JsonResponse
    {
        $code = EnrollmentCode::query()
            ->with([
                'creator:id,full_name,username',
                'redeemer.user:id,full_name,username',
            ])
            ->where('code', $request->code())
            ->first()
            ?? throw new NotFoundException('لا يوجد كود اشتراك بهذا الرقم');

        $course = Course::query()
            ->withTrashed()
            ->with('teacher.user:id,full_name,username')
            ->whereKey($code->course_id)
            ->firstOrFail();

        return ApiResponse::success([
            'enrollmentCode' => new EnrollmentCodeDetailResource($code, $course),
        ]);
    }

    /**
     * POST /enrollment-codes/redeem — row 60.
     *
     * NEITHER THE STUDENT NOR THE COURSE COMES FROM THE REQUEST. The student
     * is `$request->user()` and the course is read off the code's own row
     * inside the Action. A `course_id` in the body has no reader.
     */
    public function redeem(RedeemEnrollmentCodeRequest $request, RedeemEnrollmentCode $redeem): JsonResponse
    {
        /** @var User $student */
        $student = $request->user();

        $result = $redeem($request->code(), $student);

        return ApiResponse::success(
            [
                'course_id' => $result['course_id'],
                'course_title' => $result['course_title'],
                'subscription' => new SubscriptionResource($result['subscription']),
            ],
            'تم تفعيل اشتراكك في الدورة بنجاح'
        );
    }

    /** backend/EnrollmentCodeService::list()'s default. See index(). */
    private const LIST_LIMIT = 100;

    /** Safety ceiling for search() — see that method's docblock. */
    private const SEARCH_LIMIT = 500;
}
