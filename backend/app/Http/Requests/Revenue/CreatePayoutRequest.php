<?php

declare(strict_types=1);

namespace App\Http\Requests\Revenue;

use App\Http\Requests\ApiFormRequest;

/**
 * POST /api/v1/revenue/payouts — freeze-table row 68.
 *
 * camelCase ON THE WIRE, because that is what backend/RevenueController::
 * createPayout() reads: `$data['teacherId']`, `$data['amount']`,
 * `$data['paymentMethod']`, `$data['note']`. Note `note`, SINGULAR, even
 * though the column is `notes` — TeacherPayouts.tsx sends the singular and the
 * mapping happens in the controller. Renaming either end is an API break.
 *
 * ┌── `gt:0`, NOT `min:0`. ZERO IS A REFUSAL, NOT A ZERO-VALUE PAYOUT. ──────┐
 * │ backend/RevenueService::createPayout() rejects `$amount <= 0` with the   │
 * │ literal 'المبلغ يجب أن يكون أكبر من صفر', and that string is the         │
 * │ contract. `min:0` would admit 0.00 — a row that settles nothing, moves   │
 * │ the balance by nothing, and exists only to confuse a reconciliation.     │
 * │                                                                          │
 * │ The DB's `chk_payouts_amount_non_negative` is NOT the same rule and is   │
 * │ not a substitute: it permits 0 and only bars negatives. A negative       │
 * │ payout would INCREASE what the platform still owes (balance = earnings − │
 * │ payouts), so it is barred twice over, here and there. The two bounds     │
 * │ differ on purpose and neither is redundant.                              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── `exists:teachers,user_id` — A 500 THAT IS NOW A 422. ──────────────────┐
 * │ backend/ passed an unknown `teacherId` straight into the INSERT and let  │
 * │ `fk_payouts_teacher` reject it: SQLSTATE 23000, an unhandled exception,  │
 * │ a 500. There is no contract to preserve in a crash. Same 500→422         │
 * │ correction as open-decisions 5.2 and 7.1, and unlike the refund routes   │
 * │ there is NO literal 404 string here that an `exists` rule would          │
 * │ overwrite — nothing in backend/ ever produced one.                       │
 * │                                                                          │
 * │ It checks `teachers.user_id`, the teachers-table PK, NOT `users.id`: the │
 * │ FK points at the profile row, so a valid `users` id belonging to a       │
 * │ student would still violate it.                                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * MISSING `teacherId` OR `amount` WAS ALSO A 500 (backend/ reads both without
 * null-coalescing) and is now a 422.
 */
final class CreatePayoutRequest extends ApiFormRequest
{
    /** backend/RevenueService::createPayout()'s literal, byte for byte. */
    public const AMOUNT_NOT_POSITIVE = 'المبلغ يجب أن يكون أكبر من صفر';

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THIS STRING IS NEW, AND IT HAS TO BE WRITTEN DOWN SOMEWHERE.         │
     * │                                                                      │
     * │ backend/ answered this case with a 500, so there is no literal to    │
     * │ port. But an `exists:` rule with no message of its own emits         │
     * │ Laravel's raw translation KEY — the literal seven ASCII characters   │
     * │ `validation.exists` — because this project ships no lang file and    │
     * │ never has (see ApiFormRequest's docblock: the messages live on the   │
     * │ base class precisely so a global ar/validation.php does not restyle  │
     * │ every endpoint). MEASURED, NOT ASSUMED: this test failed with        │
     * │ 'validation.exists' in `data.errors.teacherId.0` before this         │
     * │ constant existed, which is an untranslated placeholder reaching an   │
     * │ Arabic-only admin screen.                                            │
     * │                                                                      │
     * │ Every future `exists:`/`unique:` rule added anywhere in this project │
     * │ needs the same treatment. Recorded in docs/open-decisions.md (7.14). │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public const TEACHER_NOT_FOUND = 'المعلم غير موجود';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'teacherId' => ['required', 'string', 'exists:teachers,user_id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'paymentMethod' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            // All three failure modes of "the amount is not a positive number"
            // answer with the ONE string the contract pins. A caller sending
            // "abc" and a caller sending -5 have made the same mistake as far
            // as this endpoint is concerned.
            'amount.gt' => self::AMOUNT_NOT_POSITIVE,
            'amount.numeric' => self::AMOUNT_NOT_POSITIVE,
            'amount.required' => self::AMOUNT_NOT_POSITIVE,
            // Without this, `validation.exists` ships verbatim. See the const.
            'teacherId.exists' => self::TEACHER_NOT_FOUND,
        ]);
    }

    public function teacherId(): string
    {
        return (string) $this->validated('teacherId');
    }

    public function amount(): float
    {
        return (float) $this->validated('amount');
    }

    public function paymentMethod(): ?string
    {
        $value = $this->validated('paymentMethod');

        return $value !== null ? (string) $value : null;
    }

    public function note(): ?string
    {
        $value = $this->validated('note');

        return $value !== null ? (string) $value : null;
    }
}
