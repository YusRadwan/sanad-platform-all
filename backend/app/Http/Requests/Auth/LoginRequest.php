<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

/**
 * POST /api/v1/auth/login.
 *
 * backend/'s AuthController did this check by hand, building a ValidationException
 * whose payload included an EMPTY array for whichever of the two fields was
 * fine ({username: [], password: ['كلمة المرور مطلوبة']}). Laravel simply omits
 * a field that passed. That is a strict subset of the old payload and the
 * frontend reads `data.errors[field]` with an existence check, so nothing
 * breaks — an empty error list never rendered anything anyway.
 *
 * NOTE THE ABSENCE OF ANY `exists` OR `is_active` RULE. Whether the account
 * exists, whether the password matches and whether it is active are all decided
 * in App\Actions\Auth\LoginUser, which answers ONE generic message for the first
 * two cases. Expressing them as validation rules would leak account existence
 * through a per-field error — the classic user-enumeration hole.
 */
final class LoginRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'username.required' => 'اسم المستخدم مطلوب',
            'password.required' => 'كلمة المرور مطلوبة',
        ];
    }
}
