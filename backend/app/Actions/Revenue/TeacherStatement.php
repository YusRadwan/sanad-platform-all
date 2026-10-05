<?php

declare(strict_types=1);

namespace App\Actions\Revenue;

use App\Models\Payout;
use App\Models\RevenueTransaction;
use App\Models\TeacherProfitSetting;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ ONE COMPUTATION, TWO ROUTES — freeze-table rows 64 and 99.               ║
 * ║                                                                          ║
 * ║   GET /revenue/statement/{teacherId}   admin, teacher named in the path  ║
 * ║   GET /revenue/my-statement            instructor, teacher = the session ║
 * ║                                                                          ║
 * ║ backend/RevenueService::teacherStatement() is literally                  ║
 * ║ `return $this->myStatement($teacherId);` — the two answers must never be ║
 * ║ able to differ, because an instructor phoning support about their        ║
 * ║ balance and the admin reading it back are looking at the same number by  ║
 * ║ contract. Delegation, not duplication, is what makes that structural.    ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── DECISION #2 (SESSION B): `status = 'paid'` IS NOW APPLIED HERE. ───────┐
 * │ THIS IS AN APPROVED BEHAVIOUR CHANGE FROM backend/, not a port.          │
 * │                                                                          │
 * │ backend/myStatement() summed `transactionsForTeacher()`, which selects   │
 * │ EVERY row for the teacher with no status clause, while backend/          │
 * │ listTeacherPercentages() computed the admin-facing `available_balance`   │
 * │ with `AND rt.status = 'paid'`. So a refunded sale counted toward the     │
 * │ instructor's own "total_earnings"/"balance" and did NOT count toward the │
 * │ admin's "الرصيد المتاح" for the same teacher — two screens in one        │
 * │ product disagreeing about what the platform owes, with neither marked as │
 * │ the authority.                                                           │
 * │                                                                          │
 * │ Both now call RevenueTransaction::scopePaid(), the canonical predicate   │
 * │ Session A declared for exactly this. Pinned by                           │
 * │ TeacherStatementTest::                                                   │
 * │ test_my_statement_and_the_admin_available_balance_agree_to_the_cent.     │
 * │                                                                          │
 * │ `transactions_count` MOVES WITH THE MONEY. Counting all rows while       │
 * │ summing only paid ones would produce a statement whose count and total   │
 * │ describe different sets — the very defect being closed.                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO DATE FILTERING, deliberately and matching backend/: neither route reads a
 * single query parameter. A statement is a running balance, not a period
 * report; scoping it to a date range would make "balance" mean "what we owed
 * you during March", which is not a number anyone can be paid.
 *
 * PAYOUTS ARE **NOT** STATUS-FILTERED — `payouts` has no status column. Every
 * row in that table is money that has already left the platform.
 */
final class TeacherStatement
{
    /**
     * @return array{
     *     total_earnings: float,
     *     total_paid_out: float,
     *     balance: float,
     *     profit_percentage: float,
     *     transactions_count: int
     * }
     */
    public function __invoke(string $teacherId): array
    {
        $earned = (float) RevenueTransaction::query()
            ->where('teacher_id', $teacherId)
            ->paid()
            ->sum('teacher_earnings');

        $paidOut = (float) Payout::query()
            ->where('teacher_id', $teacherId)
            ->sum('amount');

        return [
            'total_earnings' => $earned,
            'total_paid_out' => $paidOut,
            'balance' => $earned - $paidOut,
            'profit_percentage' => self::profitPercentageFor($teacherId),
            'transactions_count' => RevenueTransaction::query()
                ->where('teacher_id', $teacherId)
                ->paid()
                ->count(),
        ];
    }

    /**
     * The teacher's CURRENT share — the forward-looking rate, not the rate any
     * historical sale was settled at.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THIS IS THE ONLY NUMBER ON THE STATEMENT THAT IS NOT A SNAPSHOT, AND │
     * │ THAT IS CORRECT. `total_earnings` is the sum of per-row              │
     * │ `teacher_earnings` frozen at each sale (RevenueTransaction's         │
     * │ banner); `profit_percentage` is what the NEXT sale will pay. They    │
     * │ can legitimately disagree — a teacher moved from 60% to 40% today    │
     * │ sees 40% here over earnings computed at 60% — and reconciling them   │
     * │ by deriving earnings from this rate is the accounting bug the        │
     * │ snapshot design exists to prevent.                                   │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * `value()` returns null when NO ROW EXISTS, which is the distinction that
     * matters: a stored `0.00` is a real 0% share and must not fall through to
     * the default. Same `!== null` reasoning as
     * RecordRevenueTransaction::percentageFor(), and the default is that
     * class's constant rather than a second literal 70 — the two would drift.
     */
    public static function profitPercentageFor(string $teacherId): float
    {
        $stored = TeacherProfitSetting::query()
            ->whereKey($teacherId)
            ->value('profit_percentage');

        return $stored !== null
            ? (float) $stored
            : RecordRevenueTransaction::DEFAULT_PROFIT_PERCENTAGE;
    }
}
