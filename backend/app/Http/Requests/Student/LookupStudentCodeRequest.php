<?php

declare(strict_types=1);

namespace App\Http\Requests\Student;

use App\Http\Requests\ApiFormRequest;
use App\Support\StudentCode;
use Closure;

/**
 * GET /api/v1/admin/students/by-code/{code} — SHAPE validation only.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ WHY A FORM REQUEST FOR A ROUTE SEGMENT.                                 │
 * │                                                                          │
 * │ The contract distinguishes two failures that a naive implementation      │
 * │ collapses into one:                                                      │
 * │                                                                          │
 * │   'banana'    -> 422, 'كود الطالب يجب أن يكون 6 أرقام أو …'              │
 * │   'STU999999' -> 404, 'لا يوجد طالب بهذا الكود'   (well-formed, unknown) │
 * │                                                                          │
 * │ Route model binding cannot express that: it answers 404 for BOTH, since  │
 * │ from its point of view a malformed code is simply a code that matched no │
 * │ row. Validating the SHAPE here — before any query is built — is what     │
 * │ keeps the 422 case reachable. It is the same ordering guarantee          │
 * │ CatalogController gets by resolving CatalogType::fromRoute() as its      │
 * │ first statement.                                                         │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * THE SEGMENT IS MERGED INTO THE INPUT BAG so the validator can see it: a
 * FormRequest validates request DATA, and a URL segment is not data until it is
 * merged. This is Laravel's documented way to validate a route parameter, and
 * it keeps the error shaped like every other 422 (data.errors.code).
 */
final class LookupStudentCodeRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['code' => (string) $this->route('code')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (!StudentCode::isWellFormed($value)) {
                        $fail(StudentCode::INVALID_MESSAGE);
                    }
                },
            ],
        ];
    }

    /**
     * The single stored spelling this lookup will query for — STAGE 4
     * DECISION #4. Callers get the NORMALISED code and run an exact-match
     * query on the unique column; nobody re-derives it.
     *
     * Safe to call only after validation, which is exactly when the controller
     * receives this object.
     */
    public function normalisedCode(): string
    {
        return StudentCode::normalise((string) $this->validated('code'));
    }
}
