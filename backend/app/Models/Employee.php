<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmployeeAccountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "إدارة الأدمنز" (decisions 10.37 + 10.40) — a link row, not a profile: the
 * employee's display name/phone/email/password all live on their OWN
 * `users` row (`user_id`). This table records only the two facts specific
 * to the sub-account concept: WHO owns it (`owner_id`) and WHAT it may do
 * (`account_type`/`permissions`).
 *
 * `owner_id` -> `users.id`, and is genuinely EITHER an Instructor (decision
 * 10.37, the teacher-scoped page) OR an Admin (decision 10.40, "أدمنز
 * منفصلون كلياً عن المدرّسين" — the admin section's OWN, isolated-per-admin
 * page) — never both meanings for the same row. `App\Actions\Auth\LoginUser`'s
 * identity-swap login does not care which: `Auth::guard('web')->
 * login($employee->owner)` authenticates the session as whichever role the
 * owner actually holds, so an Admin-owned Amin genuinely inherits full
 * Super Admin authority (Gate::before fires for `role === Admin` same as
 * any other admin session) while a Teacher-owned Amin never does (the
 * owner's own role stays Instructor) — the SAME mechanism, two legitimate
 * outcomes, entirely determined by who created the employee.
 *
 * `permissions` is `null` for `account_type = amin` — an Amin's access is
 * "everything the owner can do", never an enumerable list. Only
 * App\Support\EmployeePermissions::grants() should ever read this column;
 * nothing else in the app may branch on it directly, or the "amin has ALL
 * of them" rule has to be re-derived at every call site instead of living
 * in one place.
 */
class Employee extends BaseModel
{
    protected $table = 'employees';

    protected $fillable = [
        'user_id',
        'owner_id',
        'account_type',
        'permissions',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'account_type' => EmployeeAccountType::class,
            'permissions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** The employee's OWN login identity — never the owner's. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /** The owning teacher OR admin's `users` row — see the class docblock. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id', 'id');
    }

    /** Every employee/amin belonging to one owner (teacher or admin) — the isolation boundary. */
    public function scopeOwnedBy(Builder $query, User $owner): Builder
    {
        return $query->where('owner_id', $owner->getKey());
    }
}
