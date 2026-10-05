<?php

declare(strict_types=1);

namespace App\Actions\Revenue;

use App\Enums\RefundStatus;
use App\Exceptions\NotFoundException;
use App\Models\Refund;
use App\Models\RevenueTransaction;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/revenue/refunds — the PROCESS branch of freeze-table row 70.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ APPROVAL IS TRANSACTIONAL. REJECTION IS NOT. THE ASYMMETRY IS THE WHOLE  ║
 * ║ DESIGN, AND IT IS backend/'s — INCLUDING ITS REASONING, REPRODUCED HERE  ║
 * ║ BECAUSE A LATER READER WILL OTHERWISE "TIDY" ONE SIDE INTO THE OTHER.    ║
 * ║                                                                          ║
 * ║ APPROVING moves TWO rows in TWO tables:                                  ║
 * ║     refunds.status              -> 'approved'                            ║
 * ║     revenue_transactions.status -> 'refunded'                            ║
 * ║                                                                          ║
 * ║ Non-atomically, a failure between them leaves a refund marked approved   ║
 * ║ while the sale is still 'paid'. PlatformSummary then counts that money   ║
 * ║ TWICE — once inside `gross_revenue`/`teacher_earnings_total` (because    ║
 * ║ scopePaid still matches the ledger row) and once inside `refunds_total`  ║
 * ║ — and TeacherStatement keeps crediting the instructor earnings on a sale ║
 * ║ that was refunded. Money counted in two directions at once, with no      ║
 * ║ error anywhere.                                                          ║
 * ║                                                                          ║
 * ║ REJECTING touches ONE row in ONE table. A single statement is already    ║
 * ║ atomic; DB::transaction() around it would add a savepoint, a commit and  ║
 * ║ a rollback path that can never be exercised, and — worse — would tell    ║
 * ║ the next reader that there is a second write here to look for.           ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * INVALID STATUS IS 422 WITH THE LITERAL 'حالة غير صحيحة'. That check lives in
 * App\Http\Requests\Revenue\SubmitRefundRequest (an `in:` rule with that
 * message) rather than here, for the same layering reason RequestRefund's
 * guard does: 422 is validation's status, and keeping it out of this class
 * means this class has exactly one failure mode — 404 for an unknown refund.
 *
 * 'Refund not found' is the literal backend/ throws and the string the freeze
 * table pins for row 69/70.
 *
 * ┌── RE-PROCESSING AN ALREADY-PROCESSED REFUND IS NOT BLOCKED. ─────────────┐
 * │ backend/ has no such guard and one is NOT added: approving an already-   │
 * │ approved refund re-writes the same two values to the same two values,    │
 * │ which is idempotent and harmless, and REJECTING an approved refund is a  │
 * │ legitimate correction path an admin may need (it releases the            │
 * │ transaction from Refund::scopeBlocking so a fresh request can be         │
 * │ raised). NOTE the one edge this leaves: rejecting an approved refund     │
 * │ does NOT move `revenue_transactions.status` back to 'paid' — the sale    │
 * │ stays refunded. backend/ behaves identically. Recorded in                │
 * │ docs/open-decisions.md (7.10) rather than repaired here, because         │
 * │ "un-refunding" a sale is a product decision about money, not a rewrite   │
 * │ detail.                                                                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class ProcessRefund
{
    public const REFUND_NOT_FOUND = 'Refund not found';

    public function __invoke(string $refundId, RefundStatus $status): Refund
    {
        $refund = Refund::query()->find($refundId)
            ?? throw new NotFoundException(self::REFUND_NOT_FOUND);

        /*
         * THE ONE BRANCH. Written as an if/else over a single boolean rather
         * than as two ->when() calls or a match, so the two transactionality
         * decisions sit on adjacent lines and a diff that changes one is
         * visibly missing the other.
         */
        if ($status === RefundStatus::Approved) {
            DB::transaction(function () use ($refund): void {
                $refund->update(['status' => RefundStatus::Approved]);

                /*
                 * The MONEY COLUMNS ARE NOT TOUCHED. `gross_amount`,
                 * `teacher_profit_percentage` and `teacher_earnings` stay
                 * exactly as recorded — the snapshot is what an auditor
                 * reconciles against (RevenueTransaction's banner) and zeroing
                 * it would destroy the record that the sale ever happened. The
                 * STATUS is what removes the row from every report, via
                 * scopePaid().
                 */
                RevenueTransaction::query()
                    ->whereKey($refund->revenue_transaction_id)
                    ->update(['status' => RevenueTransaction::STATUS_REFUNDED]);
            });
        } else {
            // Single write, single table. Deliberately unwrapped — see banner.
            $refund->update(['status' => $status]);
        }

        SecurityLog::criticalOperation('Refund processed', [
            'event' => 'refund.' . $status->value,
            'refund_id' => $refund->getKey(),
            'revenue_transaction_id' => $refund->revenue_transaction_id,
            'amount' => $refund->amount,
            'status' => $status->value,
        ]);

        return $refund->refresh();
    }
}
