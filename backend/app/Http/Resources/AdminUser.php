<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The user shape for the admin user-management endpoints
 * (GET/PATCH /admin/users[/{id}]). Port of backend/UserService::present().
 *
 * DISTINCT FROM App\Http\Resources\PublicUser, deliberately. PublicUser is the
 * SESSION user — the /auth/me and /auth/login payload — and carries the
 * `fullName` mirror key plus the instructor `grades`/`subjects` that the
 * Instructor components require. This one is an admin's view of ANOTHER
 * account and carries neither: no consumer of the user table reads those, and
 * attaching them would issue a teacher lookup per row of a paginated listing.
 *
 * `deleted_at` IS NOT EXPOSED, and cannot be reached anyway — every query
 * behind this resource runs under SoftDeletes' global scope, so a deleted user
 * is never hydrated in the first place.
 *
 * `created_at` IS 'Y-m-d H:i:s', matching the raw MySQL DATETIME string
 * backend/ shipped. See PublicUser's docblock for why the Carbon default is
 * not used.
 *
 * @mixin User
 */
final class AdminUser extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $role = $user->getAttribute('role');

        return [
            'id' => $user->getKey(),
            'username' => $user->getAttribute('username'),
            'email' => $user->getAttribute('email'),
            'full_name' => $user->getAttribute('full_name'),
            // The enum is cast on the model; the wire contract is its backing
            // string, exactly as the column stores it.
            'role' => $role instanceof UserRole ? $role->value : (string) $role,
            // TINYINT(1) arrives as int 1/0; frontend/src/types/user.ts
            // declares `is_active: boolean`.
            'is_active' => (bool) $user->getAttribute('is_active'),
            'avatar_url' => $user->getAttribute('avatar_url'),
            'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
