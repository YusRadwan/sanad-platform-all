<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Http\Request;

/**
 * PATCH /api/v1/admin/users/{id}.
 *
 * Port of backend/UserService::update(): an allow-listed passthrough of
 * whatever is present, with NO per-field clear-vs-ignore distinction.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ fill() + save() IS CORRECT **HERE** AND FORBIDDEN IN UpdateTeacher.      │
 * │ THE DIFFERENCE IS NOT STYLISTIC.                                        │
 * │                                                                          │
 * │ backend/ builds this write with                                          │
 * │     array_intersect_key($fields, array_flip([...]))                      │
 * │ — a pure key filter. Whatever value survives is written, '' included,    │
 * │ and no field has a "clear vs ignore" nuance to violate. There is         │
 * │ therefore nothing for a blanket fill() to get WRONG.                     │
 * │                                                                          │
 * │ Teacher update is the opposite: three different empty-string semantics   │
 * │ across its fields, so a blanket fill() would clear fullName and email.   │
 * │ Using the same pattern in both places is exactly how that bug returns.   │
 * │                                                                          │
 * │ The allow-list is applied by intersecting with what the caller ACTUALLY  │
 * │ SENT (has() per key), not with validated(): an absent key must not be    │
 * │ written, and validated() would not distinguish it from a nullable one.   │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ DECISION #3's SELF-DEACTIVATION GUARD IS **NOT** HERE.                  │
 * │ It lives in App\Http\Requests\User\UpdateUserRequest, as an              │
 * │ after-validation rule, so the 422 is raised BEFORE this action is        │
 * │ constructed and therefore before any write can possibly happen. Putting  │
 * │ it here as well would be a second copy of a security rule — the thing    │
 * │ Stage 3 spent a whole scope eliminating. If you are reading this looking │
 * │ for the guard, it is in the Form Request, and its message constant is    │
 * │ UpdateUserRequest::SELF_DEACTIVATION_MESSAGE.                            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `is_active` and `isActive` are both accepted on the wire — see the Form
 * Request's docblock for why — and collapse to the one column here, snake_case
 * winning if both arrive, because that is the spelling with an existing
 * contract.
 */
final class UpdateUser
{
    /** The three columns an admin may change on someone else's account. */
    private const ALLOWED = ['full_name', 'email', 'is_active'];

    public function __invoke(Request $request, User $user): User
    {
        $wasActive = (bool) $user->is_active;

        $attributes = [];

        foreach (self::ALLOWED as $column) {
            if ($request->has($column)) {
                $attributes[$column] = $request->input($column);
            }
        }

        // camelCase alias, applied only if the snake_case spelling was absent.
        if (!array_key_exists('is_active', $attributes) && $request->has('isActive')) {
            $attributes['is_active'] = $request->input('isActive');
        }

        if (array_key_exists('is_active', $attributes)) {
            // Coerced through boolean() rather than cast blindly: the wire
            // value may be "0"/"false", both of which are truthy as raw
            // strings and would REACTIVATE an account the admin is disabling.
            $attributes['is_active'] = filter_var(
                $attributes['is_active'],
                FILTER_VALIDATE_BOOLEAN
            );
        }

        if ($attributes === []) {
            return $user;
        }

        // `is_active` is deliberately absent from User::$fillable (it must
        // never be mass-assignable from an arbitrary request payload), so the
        // two halves are written separately: the fillable ones through fill(),
        // the flag through forceFill on an explicitly allow-listed key.
        $active = $attributes['is_active'] ?? null;
        unset($attributes['is_active']);

        if ($attributes !== []) {
            $user->fill($attributes);
        }

        if ($active !== null) {
            $user->forceFill(['is_active' => $active]);
        }

        $user->save();

        // Deactivation is an ACCESS-REVOCATION event — EnsureUserIsActive
        // kills the session on the very next request — so it is audited like a
        // deletion, and only when the flag actually changed.
        if ($active !== null && $active !== $wasActive) {
            SecurityLog::warning($active ? 'User reactivated' : 'User deactivated', [
                'event' => 'user.is_active_changed',
                'target_user_id' => $user->getKey(),
                'target_username' => $user->getAttribute('username'),
                'is_active' => (int) $active,
            ]);
        }

        return $user;
    }
}
