<?php

declare(strict_types=1);

namespace App\Actions\Revenue;

use App\Models\RevenueTransaction;
use App\Models\TeacherProfitSetting;
use App\Support\SecurityLog;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ONE PLACE `revenue_transactions` IS EVER WRITTEN.                    ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Two call sites in Stage 7 / Session A — enrollment-code redemption and the
 * admin's manual grant — and they differ only in `payment_method`. Session B
 * adds no writer; its routes are reads plus refunds/payouts, which are their
 * own tables.
 *
 * ┌── THE SPLIT IS COMPUTED HERE, AND STORED. ───────────────────────────────┐
 * │                                                                          │
 * │ `teacher_earnings` = round(gross_amount * percentage / 100, 2)           │
 * │                                                                          │
 * │ and BOTH the result and the `percentage` that produced it are written    │
 * │ onto the ledger row. See App\Models\RevenueTransaction's banner for why  │
 * │ deriving them at read time is an accounting bug rather than a style      │
 * │ choice.                                                                  │
 * │                                                                          │
 * │ THE ROUNDING IS EXPLICIT ON PURPOSE. `teacher_earnings` is DECIMAL(10,2) │
 * │ and MySQL would round an over-precise value on insert anyway — but it    │
 * │ would do so under whatever rounding mode the server is configured with,  │
 * │ and the value PHP believes it wrote would differ from the value the      │
 * │ database holds until the row is re-read. round() makes the number the    │
 * │ application asserts and the number stored identical by construction.     │
 * │ 33.33% of 100.00 is 33.33 here and 33.33 in MySQL, not 33.33 here and    │
 * │ 33.330000000000005 in a PHP-side comparison.                             │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── PRECEDENCE OF THE PERCENTAGE, IN THIS ORDER AND NO OTHER. ─────────────┐
 * │   1. an explicitly supplied `teacher_profit_percentage` (no caller in    │
 * │      this session supplies one; the branch exists because backend/       │
 * │      RevenueRepository::createTransaction() has it and a future          │
 * │      promotional/override path is the reason it does)                    │
 * │   2. teacher_profit_settings.profit_percentage for this teacher          │
 * │   3. DEFAULT_PROFIT_PERCENTAGE                                            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO TRANSACTION IS OPENED HERE. Both callers already run inside
 * DB::transaction() because the subscription write and this write must commit
 * or fail together; opening a second one would be a no-op savepoint that
 * obscures where the real boundary is. This Action must therefore NEVER be
 * invoked outside a transaction that also owns the enrollment it records.
 */
final class RecordRevenueTransaction
{
    /**
     * The share an instructor keeps when `teacher_profit_settings` has no row
     * for them.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THIS NUMBER IS A MIRROR OF A SCHEMA DEFAULT, NOT AN INDEPENDENT      │
     * │ POLICY. `teacher_profit_settings.profit_percentage` is declared      │
     * │ DECIMAL(5,2) NOT NULL DEFAULT '70.00' (migration                     │
     * │ 2026_01_01_000027, verified live). The two must agree: if they       │
     * │ diverge, a teacher's earnings change the instant an admin first      │
     * │ opens their profit settings and saves without editing anything —     │
     * │ a row appears with the DB default and the "no row" default stops     │
     * │ applying. Changing one obliges changing the other.                   │
     * │                                                                      │
     * │ It is a named constant precisely so that grep finds both ends. It    │
     * │ appeared as a bare `70.0` literal in backend/RevenueRepository::     │
     * │ profitPercentageFor() with nothing tying it to the migration.        │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public const DEFAULT_PROFIT_PERCENTAGE = 70.0;

    /**
     * @param array{
     *     subscription_id?: string|null,
     *     student_id: string,
     *     course_id: string,
     *     teacher_id: string,
     *     gross_amount: float|string,
     *     payment_method: string,
     *     status?: string,
     *     teacher_profit_percentage?: float|string|null
     * } $data
     */
    public function __invoke(array $data): RevenueTransaction
    {
        $percentage = $this->percentageFor($data);

        $gross = (float) $data['gross_amount'];
        $earnings = round($gross * ($percentage / 100), 2);

        /*
         * array_merge, NOT the `+` union operator. `+` keeps the LEFT-hand
         * value for a key present on both sides — so a caller that passed
         * `teacher_profit_percentage => null` (the "let the Action decide"
         * spelling) would have that null win over the resolved rate and the
         * column would go back to being NULL, which is the exact defect this
         * Action exists to end. The computed values must be last and must win.
         */
        $transaction = RevenueTransaction::query()->create(array_merge($data, [
            'status' => $data['status'] ?? RevenueTransaction::STATUS_PAID,
            // The rate AS OF THIS MOMENT, frozen onto the row.
            'teacher_profit_percentage' => $percentage,
            'teacher_earnings' => $earnings,
        ]));

        /*
         * The percentage and the earnings are IN THE LOG CONTEXT deliberately:
         * this is the first release in which they are non-trivial, and an
         * auditor reconciling a payout has to be able to see, per sale, which
         * rate was applied without querying the ledger. Same both-channels
         * treatment backend/Logger::critical_operation() gave it.
         */
        SecurityLog::criticalOperation('Revenue transaction recorded', [
            'event' => 'revenue.transaction_recorded',
            'transaction_id' => $transaction->getKey(),
            'subscription_id' => $data['subscription_id'] ?? null,
            'student_id' => $data['student_id'],
            'course_id' => $data['course_id'],
            'teacher_id' => $data['teacher_id'],
            'gross_amount' => $gross,
            'payment_method' => $data['payment_method'],
            'teacher_profit_percentage' => $percentage,
            'teacher_earnings' => $earnings,
        ]);

        return $transaction;
    }

    /**
     * `?? ` IS NOT ENOUGH HERE, AND THE REASON IS `0`.
     *
     * A teacher on a 0% share is a legitimate, constraint-permitted state
     * (`chk_profit_settings_percentage` allows `>= 0`), and `0.00` is a
     * perfectly real stored value. Writing this as
     * `$explicit ?: $stored ?: 70.0` — the shape that reads naturally — would
     * treat that teacher as "unset" twice over and silently pay them 70% of
     * every sale. Only a genuine NULL (no row at all) may fall through, so
     * every step tests `!== null` explicitly.
     *
     * `value()` returns null when no row matches, which is exactly the
     * distinction needed and is why it is preferred over first()->x.
     *
     * @param array<string, mixed> $data
     */
    private function percentageFor(array $data): float
    {
        $explicit = $data['teacher_profit_percentage'] ?? null;

        if ($explicit !== null) {
            return (float) $explicit;
        }

        $stored = TeacherProfitSetting::query()
            ->whereKey($data['teacher_id'])
            ->value('profit_percentage');

        return $stored !== null ? (float) $stored : self::DEFAULT_PROFIT_PERCENTAGE;
    }
}
