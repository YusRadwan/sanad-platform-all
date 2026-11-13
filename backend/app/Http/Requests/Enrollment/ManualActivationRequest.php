<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;

/** Validation for the dedicated complimentary manual-activation endpoint. */
final class ManualActivationRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'studentId' => ['required', 'string'],
            'courseId' => ['required', 'string'],
            'accessType' => ['required', 'in:lifetime,limited'],
            'expiresAt' => ['required_if:accessType,limited', 'nullable', 'date_format:Y-m-d', 'after:today'],
        ];
    }

    public function studentId(): string
    {
        return (string) $this->validated('studentId');
    }

    public function courseId(): string
    {
        return (string) $this->validated('courseId');
    }

    public function accessType(): string
    {
        return (string) $this->validated('accessType');
    }

    public function expiresAt(): ?string
    {
        return $this->validated('expiresAt');
    }
}
