<?php

declare(strict_types=1);

namespace App\Http\Requests\Center;

use App\Http\Requests\ApiFormRequest;

/** POST /admin/center-codes. Mirrors StoreEnrollmentCodeRequest's shape. */
final class StoreCenterCodeRequest extends ApiFormRequest
{
    public const TEACHER_REQUIRED = 'برجاء اختيار المدرس';

    public const MAX_COUNT = 200;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'teacher_id' => ['required', 'string'],
            'count' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_COUNT],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'teacher_id.required' => self::TEACHER_REQUIRED,
            'teacher_id.string' => self::TEACHER_REQUIRED,
            'count.integer' => 'عدد الأكواد يجب أن يكون رقماً صحيحاً',
            'count.min' => 'عدد الأكواد يجب أن يكون واحداً على الأقل',
            'count.max' => 'عدد الأكواد يجب ألا يزيد عن ' . self::MAX_COUNT,
        ];
    }

    public function teacherId(): string
    {
        return (string) $this->validated('teacher_id');
    }

    public function count(): int
    {
        $count = $this->validated('count');

        return $count === null ? 1 : (int) $count;
    }
}
