<?php

declare(strict_types=1);

namespace App\Http\Requests\Center;

use App\Http\Requests\ApiFormRequest;

/** GET /admin/center-codes/search. Two optional filters, ANDed. */
final class SearchCenterCodesRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string'],
            'teacher_id' => ['nullable', 'string'],
        ];
    }

    public function code(): string
    {
        return trim((string) $this->query('code', ''));
    }

    public function teacherId(): string
    {
        return trim((string) $this->query('teacher_id', ''));
    }
}
