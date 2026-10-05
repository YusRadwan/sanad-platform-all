<?php

declare(strict_types=1);

namespace App\Actions\Revenue;

use App\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;

/**
 * GET /api/v1/revenue/teachers — freeze-table row 63.
 *
 * One row per instructor: their name, their CURRENT profit share, and what the
 * platform still owes them.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ TWO INDEPENDENT ONE-TO-MANY AGGREGATES. A JOIN HERE IS A FAN-OUT BUG.   ║
 * ║                                                                          ║
 * ║ `available_balance` = SUM(paid teacher_earnings) - SUM(payouts.amount),  ║
 * ║ and those two sums walk two unrelated child tables. Joining both onto    ║
 * ║ `teachers` in one pass yields their CROSS PRODUCT — a teacher with 3     ║
 * ║ transactions and 2 payouts produces 6 rows, so the earnings sum is       ║
 * ║ doubled and the payouts sum tripled, and the balance is wrong in BOTH    ║
 * ║ directions at once. This is the same defect backend/ shipped on          ║
 * ║ courses/overview (see App\Actions\Enrollment\CoursesOverview), one table ║
 * ║ over.                                                                    ║
 * ║                                                                          ║
 * ║ `withSum()` compiles to a CORRELATED SUBQUERY in the SELECT list, never  ║
 * ║ to a join, so each aggregate is computed over its own row set and is     ║
 * ║ structurally incapable of being multiplied by its sibling's cardinality. ║
 * ║ backend/RevenueRepository::listTeacherPercentages() reached the same     ║
 * ║ shape by hand-writing two SELECT-list subqueries; this is that query     ║
 * ║ without the SQL. ADDING A `->join('payouts', …)` HERE FOR ANY REASON     ║
 * ║ REINTRODUCES IT.                                                         ║
 * ║                                                                          ║
 * ║ Pinned by TeacherPercentagesTest::                                       ║
 * ║ test_three_transactions_and_two_payouts_do_not_multiply.                 ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── TWO FIELDS HERE EXIST TO FIX TWO REAL, DOCUMENTED DEAD FIELDS. ────────┐
 * │                                                                          │
 * │ 1. `name` DUPLICATES `full_name`. backend/'s own comment records why:    │
 * │    every admin consumer of this endpoint (ProfitEngine.tsx,              │
 * │    TeacherPayouts.tsx, InstructorStatement.tsx) renders `teacher.name`   │
 * │    per the RevenueTeacherSummary interface in types/payment.ts, while    │
 * │    the query only ever selected `full_name`. All three screens showed a  │
 * │    blank teacher name and an empty <option> label. Consolidating the two │
 * │    keys would re-blank them with nothing failing to say why.             │
 * │                                                                          │
 * │ 2. `available_balance` IS A REAL COMPUTED FIELD. It is part of that same │
 * │    interface and TeacherPayouts.tsx renders it as "الرصيد المتاح", but   │
 * │    it was never selected, so the frontend's `?? 0` fallback fired on     │
 * │    every row and the column read 0 for everyone, forever. It is the same │
 * │    figure App\Actions\Revenue\TeacherStatement calls `balance` — and     │
 * │    since decision #2 that is now true to the cent, because both sides    │
 * │    filter earnings through RevenueTransaction::scopePaid(). See that     │
 * │    class's banner.                                                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `profit_percentage` FALLS BACK TO RecordRevenueTransaction::
 * DEFAULT_PROFIT_PERCENTAGE, not to a literal 70 — a teacher has no
 * `teacher_profit_settings` row until an admin first saves one, and the schema
 * default and the application default must be greppable to each other.
 *
 * INNER JOIN SEMANTICS ARE PRESERVED via scopeWithLiveUser(): backend/ used
 * `INNER JOIN users u ON u.id = t.user_id` with no `deleted_at` clause, so a
 * soft-deleted teacher DID appear here with their name intact. whereHas('user')
 * applies User's SoftDeletes global scope inside the subquery, so they no
 * longer do — the same accepted correction as decision #4 and open-decision
 * 7.3. Documented in docs/open-decisions.md (7.11); a teacher whose account is
 * gone cannot be paid, so listing them on a payout screen was never useful.
 */
final class ListTeacherPercentages
{
    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(): array
    {
        return Teacher::query()
            ->withLiveUser()
            ->with(['user:id,full_name,avatar_url', 'profitSetting'])
            ->withSum(
                ['revenueTransactions as earned_sum' => fn (Builder $q): Builder => $q->paid()],
                'teacher_earnings'
            )
            ->withSum('payouts as paid_out_sum', 'amount')
            ->get()
            ->map(function (Teacher $teacher): array {
                // COALESCE to 0 on BOTH sides: withSum yields NULL, not 0, for
                // a teacher with no rows on that edge, and `null - null` in PHP
                // is 0 only by accident of juggling. A brand-new teacher must
                // report 0.0, not null — TeacherPayouts.tsx formats this value
                // as currency.
                $earned = (float) ($teacher->getAttribute('earned_sum') ?? 0);
                $paidOut = (float) ($teacher->getAttribute('paid_out_sum') ?? 0);

                $percentage = $teacher->profitSetting?->profit_percentage;

                return [
                    'teacher_id' => (string) $teacher->getKey(),
                    'full_name' => $teacher->user?->full_name,
                    // NOT a typo. See the banner: three admin screens read it.
                    'name' => $teacher->user?->full_name,
                    // NEW — ProfitEngine.tsx shows each teacher's real photo
                    // instead of a bare name row.
                    'avatar_url' => $teacher->user?->avatar_url,
                    'profit_percentage' => $percentage !== null
                        ? (float) $percentage
                        : RecordRevenueTransaction::DEFAULT_PROFIT_PERCENTAGE,
                    'available_balance' => $earned - $paidOut,
                ];
            })
            ->all();
    }
}
