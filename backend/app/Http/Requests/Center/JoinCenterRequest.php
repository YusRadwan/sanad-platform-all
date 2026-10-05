<?php

declare(strict_types=1);

namespace App\Http\Requests\Center;

use App\Http\Requests\ApiFormRequest;

/** POST /center/join. */
final class JoinCenterRequest extends ApiFormRequest
{
    public const CODE_REQUIRED = 'برجاء إدخال كود السنتر';

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        $this->merge(['code' => is_string($code) ? strtoupper(trim($code)) : $code]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'size:6'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'code.required' => self::CODE_REQUIRED,
            'code.string' => self::CODE_REQUIRED,
            'code.size' => 'كود السنتر يجب أن يتكون من 6 خانات',
        ];
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }
}
