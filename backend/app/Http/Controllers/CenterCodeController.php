<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Center\GenerateCenterCode;
use App\Actions\Center\UnlinkCenterMembership;
use App\Exceptions\NotFoundException;
use App\Http\Requests\Center\SearchCenterCodesRequest;
use App\Http\Requests\Center\StoreCenterCodeRequest;
use App\Http\Resources\CenterCodeDetailResource;
use App\Http\Resources\CenterCodeResource;
use App\Models\CenterCode;
use App\Models\CenterMembership;
use App\Models\Teacher;
use App\Models\User;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Center codes — admin-only surface. "توليد أكواد السنتر" +
 * "تفاصيل كود سنتر". Same shape as EnrollmentCodeController's admin half,
 * minus redemption (a center code has no student-facing controller here —
 * that lives on CenterStudentController::join()).
 */
final class CenterCodeController extends Controller
{
    private const LIST_LIMIT = 100;

    private const SEARCH_LIMIT = 500;

    /** POST /admin/center-codes — bulk-capable, same as enrollment codes. */
    public function store(StoreCenterCodeRequest $request, GenerateCenterCode $generate): JsonResponse
    {
        $teacher = Teacher::query()->whereKey($request->teacherId())->first()
            ?? throw new NotFoundException('المدرس غير موجود');

        /** @var User|null $admin */
        $admin = $request->user();
        $count = $request->count();

        $codes = DB::transaction(function () use ($generate, $teacher, $admin, $count): array {
            $created = [];

            for ($i = 0; $i < $count; $i++) {
                $created[] = $generate($teacher, $admin);
            }

            return $created;
        });

        return ApiResponse::created(
            ['codes' => CenterCodeResource::collection($codes)],
            $count > 1 ? "تم توليد {$count} كود بنجاح" : 'تم توليد الكود بنجاح'
        );
    }

    /** Eager-loaded on every listing route so CenterCodeResource's `students` costs no N+1. */
    private const WITH_STUDENTS = ['teacher.user:id,full_name', 'memberships.student.user:id,full_name'];

    /** GET /admin/center-codes — recent, capped, no pagination. */
    public function index(): JsonResponse
    {
        $codes = CenterCode::query()
            ->with(self::WITH_STUDENTS)
            ->newestFirst()
            ->limit(self::LIST_LIMIT)
            ->get();

        return ApiResponse::success(['codes' => CenterCodeResource::collection($codes)]);
    }

    /** GET /admin/center-codes/search — registered before the {code} wildcard. */
    public function search(SearchCenterCodesRequest $request): JsonResponse
    {
        $codes = CenterCode::query()
            ->with(self::WITH_STUDENTS)
            ->when($request->code() !== '', fn ($q) => $q->where('code', 'like', '%' . $request->code() . '%'))
            ->when($request->teacherId() !== '', fn ($q) => $q->where('teacher_id', $request->teacherId()))
            ->newestFirst()
            ->limit(self::SEARCH_LIMIT)
            ->get();

        return ApiResponse::success(['codes' => CenterCodeResource::collection($codes)]);
    }

    /** GET /admin/center-codes/{code}. */
    public function show(string $code): JsonResponse
    {
        $row = CenterCode::query()
            ->with(['teacher.user:id,full_name', 'creator:id,full_name', 'memberships.student.user:id,full_name'])
            ->where('code', $code)
            ->first()
            ?? throw new NotFoundException('لا يوجد كود سنتر بهذا الرقم');

        return ApiResponse::success(['code' => new CenterCodeDetailResource($row)]);
    }

    /**
     * DELETE /admin/center-memberships/{id} — "فك الربط".
     *
     * Deletes the CenterMembership row linking one student to one teacher's
     * center. The student's `students`/`users` rows are untouched — only the
     * link is removed — so the SAME center code (or any other of that
     * teacher's codes) can be used again afterward, exactly as requested:
     * this is a hard un-link, not a soft "revoke" flag, because nothing else
     * in the schema reads a revoked-but-present membership differently.
     *
     * Delegates to UnlinkCenterMembership (open decision 10.34), which ALSO
     * revokes any `center_course_activations` this teacher granted the
     * student — otherwise the student keeps working access to that
     * teacher's courses despite the "unlink" appearing to remove them.
     */
    public function unlinkMembership(string $id, UnlinkCenterMembership $unlink): JsonResponse
    {
        $membership = CenterMembership::query()->whereKey($id)->first()
            ?? throw new NotFoundException('الربط غير موجود');

        $unlink($membership);

        return ApiResponse::success(null, 'تم فك ارتباط الطالب بنجاح، ويمكنه استخدام الكود مرة أخرى');
    }
}
