<?php

declare(strict_types=1);

namespace App\Actions\Revenue;

use App\Enums\RefundStatus;
use App\Exceptions\NotFoundException;
use App\Models\Refund;
use App\Models\RevenueTransaction;
use App\Support\SecurityLog;

/**
 * POST /api/v1/revenue/refunds — the REQUEST branch of freeze-table row 70.
 *
 * See App\Http\Controllers\RevenueController::submitRefund() for why one route
 * carries two operations.
 *
 * ┌── THE AMOUNT IS ALWAYS THE FULL `gross_amount`. THERE ARE NO PARTIAL ────┐
 * │   REFUNDS, AND THE CALLER CANNOT SUPPLY ONE.                             │
 * │                                                                          │
 * │ backend/RevenueService::requestRefund() reads the amount off the         │
 * │ transaction it just loaded and never looks at the request body for it.   │
 * │ Preserved exactly — and the reason is not just fidelity: approval sets   │
 * │ the transaction's status to 'refunded', which removes its WHOLE          │
 * │ `gross_amount` and its WHOLE `teacher_earnings` from every report        │
 * │ (RevenueTransaction::scopePaid). A partial refund row would therefore    │
 * │ describe a partial reversal that the ledger executes in full. Supporting │
 * │ partials needs a second money column on the transaction, not a field on  │
 * │ this request.                                                            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * 404, NOT 422, FOR AN UNKNOWN TRANSACTION — 'Transaction not found', the
 * literal backend/ throws and the string the freeze table pins for row 70. An
 * `exists:` rule on the Form Request would answer 422 and change the contract.
 *
 * ┌── WHERE THE DOUBLE-REFUND GUARD IS **NOT**. ─────────────────────────────┐
 * │ The guard lives in App\Http\Requests\Revenue\SubmitRefundRequest as a    │
 * │ validation rule, because its answer is 422 and 422 is the validation     │
 * │ layer's status. Putting it here would mean this Action throws two        │
 * │ different statuses for two different pre-conditions, and the ORDER in    │
 * │ which it checked them would silently decide whether an unknown           │
 * │ transaction with a stale refund row answers 404 or 422. Split by layer,  │
 * │ the order is fixed by the framework: validation first, then the lookup.  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO TRANSACTION. One INSERT into one table; nothing else moves. Contrast
 * ProcessRefund, where approval moves two rows and IS wrapped.
 */
final class RequestRefund
{
    public const TRANSACTION_NOT_FOUND = 'Transaction not found';

    public function __invoke(string $transactionId, string $reason): Refund
    {
        $transaction = RevenueTransaction::query()->find($transactionId)
            ?? throw new NotFoundException(self::TRANSACTION_NOT_FOUND);

        $refund = Refund::query()->create([
            'revenue_transaction_id' => $transaction->getKey(),
            // Full gross. See the banner.
            'amount' => $transaction->gross_amount,
            /*
             * backend/ stores `(string) ($data['reason'] ?? '')` — an EMPTY
             * STRING, not NULL, when no reason is given. The column is
             * nullable and null would read better, but RefundManagement.tsx
             * renders this cell directly and '' vs null is a visible
             * difference in a table it already displays. Carried over.
             */
            'reason' => $reason,
            'status' => RefundStatus::Pending,
        ]);

        SecurityLog::criticalOperation('Refund requested', [
            'event' => 'refund.requested',
            'refund_id' => $refund->getKey(),
            'revenue_transaction_id' => $transaction->getKey(),
            'amount' => $transaction->gross_amount,
        ]);

        return $refund;
    }
}
