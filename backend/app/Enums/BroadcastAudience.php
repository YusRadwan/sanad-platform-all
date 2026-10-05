<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * broadcast_notifications.audience.
 *
 * Exactly the four radio options the admin broadcast screen offers — no
 * invented targeting:
 *   all_teachers      -> every active user with role='instructor'
 *   all_staff         -> every active user with role='admin'
 *   selected_teachers -> the instructor ids checked in the picker
 *   specific_user     -> one user resolved from the free-text username/id box
 *
 * The two "selected"/"specific" cases read their targets from
 * target_user_ids (a JSON array of users.id); the two "all" cases leave it
 * NULL and resolve their recipients by query at send time.
 */
enum BroadcastAudience: string
{
    use HasValues;

    case AllTeachers = 'all_teachers';
    case AllStaff = 'all_staff';
    case SelectedTeachers = 'selected_teachers';
    case SpecificUser = 'specific_user';
}
