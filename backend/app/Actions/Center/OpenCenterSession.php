<?php

declare(strict_types=1);

namespace App\Actions\Center;

use App\Models\CenterGroup;
use App\Models\CenterSession;
use App\Models\User;

/**
 * Opens a new session ("حصة") for one of the CALLING teacher's own groups.
 * Ownership is verified by the caller (controller resolves the group through
 * `CenterGroup::ownedBy($teacher)` before this runs) — this Action only
 * writes the row.
 */
final class OpenCenterSession
{
    public function __invoke(CenterGroup $group, User $teacher, string $sessionDate): CenterSession
    {
        return CenterSession::query()->create([
            'teacher_id' => $teacher->getKey(),
            'center_group_id' => $group->getKey(),
            'session_date' => $sessionDate,
            'opened_at' => now(),
            'status' => CenterSession::STATUS_OPEN,
        ]);
    }
}
