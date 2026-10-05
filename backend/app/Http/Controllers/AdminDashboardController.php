<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Admin\DashboardStats;
use App\Actions\Admin\ListLoginActivity;
use App\Actions\Enrollment\CoursesOverview;
use App\Actions\Enrollment\ListSubscriptionsForAdmin;
use App\Actions\Enrollment\ToggleEnrollment;
use App\Http\Requests\Enrollment\ToggleEnrollmentRequest;
use App\Http\Resources\LoginActivityResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The admin's enrolment surface — freeze-table rows 53, 54 and 55.
 *
 * ┌── SCOPE NOTE — UPDATED BY SESSION B. ────────────────────────────────────┐
 * │ Session A left this class holding rows 53, 54 and 55 and noted that      │
 * │ `stats()` (row 52) and `loginActivity()` (row 56) were Session B's.      │
 * │                                                                          │
 * │ SESSION B ADDS `stats()` ONLY. ROW 56 IS **STAGE 10's**, not this        │
 * │ session's — it reads `login_sessions` and belongs with the session-       │
 * │ management work, and building it here would mean shipping a User-Agent   │
 * │ parser (browser/os/device strings, seven Arabic literals) with no        │
 * │ session-lifecycle code around it to test it against. It is deliberately  │
 * │ ABSENT rather than stubbed: an unroutable method on a class whose every  │
 * │ other method is live is worse than a gap the freeze table records.       │
 * │                                                                          │
 * │ STAGE 7 IS THEREFORE COMPLETE AT 21 ROWS, and row 56 is the only         │
 * │ /admin-prefixed route still open anywhere in the table.                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO POLICY IS CONSULTED BY ANY METHOD BELOW, and that is the freeze table's
 * ruling for the admin block ("`role:admin` is the whole rule"): a Policy on
 * these routes could only ever `return $user->isAdmin()`, which Gate::before
 * has already answered before any Policy method is entered. A second copy of a
 * security rule in the place designed to hold exactly one is a liability, not
 * defence in depth.
 */
final class AdminDashboardController extends Controller
{
    /**
     * GET /admin/dashboard — row 52. The nine tiles on AdminHome.tsx.
     *
     * NO REQUEST INPUT AT ALL: no filters, no date range, no pagination. The
     * method takes nothing but the Action, so there is nothing a caller can
     * supply that changes the answer.
     *
     * THREE OF THE NINE COUNTERS DELIBERATELY DIFFER FROM backend/ (Session B
     * decision #4 — soft-deleted users, teachers and students are now
     * excluded). The reasoning, the exact before/after, and why the other six
     * are byte-identical live on App\Actions\Admin\DashboardStats.
     */
    public function stats(DashboardStats $stats): JsonResponse
    {
        return ApiResponse::success(['stats' => $stats()]);
    }

    /**
     * GET /admin/subscriptions — row 53.
     *
     * FIVE OPTIONAL FILTERS, COLLECTED HERE AND APPLIED IN SQL. A blank or
     * absent parameter means "no filter", so the unfiltered call
     * ManageSubscriptions.tsx already makes keeps its exact previous
     * behaviour — which is why the whitelist is built by iterating a fixed
     * list rather than by passing `$request->query()` through: an unknown
     * query parameter must be inert, not a filter.
     *
     * NO FormRequest, deliberately. There is no invalid input here — every
     * value is an optional free-text search term, and a FormRequest could only
     * reject shapes that currently return an empty list. Contrast
     * ToggleEnrollmentRequest below, where three fields are mandatory.
     */
    public function subscriptions(Request $request, ListSubscriptionsForAdmin $list): JsonResponse
    {
        $filters = [];

        foreach (self::SUBSCRIPTION_FILTERS as $key) {
            $value = trim((string) ($request->query($key) ?? ''));

            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        return ApiResponse::success(['subscriptions' => $list($filters)]);
    }

    /**
     * PUT /admin/enrollments/toggle — row 54.
     *
     * ONE ROUTE, TWO GENUINELY DIFFERENT OPERATIONS — see
     * App\Actions\Enrollment\ToggleEnrollment for why granting and cancelling
     * are asymmetric (different 404s, different transactionality, only one of
     * them touches money). The controller does not branch; the Action owns the
     * split so there is one place where the two paths are visible side by
     * side.
     */
    public function toggleEnrollment(ToggleEnrollmentRequest $request, ToggleEnrollment $toggle): JsonResponse
    {
        $subscription = $toggle(
            $request->studentId(),
            $request->courseId(),
            $request->isActive(),
        );

        return ApiResponse::success(
            ['enrollment' => new SubscriptionResource($subscription)],
            'تم تحديث حالة الاشتراك'
        );
    }

    /** GET /admin/courses/overview — row 55. */
    public function coursesOverview(CoursesOverview $overview): JsonResponse
    {
        return ApiResponse::success(['overview' => $overview()]);
    }

    /**
     * GET /admin/login-activity — row 56, and the LAST /admin-prefixed route
     * in the freeze table. Added by STAGE 10, as Session B's docblock above
     * promised.
     *
     * ┌── THE WRAPPER KEY IS `sessions`, NOT `loginActivity`. ───────────────┐
     * │ backend/AdminDashboardController::loginActivity() answers            │
     * │ `ApiResponse::success(['sessions' => …])` and LoginActivity.tsx      │
     * │ reads `data.sessions`. The method name and the payload key differ,   │
     * │ and the payload key is the contract.                                  │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * NO REQUEST INPUT, like `stats()` above: the Action takes nothing, so no
     * query parameter can widen the 300-row cap or reorder the result.
     *
     * The three display strings (browser / os / deviceType) are derived in
     * App\Support\UserAgentParser — a separate class precisely so its
     * order-sensitive branch chain can be unit-tested without this endpoint.
     */
    public function loginActivity(ListLoginActivity $list): JsonResponse
    {
        return ApiResponse::success([
            'sessions' => LoginActivityResource::collection($list()),
        ]);
    }

    /**
     * The exact filter set, verified against backend/AdminDashboardController::
     * subscriptions(). Five, in this order, and matching the query-string names
     * ManageSubscriptions.tsx sends (snake_case here, unlike the camelCase body
     * of the toggle route — inherited inconsistency, not a choice).
     */
    private const SUBSCRIPTION_FILTERS = [
        'phone',
        'parent_phone',
        'student_code',
        'teacher_id',
        'course_id',
    ];
}
