<?php

declare(strict_types=1);

namespace App\Http\Requests\Center;

use App\Http\Requests\ApiFormRequest;
use App\Models\CenterGroup;
use App\Support\CenterAccess;

/** POST /center/sessions. */
final class OpenCenterSessionRequest extends ApiFormRequest
{
    private ?CenterGroup $group = null;

    public function authorize(): bool
    {
        CenterAccess::assertOwnsGroup($this->group());

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'center_group_id' => ['required', 'string'],
            'session_date' => ['nullable', 'date'],
        ];
    }

    public function group(): CenterGroup
    {
        return $this->group ??= CenterAccess::findGroupOrFail((string) $this->input('center_group_id'));
    }

    public function groupId(): string
    {
        return (string) $this->validated('center_group_id');
    }

    public function sessionDate(): string
    {
        $date = $this->validated('session_date');

        return $date === null ? now()->toDateString() : (string) $date;
    }
}
