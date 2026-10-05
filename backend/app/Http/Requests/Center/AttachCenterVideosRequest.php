<?php

declare(strict_types=1);

namespace App\Http\Requests\Center;

use App\Http\Requests\ApiFormRequest;
use App\Models\CenterSession;
use App\Support\CenterAccess;

/** POST /center/sessions/{id}/videos — the video sibling of AttachCenterExamsRequest. */
final class AttachCenterVideosRequest extends ApiFormRequest
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
            'video_ids' => ['required', 'array', 'min:1'],
            'video_ids.*' => ['string'],
        ];
    }

    /** @return list<string> */
    public function videoIds(): array
    {
        return array_map('strval', (array) $this->validated('video_ids'));
    }
}
