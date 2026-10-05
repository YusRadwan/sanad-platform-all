<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Rules\StudentPasswordPolicy;
use Illuminate\Contracts\Validation\Validator;

/**
 * POST /api/v1/auth/register.
 *
 * One payload shape: the historical, curriculum-free one. `studentPhone` and
 * `parentPhone` are always local Egyptian numbers (`digits:11`).
 */
final class RegisterRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fullName' => ['required', 'string', 'min:3'],
            'studentPhone' => ['required', 'digits:11'],
            'parentPhone' => ['required', 'digits:11'],
            'governorate' => ['required', 'string'],
            // Legacy rule was the bare `string`, whose hand-rolled
            // implementation SKIPPED empty values. `nullable` is Laravel's
            // spelling of that same "optional, but must be a string if given".
            'gender' => ['nullable', 'string'],
            'educationalStage' => ['nullable', 'string'],
            'gradeLevel' => ['required', 'string'],
            'password' => StudentPasswordPolicy::rules(),
        ];
    }

    /**
     * The one cross-field rule. Compared on the RAW submitted values, exactly
     * as backend/ did: a parent who deliberately writes the same number in two
     * different formats is out of scope for this check and always has been.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $student = $this->input('studentPhone');
            $parent = $this->input('parentPhone');

            if ($student !== null && $student !== '' && $parent === $student) {
                $validator->errors()->add(
                    'parentPhone',
                    'لا يمكن أن يتطابق رقم هاتف ولي الأمر مع هاتف الطالب'
                );
            }
        });
    }
}
