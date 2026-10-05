<?php

declare(strict_types=1);

namespace App\Actions\Revenue;

use App\Models\Payout;
use App\Support\SecurityLog;

/**
 * POST /api/v1/revenue/payouts — freeze-table row 68.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ DELIBERATELY **NOT** WRAPPED IN DB::transaction(), AND THIS IS NOT AN    ║
 * ║ OVERSIGHT. backend/RevenueService::createPayout() carries the same       ║
 * ║ decision with the same reasoning, verbatim:                              ║
 * ║                                                                          ║
 * ║   "Verified single-table: createPayout() performs one INSERT into        ║
 * ║    `payouts` and nothing else. There is no denormalised balance column   ║
 * ║    anywhere — myStatement() derives balance on read as                   ║
 * ║    SUM(teacher_earnings) - SUM(payouts.amount) — so wrapping this in a   ║
 * ║    transaction would add ceremony without atomicity value."              ║
 * ║                                                                          ║
 * ║ A single INSERT is already atomic. The cost of "harmless" ceremony is    ║
 * ║ that it MISINFORMS: a transaction block is a claim that two or more      ║
 * ║ writes must land together, and the next reader will search for the       ║
 * ║ second write, find none, and either add one or leave the block in place  ║
 * ║ believing it protects something. Compare App\Actions\Revenue\            ║
 * ║ ProcessRefund, where the block is load-bearing and says so.              ║
 * ║                                                                          ║
 * ║ THE CONDITION UNDER WHICH THIS MUST CHANGE, stated so it is checkable:   ║
 * ║ the moment anything writes a stored balance, a payout-count, or any      ║
 * ║ other derived column alongside this INSERT, this Action needs a          ║
 * ║ transaction. Until then it must not have one. Pinned by                  ║
 * ║ PayoutTest::test_recording_a_payout_opens_no_transaction.                ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * A PAYOUT IS NOT VALIDATED AGAINST THE AVAILABLE BALANCE, matching backend/.
 * An admin may over-pay an instructor and the balance simply goes negative —
 * which is a real operational state (an advance, or a correction) and not an
 * error the API should refuse. The DB's `chk_payouts_amount_non_negative` and
 * the Form Request's `gt:0` bound the row itself; nothing bounds the running
 * total, on purpose.
 *
 * `created_by` IS THE ACTING ADMIN, taken from the session and never from the
 * body. There is no request field for it and there must not be one — an
 * audit column a caller can set is not an audit column.
 */
final class CreatePayout
{
    /**
     * @return array{id: string, teacher_id: string, amount: float}
     */
    public function __invoke(
        string $teacherId,
        float $amount,
        ?string $paymentMethod,
        ?string $note,
        string $createdBy,
    ): array {
        $payout = Payout::query()->create([
            'teacher_id' => $teacherId,
            'amount' => $amount,
            // backend/RevenueRepository::createPayout() defaults an absent
            // method to the literal 'manual'; the constant keeps that fact
            // greppable from the model that owns the column.
            'payment_method' => $paymentMethod ?? Payout::METHOD_MANUAL,
            'notes' => $note,
            'created_by' => $createdBy,
        ]);

        SecurityLog::criticalOperation('Payout created', [
            'event' => 'payout.created',
            'payout_id' => $payout->getKey(),
            'teacher_id' => $teacherId,
            'amount' => $amount,
            'payment_method' => $payout->payment_method,
            'created_by' => $createdBy,
        ]);

        /*
         * THE RESPONSE SHAPE IS backend/'s THREE KEYS, NOT THE WHOLE ROW.
         * createPayout() returned `['id', 'teacher_id', 'amount']` and nothing
         * else — no created_at, no notes, no payment_method. Widening it is
         * additive and safe in principle, but this is the payload
         * TeacherPayouts.tsx already handles and there is no consumer asking
         * for more.
         */
        return [
            'id' => (string) $payout->getKey(),
            'teacher_id' => $teacherId,
            'amount' => $amount,
        ];
    }
}
