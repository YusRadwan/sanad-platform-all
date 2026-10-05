<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TeacherApplication\StoreTeacherApplicationRequest;
use App\Http\Resources\TeacherApplicationResource;
use App\Http\Responses\ApiResponse;
use App\Models\TeacherApplication;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "طلب مدرس جديد" — freeze-table rows 119 (public) and 120 (admin), open
 * decision 10.23.
 *
 * Two audiences, one controller, exactly like EnrollmentCodeController: role
 * separation happens at the route (store carries no auth middleware at all,
 * index sits in the `role:admin` group), never by a branch in here.
 */
final class TeacherApplicationController extends Controller
{
    /**
     * POST /teacher-applications — row 119. PUBLIC, no session.
     *
     * No approve/reject workflow exists — the request explicitly asked only
     * for the submission to be captured and shown to an admin. `store()`
     * therefore just persists the validated payload and returns it back.
     */
    public function store(StoreTeacherApplicationRequest $request): JsonResponse
    {
        $application = TeacherApplication::query()->create($request->payload());

        return ApiResponse::created(
            ['application' => new TeacherApplicationResource($application)],
            'تم إرسال طلبك بنجاح، سيتم التواصل معك قريباً'
        );
    }

    /** Safety ceiling for `?all=1` — see index() and StudentController::index(). */
    private const ALL_LIMIT = 5000;

    /**
     * GET /admin/teacher-applications — row 120.
     *
     * Paginated with the same {items, total, page, per_page} envelope and
     * `Pagination::fromRequest` clamp every other admin listing in this
     * controller set uses (see StudentController::index()) — an unbounded
     * `->get()` here meant the response grew without limit as submissions
     * accumulated, since this table has no archival/delete path.
     *
     * `?all=1` keeps serving the review page's own search-across-everything
     * UI (TeacherApplications.tsx filters client-side by name/phone/subject/
     * governorate) without regressing to an unbounded query — same escape
     * hatch, same ceiling, as StudentController::index().
     */
    public function index(Request $request): JsonResponse
    {
        $query = TeacherApplication::query()->newestFirst();

        $total = (clone $query)->getQuery()->getCountForPagination();

        if ($request->boolean('all')) {
            return ApiResponse::success([
                'items' => TeacherApplicationResource::collection($query->limit(self::ALL_LIMIT)->get()),
                'total' => $total,
                'page' => 1,
                'per_page' => self::ALL_LIMIT,
            ]);
        }

        [$page, $perPage] = Pagination::fromRequest($request);

        return ApiResponse::success([
            'items' => TeacherApplicationResource::collection($query->forPage($page, $perPage)->get()),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }
}
