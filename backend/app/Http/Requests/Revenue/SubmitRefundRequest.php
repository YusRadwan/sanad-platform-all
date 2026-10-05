<?php

declare(strict_types=1);

namespace App\Http\Requests\Revenue;

use App\Enums\RefundStatus;
use App\Http\Requests\ApiFormRequest;
use App\Models\Refund;

/**
 * POST /api/v1/revenue/refunds — freeze-table row 70.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ ONE ROUTE, TWO OPERATIONS, DISCRIMINATED BY PAYLOAD SHAPE. THIS IS       ║
 * ║ backend/'s CONTRACT AND IT IS PRESERVED EXACTLY.                         ║
 * ║                                                                          ║
 * ║   body has `transactionId` -> REQUEST a refund   -> 201                  ║
 * ║   otherwise                -> PROCESS a request  -> 200                  ║
 * ║                                                                          ║
 * ║ backend/RevenueController::submitRefund() branches on                    ║
 * ║ `isset($data['transactionId'])` and nothing else. It is not a shape this ║
 * ║ rewrite would choose — two verbs on one path is exactly what separate    ║
 * ║ routes exist for — but refundApiService.ts posts both to this URL and    ║
 * ║ splitting it is an API break, not a refactor.                            ║
 * ║                                                                          ║
 * ║ THE DISCRIMINATOR IS RE-IMPLEMENTED, NOT DELEGATED TO A HELPER, BECAUSE  ║
 * ║ NO LARAVEL HELPER MATCHES `isset()` ON BOTH EDGES:                       ║
 * ║                                                                          ║
 * ║   body                        isset()  has()  filled()  THIS CLASS       ║
 * ║   {"transactionId": "abc"}    true     true   true      REQUEST          ║
 * ║   {"transactionId": null}     FALSE    TRUE   false     PROCESS          ║
 * ║   {"transactionId": ""}       true     true   FALSE     REQUEST          ║
 * ║                                                                          ║
 * ║ `has()` would flip the null case and `filled()` would flip the empty-    ║
 * ║ string case — and flipping either does not produce a validation error,   ║
 * ║ it runs THE OTHER OPERATION. isRefundRequest() below therefore spells    ║
 * ║ out `!== null`, which is exactly `isset()`'s rule, so the branch point   ║
 * ║ is written down rather than inherited from a helper's edge case.         ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── THE DOUBLE-REFUND GUARD (Session B decision #1) — NEW, NOT A PORT. ────┐
 * │ backend/ has NO protection against raising a second refund against a     │
 * │ transaction that already has one in flight. An admin could stack five    │
 * │ pending requests on one sale; approving them later adds the same         │
 * │ `gross_amount` to `refunds_total` FIVE TIMES while the ledger row moves  │
 * │ to 'refunded' exactly once. The platform's own summary then reports      │
 * │ having refunded more than it ever earned, with nothing failing.          │
 * │                                                                          │
 * │ Same silent-failure class Stage 6 found on `assignment_submissions`:     │
 * │ a missing uniqueness rule that produces wrong NUMBERS rather than an     │
 * │ error, so it is invisible until someone reconciles by hand.              │
 * │                                                                          │
 * │ THE PREDICATE IS Refund::scopeBlocking(), Session A's — pending OR       │
 * │ approved — and it is NOT re-expressed inline here. A REJECTED refund     │
 * │ deliberately does not block: rejection is the signal that the request    │
 * │ was not legitimate, so a corrected re-request is the intended flow. See  │
 * │ that scope's banner.                                                     │
 * │                                                                          │
 * │ It is a `Rule::` closure and not a check inside the Action because its   │
 * │ answer is 422 and the Action's only failure mode is 404. See             │
 * │ App\Actions\Revenue\RequestRefund's banner for why splitting them by     │
 * │ layer is what fixes the ordering question rather than deferring it.      │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO `exists:` RULE ON `transactionId` OR `id`. Both unknown-row answers are
 * 404s with their own literal strings ('Transaction not found', 'Refund not
 * found') thrown by the Actions; an `exists` rule would answer 422 and
 * collapse two pinned contract answers into one. Same reasoning as
 * ToggleEnrollmentRequest.
 *
 * THREE SHAPES THAT USED TO BE 500 ARE NOW 422 — a body with neither
 * `transactionId` nor `id`, one with `id` but no `status`, and one with a
 * `status` outside the enum. backend/ read `$data['id']` and `$data['status']`
 * with no null-coalescing (an "Undefined array key" warning and a 500) and
 * validated the status only after that. Same 500→422 correction as
 * open-decisions 7.1 and 5.2; no request that previously SUCCEEDED behaves
 * differently.
 */
final class SubmitRefundRequest extends ApiFormRequest
{
    /** Decision #1's refusal. New string — backend/ had no such answer. */
    public const ALREADY_REFUNDING = 'يوجد طلب استرداد قائم بالفعل لهذه المعاملة';

    /** backend/RevenueService::processRefund()'s literal, byte for byte. */
    public const INVALID_STATUS = 'حالة غير صحيحة';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->isRefundRequest()) {
            return [
                'transactionId' => [
                    'required',
                    'string',
                    function (string $attribute, mixed $value, callable $fail): void {
                        $blocked = Refund::query()
                            ->blocking()
                            ->where('revenue_transaction_id', (string) $value)
                            ->exists();

                        if ($blocked) {
                            $fail(self::ALREADY_REFUNDING);
                        }
                    },
                ],
                // Free text. backend/ never validated it and stores '' when
                // absent — see RequestRefund's note on '' vs null.
                'reason' => ['nullable', 'string'],
            ];
        }

        return [
            'id' => ['required', 'string'],
            'status' => [
                'required',
                'string',
                // 'pending' is NOT accepted: backend/ allows only these two.
                'in:' . RefundStatus::Approved->value . ',' . RefundStatus::Rejected->value,
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            /*
             * ALL THREE FAILURE MODES OF `status` ANSWER WITH THE ONE STRING,
             * and that is fidelity rather than laziness. backend/ reached its
             * check as `in_array($status, ['approved','rejected'], true)`
             * AFTER casting whatever arrived to string — so '' , 5 and
             * 'APPROVED' all fell into the same `in_array` miss and all
             * produced 'حالة غير صحيحة'. Leaving `required` on the generic
             * 'الحقل status مطلوب' would make an empty string answer
             * differently from the literal backend/ pins.
             */
            'status.in' => self::INVALID_STATUS,
            'status.string' => self::INVALID_STATUS,
            'status.required' => self::INVALID_STATUS,
        ]);
    }

    /**
     * THE BRANCH POINT. A present-and-non-null `transactionId` means "raise a
     * refund"; anything else means "process one". Written explicitly rather
     * than as `$this->has()` or `$this->filled()` — see the banner: those two
     * helpers disagree with backend/'s `isset()` on `null` and on `''`
     * respectively, and the difference decides which of two operations runs.
     * `isset()`'s semantics (present AND not null) are the ones reproduced.
     */
    public function isRefundRequest(): bool
    {
        return $this->input('transactionId') !== null;
    }

    public function transactionId(): string
    {
        return (string) $this->validated('transactionId');
    }

    /** '' when absent, never null — backend/'s `(string) ($x ?? '')`. */
    public function reason(): string
    {
        return (string) ($this->validated('reason') ?? '');
    }

    public function refundId(): string
    {
        return (string) $this->validated('id');
    }

    public function status(): RefundStatus
    {
        return RefundStatus::from((string) $this->validated('status'));
    }
}
