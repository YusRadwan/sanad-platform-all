<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;

/**
 * POST /api/v1/enrollment-codes/redeem — freeze-table row 60.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `course_id` IS NOT IN `rules()`, AND THAT IS THE SECURITY PROPERTY.      ║
 * ║                                                                          ║
 * ║ The course a code unlocks comes from the code's own row; the student     ║
 * ║ comes from the session. If a caller posts `{"code": "...",               ║
 * ║ "course_id": "<someone else's course>"}` the extra key is simply never   ║
 * ║ read — `validated()` returns only what the rules name, and               ║
 * ║ App\Actions\Enrollment\RedeemEnrollmentCode takes the course from the    ║
 * ║ EnrollmentCode model.                                                    ║
 * ║                                                                          ║
 * ║ NOTE WHAT IS **NOT** DONE HERE: there is no rule rejecting a supplied    ║
 * ║ course_id. Rejecting it would be weaker, not stronger — it would mean    ║
 * ║ the field is meaningful enough to police, and a future refactor that     ║
 * ║ dropped the rule would silently make it meaningful again. "No code path  ║
 * ║ observes it" needs no maintenance.                                       ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * SHAPE FIRST, EXISTENCE LATER. `digits:8` answers 422 for junk; a well-formed
 * code that matches no row is the Action's 404 'هذا الكود غير صحيح'. The two
 * must stay distinguishable for the same reason as the admin lookup — and here
 * there is a second reason: an `exists:enrollment_codes,code` rule would turn
 * the redemption box into an ORACLE, letting anyone probe which 8-digit codes
 * exist by reading 422-vs-404. Both answers are already distinguishable to a
 * legitimate student, but validation-time existence checks are exactly how
 * that surface silently widens later.
 */
final class RedeemEnrollmentCodeRequest extends ApiFormRequest
{
    public const MALFORMED = 'الكود يجب أن يتكون من 8 أرقام';

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        $this->merge(['code' => is_string($code) ? trim($code) : $code]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'digits:8'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'code.required' => self::MALFORMED,
            'code.digits' => self::MALFORMED,
        ];
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }
}
