<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CenterSession;
use App\Models\User;

/**
 * `center_sessions.teacher_id` is a direct, denormalised owner column (see
 * migration 2026_09_06_000006) — same shape as CenterGroupPolicy.
 */
class CenterSessionPolicy
{
    public function manage(User $user, CenterSession $session): bool
    {
        return (string) $session->teacher_id === (string) $user->id;
    }
}
