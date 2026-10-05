<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Revenue\CreatePayout;
use App\Actions\Revenue\ListRevenueTransactions;
use App\Actions\Revenue\ListTeacherPercentages;
use App\Actions\Revenue\PlatformSummary;
use App\Actions\Revenue\ProcessRefund;
use App\Actions\Revenue\RequestRefund;
use App\Actions\Revenue\TeacherStatement;
use App\Actions\Revenue\UpdateTeacherPercentage;
use App\Enums\RefundStatus;
use App\Http\Requests\Revenue\CreatePayoutRequest;
use App\Http\Requests\Revenue\SubmitRefundRequest;
use App\Http\Requests\Revenue\UpdateTeacherPercentageRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\RevenueTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every money route in the product — freeze-table rows 61-70 (admin) and
 * 99-101 (the instructor's own three). Stage 7 / Session B.
 *
 * ┌── THESE PATHS ARE **NOT** UNDER `/admin`, AND THAT IS NOT A MISTAKE. ────┐
 * │ They are bare `/api/v1/revenue/*`, because revenueService.ts calls them  │
 * │ that way and has since the product shipped. Ten of the thirteen are      │
 * │ nonetheless `role:admin`; the three `my-*` routes are `role:instructor`. │
 * │ Path prefix and authorisation are simply unrelated here. Moving them     │
 * │ under /admin for tidiness would 404 every finance screen at once.        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── THE `my-*` ROUTES ARE `role:instructor` ONLY. AN ADMIN GETS 403. ──────┐
 * │ No admin override, matching backend/'s own route group and the           │
 * │ self-service pattern Stage 4 established for /teachers/statistics and    │
 * │ /students/dashboard. An admin is not without access — rows 64, 65 and 66 │
 * │ read ANY teacher's statement, transactions and payouts by id — so the    │
 * │ restriction costs nothing and buys a clean rule: `my-*` means the        │
 * │ session's own data, and a role that has no revenue of its own has no     │
 * │ "own data" to return. `Gate::before`'s admin bypass is irrelevant:       │
 * │ middleware runs before any Gate is consulted, and no Policy is consulted │
 * │ on any route in this class.                                              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE SESSION IDENTITY IS THE ONLY TEACHER ID THE `my-*` ROUTES CAN SEE.  ║
 * ║ A `?teacher_id=` ON ANY OF THEM IS INERT **BY CONSTRUCTION**, not by a   ║
 * ║ check: the three methods read `$request->user()->getKey()` and no code   ║
 * ║ path below them looks at the query string at all. Nothing to validate    ║
 * ║ means nothing to get wrong — the same guarantee Stage 4 built for        ║
 * ║ /students/dashboard and Session A for enrollment-code redemption.        ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * NO POLICY IS CONSULTED BY ANY METHOD HERE, matching the freeze table's
 * ruling for the admin and self-service blocks: every candidate Policy would
 * reduce to `return $user->isAdmin()` (already answered by Gate::before) or to
 * "is this row mine?" on a route that never names a row.
 */
final class RevenueController extends Controller
{
    // ── Instructor-scoped: rows 99, 100, 101 ────────────────────────────

    /**
     * GET /revenue/my-statement — row 99.
     *
     * Delegates to the SAME Action as row 64 (the admin's read of any
     * teacher). See App\Actions\Revenue\TeacherStatement: the two answers must
     * never be able to differ, and Session B's decision #2 additionally aligns
     * the number with `available_balance` on row 63.
     */
    public function myStatement(Request $request, TeacherStatement $statement): JsonResponse
    {
        return ApiResponse::success(['statement' => $statement($this->sessionId($request))]);
    }

    /** GET /revenue/my-transactions — row 100. */
    public function myTransactions(Request $request, ListRevenueTransactions $list): JsonResponse
    {
        return ApiResponse::success([
            'transactions' => $list(teacherId: $this->sessionId($request)),
        ]);
    }

    /** GET /revenue/my-payouts — row 101. */
    public function myPayouts(Request $request): JsonResponse
    {
        return ApiResponse::success(['payouts' => $this->payoutsFor($this->sessionId($request))]);
    }

    // ── Admin, platform-wide: rows 61, 62, 69, 70, 68 ───────────────────

    /** GET /revenue/platform — row 61. */
    public function platform(Request $request, PlatformSummary $summary): JsonResponse
    {
        return ApiResponse::success(['summary' => $summary($this->transactionFilters($request))]);
    }

    /** GET /revenue/transactions — row 62. */
    public function transactions(Request $request, ListRevenueTransactions $list): JsonResponse
    {
        return ApiResponse::success(['transactions' => $list($this->transactionFilters($request))]);
    }

    /**
     * GET /revenue/refunds — row 69.
     *
     * `?status=` IS AN UNVALIDATED PASS-THROUGH, matching backend/: an unknown
     * value yields an empty list rather than a 422. There is no invalid input
     * to reject here — the parameter is a filter, and a filter that matches
     * nothing is a legitimate answer. A FormRequest could only turn today's
     * empty list into an error.
     */
    public function refunds(Request $request): JsonResponse
    {
        $status = trim((string) ($request->query('status') ?? ''));

        $refunds = Refund::query()
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Refund $refund): array => [
                'id' => (string) $refund->getKey(),
                'revenue_transaction_id' => $refund->revenue_transaction_id,
                'amount' => $refund->amount,
                'reason' => $refund->reason,
                'status' => $refund->status->value,
                'created_at' => $refund->created_at?->toDateTimeString(),
            ])
            ->all();

        return ApiResponse::success(['refunds' => $refunds]);
    }

    /**
     * POST /revenue/refunds — row 70. TWO OPERATIONS ON ONE PATH.
     *
     * The branch is the Form Request's (isRefundRequest()), not this method's,
     * so the discriminator is decided once — in the layer that also has to
     * apply two different rule sets to the two shapes — instead of twice, in
     * two places that could drift. See SubmitRefundRequest's banner for the
     * `isset` / `has()` / `filled()` table that makes the exact predicate
     * load-bearing.
     *
     * The two branches differ in STATUS CODE as well as behaviour: 201 for a
     * created request, 200 for a state change. backend/ did the same.
     */
    public function submitRefund(
        SubmitRefundRequest $request,
        RequestRefund $requestRefund,
        ProcessRefund $processRefund,
    ): JsonResponse {
        if ($request->isRefundRequest()) {
            $refund = $requestRefund($request->transactionId(), $request->reason());

            return ApiResponse::created(
                ['refund' => $this->presentRefund($refund)],
                'تم إرسال طلب الاسترداد'
            );
        }

        $refund = $processRefund($request->refundId(), $request->status());

        return ApiResponse::success(
            ['refund' => $this->presentRefund($refund)],
            'تم تحديث حالة الاسترداد'
        );
    }

    /**
     * POST /revenue/payouts — row 68.
     *
     * NOT wrapped in a transaction, deliberately — see App\Actions\Revenue\
     * CreatePayout's banner. `created_by` is the acting admin and comes from
     * the session; there is no request field for it.
     */
    public function createPayout(CreatePayoutRequest $request, CreatePayout $create): JsonResponse
    {
        $payout = $create(
            $request->teacherId(),
            $request->amount(),
            $request->paymentMethod(),
            $request->note(),
            $this->sessionId($request),
        );

        return ApiResponse::created(['payout' => $payout], 'تم تسجيل الدفعة بنجاح');
    }

    // ── Admin, per-teacher: rows 63, 64, 65, 66, 67 ─────────────────────

    /** GET /revenue/teachers — row 63. */
    public function teachers(ListTeacherPercentages $list): JsonResponse
    {
        return ApiResponse::success(['teachers' => $list()]);
    }

    /**
     * GET /revenue/statement/{teacherId} — row 64.
     *
     * THE SAME ACTION AS row 99, parameterised by the path instead of by the
     * session. Not a copy of the computation — a call to it.
     */
    public function teacherStatement(string $teacherId, TeacherStatement $statement): JsonResponse
    {
        return ApiResponse::success(['statement' => $statement($teacherId)]);
    }

    /** GET /revenue/transactions/{teacherId} — row 65. */
    public function teacherTransactions(string $teacherId, ListRevenueTransactions $list): JsonResponse
    {
        return ApiResponse::success(['transactions' => $list(teacherId: $teacherId)]);
    }

    /** GET /revenue/payouts/{teacherId} — row 66. */
    public function teacherPayouts(string $teacherId): JsonResponse
    {
        return ApiResponse::success(['payouts' => $this->payoutsFor($teacherId)]);
    }

    /**
     * PUT /revenue/teachers/{teacherId}/percentage — row 67.
     *
     * Changes the rate for FUTURE sales only. Every already-recorded
     * `teacher_earnings` is a snapshot and stays exactly as it is — see
     * App\Actions\Revenue\UpdateTeacherPercentage's banner.
     */
    public function updateTeacherPercentage(
        UpdateTeacherPercentageRequest $request,
        UpdateTeacherPercentage $update,
    ): JsonResponse {
        return ApiResponse::success(
            ['result' => $update($request->teacherId(), $request->percentage())],
            'تم تحديث نسبة الأرباح'
        );
    }

    // ── Shared internals ────────────────────────────────────────────────

    /**
     * The three report filters, whitelisted BY ITERATING A FIXED LIST rather
     * than by passing `$request->query()` through — an unknown query parameter
     * must be inert, not a filter. Same construction as
     * AdminDashboardController::subscriptions().
     *
     * A blank or absent value means "no filter", so Revenue.tsx's initial
     * unfiltered render keeps its exact previous behaviour. The names are
     * RevenueTransaction::FILTER_KEYS so this whitelist and the scope that
     * consumes it cannot list different things.
     *
     * @return array<string, string>
     */
    private function transactionFilters(Request $request): array
    {
        $filters = [];

        foreach (RevenueTransaction::FILTER_KEYS as $key) {
            $value = trim((string) ($request->query($key) ?? ''));

            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * `SELECT * FROM payouts WHERE teacher_id = ? ORDER BY created_at DESC` —
     * backend/RevenueRepository::payoutsForTeacher(), key for key. Shared by
     * rows 66 and 101 so the admin's view of a teacher's payouts and that
     * teacher's own view are the same list by construction.
     *
     * @return list<array<string, mixed>>
     */
    private function payoutsFor(string $teacherId): array
    {
        return Payout::query()
            ->where('teacher_id', $teacherId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Payout $payout): array => [
                'id' => (string) $payout->getKey(),
                'teacher_id' => $payout->teacher_id,
                'amount' => $payout->amount,
                'payment_method' => $payout->payment_method,
                'notes' => $payout->notes,
                'created_by' => $payout->created_by,
                'created_at' => $payout->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * backend/ returned the whole `refunds` row from findRefund(); the six
     * columns are reproduced exactly. `status` is unwrapped from its enum
     * cast — RefundManagement.tsx compares it to string literals.
     *
     * @return array<string, mixed>
     */
    private function presentRefund(Refund $refund): array
    {
        return [
            'id' => (string) $refund->getKey(),
            'revenue_transaction_id' => $refund->revenue_transaction_id,
            'amount' => $refund->amount,
            'reason' => $refund->reason,
            'status' => $refund->status instanceof RefundStatus
                ? $refund->status->value
                : (string) $refund->status,
            'created_at' => $refund->created_at?->toDateTimeString(),
        ];
    }

    /**
     * The acting user's id. `user()` cannot be null on any route in this class
     * — every one of them is behind `auth` — so this is a type narrowing, not
     * a guard, and it is written once rather than at nine call sites.
     */
    private function sessionId(Request $request): string
    {
        return (string) $request->user()?->getKey();
    }
}
