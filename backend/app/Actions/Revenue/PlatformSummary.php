<?php

declare(strict_types=1);

namespace App\Actions\Revenue;

use App\Enums\RefundStatus;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\RevenueTransaction;

/**
 * GET /api/v1/revenue/platform — freeze-table row 61. The six summary cards at
 * the top of Revenue.tsx.
 *
 * ┌── FOUR FIGURES RESPECT THE DATE RANGE. TWO DELIBERATELY DO NOT. ─────────┐
 * │                                                                          │
 * │   gross_revenue           filtered + status='paid'                       │
 * │   teacher_earnings_total  filtered + status='paid'                       │
 * │   platform_share          derived from the two above                     │
 * │   transactions_count      filtered + status='paid'                       │
 * │   payouts_total           ⚠ WHOLE TABLE, NO DATE FILTER                  │
 * │   refunds_total           ⚠ WHOLE TABLE, NO DATE FILTER                  │
 * │                                                                          │
 * │ THIS ASYMMETRY IS backend/'s ACTUAL BEHAVIOUR AND IS KEPT ON PURPOSE     │
 * │ (Session B decision #3). backend/RevenueRepository::platformSummary()    │
 * │ builds the filtered WHERE for the ledger and then issues two bare        │
 * │ `SELECT COALESCE(SUM(amount),0)` queries with no filter at all — an      │
 * │ undocumented but entirely real property that anyone reading the four     │
 * │ filtered figures would assume applies to all six.                        │
 * │                                                                          │
 * │ It is NOT "fixed" here, and it is not left to be rediscovered either:    │
 * │ PlatformSummaryTest::                                                    │
 * │ test_payouts_and_refunds_totals_ignore_the_date_range_on_purpose moves   │
 * │ the date window and asserts gross_revenue CHANGES while payouts_total    │
 * │ and refunds_total stay CONSTANT. A future maintainer who "corrects" the  │
 * │ inconsistency gets a failing test that explains itself, rather than a    │
 * │ silent change to two numbers the finance screen has always shown.        │
 * │ Recorded in docs/open-decisions.md (7.9).                                │
 * │                                                                          │
 * │ Why it would be a real change and not a tidy-up: `payouts` and `refunds` │
 * │ carry their OWN `created_at`, unrelated to the sale's. A payout made in  │
 * │ April settling March's sales belongs to neither month unambiguously,     │
 * │ and picking one is a product decision about what "payouts in this        │
 * │ period" means — not something to slip in during a rewrite.               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `platform_share` IS A REAL NUMBER FOR THE FIRST TIME. It is
 * `gross - teacher_earnings_total`, which was arithmetically meaningless while
 * `teacher_earnings` was unpopulated; Session A's RecordRevenueTransaction is
 * what made the subtrahend real. Derived, never stored — there is no
 * `platform_share` column and there must not be one, or it becomes a third
 * number that can disagree with the two it is made of.
 *
 * `refunds_total` COUNTS APPROVED REFUNDS ONLY. A pending request is not money
 * that has moved; including it would show the platform having refunded sums it
 * may yet reject. backend/ filtered `WHERE status = 'approved'` and so does
 * this. NOTE this is deliberately NARROWER than Refund::scopeBlocking(), which
 * is pending-OR-approved — that scope answers "may a new request be raised?",
 * a different question with a different answer, and conflating the two would
 * either double-count money or allow double refunds.
 */
final class PlatformSummary
{
    /**
     * @param array<string, string> $filters
     * @return array{
     *     gross_revenue: float,
     *     teacher_earnings_total: float,
     *     platform_share: float,
     *     transactions_count: int,
     *     payouts_total: float,
     *     refunds_total: float
     * }
     */
    public function __invoke(array $filters = []): array
    {
        /*
         * ->filtered() is the SAME scope App\Actions\Revenue\
         * ListRevenueTransactions applies, which is the entire reason it is a
         * scope. Revenue.tsx renders these cards directly above that table
         * with one shared filter bar; if the two ever compiled different WHERE
         * clauses the screen would contradict itself with no error anywhere.
         *
         * ->paid() is composed ON TOP rather than folded into the scope,
         * because the table below deliberately DOES show refunded rows. See
         * scopeFiltered()'s banner.
         */
        $ledger = RevenueTransaction::query()->filtered($filters)->paid();

        // ONE pass over the filtered set, not three. Three separate ->sum()
        // calls would re-scan and, worse, could disagree with each other if a
        // write landed between them.
        $row = (clone $ledger)
            ->selectRaw('COALESCE(SUM(gross_amount), 0) AS gross')
            ->selectRaw('COALESCE(SUM(teacher_earnings), 0) AS teacher_total')
            ->selectRaw('COUNT(*) AS tx_count')
            ->first();

        $gross = (float) ($row?->getAttribute('gross') ?? 0);
        $teacherTotal = (float) ($row?->getAttribute('teacher_total') ?? 0);

        return [
            'gross_revenue' => $gross,
            'teacher_earnings_total' => $teacherTotal,
            'platform_share' => $gross - $teacherTotal,
            'transactions_count' => (int) ($row?->getAttribute('tx_count') ?? 0),
            // ⚠ UNFILTERED ON PURPOSE — decision #3. See the banner above.
            'payouts_total' => (float) Payout::query()->sum('amount'),
            'refunds_total' => (float) Refund::query()
                ->where('status', RefundStatus::Approved->value)
                ->sum('amount'),
        ];
    }
}
