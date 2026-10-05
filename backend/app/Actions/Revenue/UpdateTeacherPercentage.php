<?php

declare(strict_types=1);

namespace App\Actions\Revenue;

use App\Models\TeacherProfitSetting;
use App\Support\SecurityLog;

/**
 * PUT /api/v1/revenue/teachers/{teacherId}/percentage — freeze-table row 67.
 * ProfitEngine.tsx's only write.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS CHANGES WHAT THE **NEXT** SALE PAYS. IT CHANGES NOTHING ALREADY     ║
 * ║ RECORDED, AND THAT IS THE POINT OF THE WHOLE LEDGER DESIGN.              ║
 * ║                                                                          ║
 * ║ `revenue_transactions.teacher_profit_percentage` and `teacher_earnings`  ║
 * ║ are SNAPSHOTS written by App\Actions\Revenue\RecordRevenueTransaction at ║
 * ║ the moment of sale (see RevenueTransaction's banner). Nothing in this    ║
 * ║ Action touches that table, and nothing may be added that does. An admin  ║
 * ║ moving a teacher from 60% to 40% must not retroactively rewrite what     ║
 * ║ that teacher earned on every sale they ever made — including sales       ║
 * ║ already PAID OUT, which would then reconcile against a number that no    ║
 * ║ longer exists anywhere.                                                  ║
 * ║                                                                          ║
 * ║ Session A pinned this at the Action layer (RevenueRecordingTest::        ║
 * ║ test_changing_the_percentage_afterwards_never_touches_a_recorded_row).   ║
 * ║ Session B pins it again at the HTTP layer, because THIS ROUTE is the     ║
 * ║ actual mutation an admin performs — a unit-level guarantee about a       ║
 * ║ method nobody calls from the outside is not the same guarantee:          ║
 * ║ TeacherPercentageUpdateTest::                                            ║
 * ║ test_updating_the_percentage_over_http_leaves_recorded_rows_untouched.   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * `updateOrCreate`, KEYED ON `teacher_id`, BECAUSE THE ROW MAY NOT EXIST.
 * `teacher_profit_settings.teacher_id` IS the primary key, and a teacher has
 * no row at all until this endpoint is first called for them — the 70% default
 * is implicit (RecordRevenueTransaction::DEFAULT_PROFIT_PERCENTAGE mirroring
 * the column's DEFAULT '70.00') and materialises only here. A plain
 * `->update()` would silently no-op for every teacher who has never been
 * edited, answering 200 while changing nothing. backend/ used
 * `INSERT … ON DUPLICATE KEY UPDATE` for exactly this reason.
 *
 * RANGE VALIDATION (0-100, 'النسبة يجب أن تكون بين 0 و100') LIVES IN
 * App\Http\Requests\Revenue\UpdateTeacherPercentageRequest. backend/ threw it
 * from the service because it had no validation layer to put it in; here 422
 * belongs to the Form Request, and the DB's `chk_profit_settings_percentage`
 * is the backstop.
 *
 * 0 IS A LEGITIMATE VALUE, not "unset". See
 * RecordRevenueTransaction::percentageFor() for why every read of this column
 * tests `!== null` rather than truthiness.
 */
final class UpdateTeacherPercentage
{
    /**
     * @return array{teacher_id: string, profit_percentage: float}
     */
    public function __invoke(string $teacherId, float $percentage): array
    {
        TeacherProfitSetting::query()->updateOrCreate(
            ['teacher_id' => $teacherId],
            ['profit_percentage' => $percentage],
        );

        SecurityLog::criticalOperation('Teacher profit percentage updated', [
            'event' => 'revenue.percentage_updated',
            'teacher_id' => $teacherId,
            'profit_percentage' => $percentage,
        ]);

        /*
         * backend/ echoed back the SUBMITTED value rather than re-reading the
         * stored one, and that is preserved. Re-reading would return the
         * DECIMAL(5,2) rounding of the input ("33.33" for 33.333), which is a
         * different number from the one ProfitEngine.tsx just sent and would
         * make the field appear to change under the user's cursor.
         */
        return [
            'teacher_id' => $teacherId,
            'profit_percentage' => $percentage,
        ];
    }
}
