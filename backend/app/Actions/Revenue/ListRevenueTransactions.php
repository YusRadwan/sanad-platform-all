<?php

declare(strict_types=1);

namespace App\Actions\Revenue;

use App\Models\RevenueTransaction;

/**
 * The ledger table, for all three routes that render one — freeze-table rows
 * 62 (`/revenue/transactions`, filtered, platform-wide), 65
 * (`/revenue/transactions/{teacherId}`, admin) and 100
 * (`/revenue/my-transactions`, the session's own).
 *
 * ┌── THE THREE RESOLVED NAMES ARE NOT DECORATION. ──────────────────────────┐
 * │ `revenue_transactions` stores only ids, but Revenue.tsx,                 │
 * │ RefundManagement.tsx and InstructorStatement.tsx all render              │
 * │ `tx.student_name` / `tx.course_name` / `tx.teacher_name` (the            │
 * │ RevenueTransaction interface in types/payment.ts) AND filter their       │
 * │ search boxes on those three fields. backend/RevenueRepository's          │
 * │ TRANSACTION_SELECT records that a bare `SELECT *` left every one of      │
 * │ those cells blank and made the search match nothing. They ship here for  │
 * │ the same reason, and the raw ids stay in the payload untouched.          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * EAGER-LOADED, NOT JOINED. Three `with()` relations produce three extra
 * queries regardless of row count; the LEFT JOINs backend/ used would be
 * equivalent here (they are many-to-one, so no fan-out is possible) but the
 * relations already exist and N+1 is what a naive `->course->title` in the map
 * would cost. All three loads tolerate a deleted row: `studentAccount` and
 * `teacherAccount` are declared `withTrashed()` and the course is loaded
 * `withTrashed()` at this call site — matching backend/'s unfiltered LEFT
 * JOINs, which happily named a soft-deleted course. A blank course title on an
 * accounting screen reads as data loss, not as a deletion.
 *
 * ORDER `created_at DESC`, byte for byte backend/'s. NOTE the ties: bulk
 * enrolment writes several ledger rows inside the same second and DATETIME has
 * no sub-second precision here, so their relative order is not deterministic.
 * backend/ had the same property; no secondary key is added because inventing
 * one would change an order the frontend already renders.
 */
final class ListRevenueTransactions
{
    /**
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public function __invoke(array $filters = [], ?string $teacherId = null): array
    {
        $query = RevenueTransaction::query()
            ->with([
                'studentAccount:id,full_name',
                'teacherAccount:id,full_name',
                'course' => fn ($q) => $q->withTrashed()->select(['id', 'title']),
            ])
            // The ONE filter expression, shared with PlatformSummary. See
            // RevenueTransaction::scopeFiltered()'s banner for why the summary
            // cards and this table may never grow separate copies.
            ->filtered($filters);

        if ($teacherId !== null) {
            $query->where('teacher_id', $teacherId);
        }

        return $query
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (RevenueTransaction $tx): array => [
                'id' => (string) $tx->getKey(),
                'subscription_id' => $tx->subscription_id,
                'student_id' => $tx->student_id,
                'course_id' => $tx->course_id,
                'teacher_id' => $tx->teacher_id,
                'gross_amount' => $tx->gross_amount,
                'payment_method' => $tx->payment_method,
                'status' => $tx->status,
                'teacher_profit_percentage' => $tx->teacher_profit_percentage,
                'teacher_earnings' => $tx->teacher_earnings,
                'created_at' => $tx->created_at?->toDateTimeString(),
                'student_name' => $tx->studentAccount?->full_name,
                'course_name' => $tx->course?->title,
                'teacher_name' => $tx->teacherAccount?->full_name,
            ])
            ->all();
    }
}
