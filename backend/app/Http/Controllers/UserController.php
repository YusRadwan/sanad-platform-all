<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\User\UpdateUser;
use App\Enums\UserRole;
use App\Exceptions\NotFoundException;
use App\Http\Requests\User\DeleteUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\AdminUser;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Support\Pagination;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generic user management for admins — the flat `users` table view behind
 * "جدول المستخدمين".
 *
 * Port of backend/app/Http/Controllers/UserController.php. No Policy, for the
 * reason recorded in the freeze table for all 25 /admin rows: Gate::before
 * makes an admin-only Policy unreachable ceremony, and `role:admin` in
 * routes/api.php is where the rule belongs.
 *
 * SOFT-DELETED USERS ARE INVISIBLE TO EVERY ACTION HERE, and not because any
 * query says so: User uses SoftDeletes, so the global scope filters them out
 * of the listing, the lookups and the counts alike. backend/ hand-wrote
 * `deleted_at IS NULL` in each of its four queries — four chances to forget
 * one and leak a deleted account into an admin table.
 */
final class UserController extends Controller
{
    /**
     * GET /api/v1/admin/users — paginated, with an optional `?role=` filter.
     *
     * The filter is matched against App\Enums\UserRole rather than passed
     * through: an unknown role yields an EMPTY page, not a 500 and not the
     * unfiltered list. backend/ bound the raw string straight into the query,
     * where an unknown value harmlessly matched nothing — same observable
     * result, now by construction rather than by luck.
     */
    public function index(Request $request): JsonResponse
    {
        [$page, $perPage] = Pagination::fromRequest($request);

        $query = User::query()->orderByDesc('created_at');

        if ($request->filled('role')) {
            $role = UserRole::tryFrom((string) $request->query('role'));

            // whereRaw('1 = 0')-equivalent: an unrecognised role must match no
            // rows rather than every row. `whereKey(null)` would be subtler and
            // less obvious; this states the intent.
            $query->when(
                $role !== null,
                fn ($q) => $q->where('role', $role?->value),
                fn ($q) => $q->whereRaw('1 = 0')
            );
        }

        $total = (clone $query)->getQuery()->getCountForPagination();

        return ApiResponse::success([
            'items' => AdminUser::collection($query->forPage($page, $perPage)->get()),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /** GET /api/v1/admin/users/{id}. */
    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new AdminUser($this->findUser($id)));
    }

    /**
     * PATCH /api/v1/admin/users/{id}.
     *
     * STAGE 4 DECISION #3's self-deactivation guard runs inside
     * UpdateUserRequest, as an after-validation rule — so the 422 is raised
     * before this method is entered and therefore before any write. See that
     * request's docblock for why 422 and not 403.
     *
     * PATCH ONLY. Unlike teachers and students there is no PUT twin here,
     * because backend/ registered none — the duplication on those two routes
     * exists to serve a specific frontend toggle flow, and inventing a third
     * would be adding surface nobody asked for.
     */
    public function update(UpdateUserRequest $request, UpdateUser $update, string $id): JsonResponse
    {
        $user = $update($request, $this->findUser($id));

        return ApiResponse::success(new AdminUser($user), 'تم التحديث بنجاح');
    }

    /**
     * DELETE /api/v1/admin/users/{id}.
     *
     * STAGE 4 DECISION #1: re-authentication is required — new behaviour.
     * This is the most dangerous of the three deletes because it can target
     * ANY role, another administrator included.
     */
    public function destroy(DeleteUserRequest $request, string $id): JsonResponse
    {
        $user = $this->findUser($id);

        $role = $user->getAttribute('role');

        $user->delete();

        SecurityLog::warning('User deleted', [
            'event' => 'user.deleted',
            'target_user_id' => $user->getKey(),
            'target_username' => $user->getAttribute('username'),
            'target_role' => $role instanceof UserRole ? $role->value : (string) $role,
        ]);

        return ApiResponse::success(null, 'تم الحذف بنجاح');
    }

    /**
     * NOT implicit route-model binding: 'User not found' is the contract
     * (freeze-table row 36), and binding's own 404 message would be
     * genericised by App\Exceptions\Handler — which is right for framework
     * 404s, since theirs embed the model class and the id that was looked up.
     */
    private function findUser(string $id): User
    {
        return User::query()->whereKey($id)->first()
            ?? throw new NotFoundException('User not found');
    }
}
