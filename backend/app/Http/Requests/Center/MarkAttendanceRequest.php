<?php

declare(strict_types=1);

namespace App\Http\Requests\Center;

use App\Http\Requests\ApiFormRequest;
use App\Models\CenterSession;
use App\Support\CenterAccess;

/** POST /center/sessions/{id}/attendance. `identifier`: student code, phone, or scanned QR payload (= student code). */
final class MarkAttendanceRequest extends ApiFormRequest
{
    private ?CenterSession $centerSession = null;

    public function authorize(): bool
    {
        CenterAccess::assertOwnsSession($this->session());

        return true;
    }

    public function session(): CenterSession
    {
        return $this->centerSession ??= CenterAccess::findSessionOrFail((string) $this->route('id'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string'],
            // Cross-group compensatory-attendance confirmation — see
            // MarkAttendance's docblock. Absent/false on the first attempt;
            // the frontend resends true after the teacher confirms the
            // "this student belongs to another group" warning.
            'confirm' => ['sometimes', 'boolean'],
        ];
    }

    public function identifier(): string
    {
        return (string) $this->validated('identifier');
    }

    public function confirmed(): bool
    {
        return $this->boolean('confirm');
    }
}
