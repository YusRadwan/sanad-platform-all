<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;

/**
 * GET /api/v1/admin/enrollment-codes/{code} — freeze-table row 59.
 *
 * SHAPE VALIDATION ONLY, AND IT EXISTS TO KEEP TWO ANSWERS DISTINCT — the same
 * argument App\Http\Requests\Student\LookupStudentCodeRequest makes for
 * /admin/students/by-code/{code}:
 *
 *   'banana'    -> 422  'الكود يجب أن يتكون من 8 أرقام'
 *   '99999999'  -> 404  'لا يوجد كود اشتراك بهذا الرقم'   (well-formed, unknown)
 *
 * Route-model binding cannot express that distinction: from its point of view
 * a malformed code is simply a code that matched no row, so both collapse to
 * 404. Validating the shape before any query is built is what keeps the 422
 * reachable.
 *
 * THE SEGMENT IS MERGED INTO THE INPUT BAG because a FormRequest validates
 * request DATA and a URL segment is not data until it is merged. This is
 * Laravel's documented way to validate a route parameter and it keeps the
 * error shaped like every other 422 (`data.errors.code`).
 *
 * `digits:8` RATHER THAN A REGEX, and the difference is not cosmetic: the rule
 * rejects '1234567.0', '+12345678' and ' 12345678' as well as 'banana', which
 * is exactly the set CHAR(8) cannot round-trip. Its message comes from
 * ApiFormRequest's shared template — overridden here because the contract
 * string is 'الكود يجب أن يتكون من 8 أرقام', not the generic phrasing.
 */
final class ShowEnrollmentCodeRequest extends ApiFormRequest
{
    public const MALFORMED = 'الكود يجب أن يتكون من 8 أرقام';

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => trim((string) $this->route('code'))]);
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
