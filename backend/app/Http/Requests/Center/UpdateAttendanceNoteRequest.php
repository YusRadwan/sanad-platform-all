<?php

declare(strict_types=1);

namespace App\Http\Requests\Center;

use App\Exceptions\NotFoundException;
use App\Http\Requests\ApiFormRequest;
use App\Models\CenterAttendance;
use App\Support\CenterAccess;

/** PATCH /center/attendance/{id}/note. */
final class UpdateAttendanceNoteRequest extends ApiFormRequest
{
    private ?CenterAttendance $attendance = null;

    public function authorize(): bool
    {
        CenterAccess::assertOwnsSession($this->attendance()->session()->firstOrFail());

        return true;
    }

    public function attendance(): CenterAttendance
    {
        return $this->attendance ??= CenterAttendance::query()->whereKey((string) $this->route('id'))->first()
            ?? throw new NotFoundException('سجل الحضور غير موجود');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return $note === null ? null : (string) $note;
    }
}
