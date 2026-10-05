<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CenterGroup;
use App\Models\User;

/**
 * `center_groups.teacher_id` is a direct FK to teachers.user_id — no Course
 * in the chain, same shape as QuestionGroupPolicy. Admins never reach this
 * file: Gate::before already answered for them.
 */
class CenterGroupPolicy
{
    public function manage(User $user, CenterGroup $group): bool
    {
        return (string) $group->teacher_id === (string) $user->id;
    }
}
