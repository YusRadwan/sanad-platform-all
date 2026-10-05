<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Exceptions\ForbiddenException;
use App\Models\User;
use App\Support\SecurityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level role gate. Aliased as `role`, used as `role:admin` (and, in a
 * later stage, `role:admin,instructor`).
 *
 * Port of backend/app/Http/Middleware/RoleMiddleware.php, which was a
 * factory — `RoleMiddleware::for('admin')` — because that container could only
 * register middleware by class name and had no parameter syntax. Laravel has
 * one, so the factory disappears and the allowed roles become route
 * parameters, visible in `php artisan route:list` instead of buried in a
 * constructor call.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ WHY MIDDLEWARE AND NOT A POLICY, IN THIS STAGE.                          │
 * │                                                                          │
 * │ A Policy answers "may THIS user act on THIS row". Every write in Stage 2 │
 * │ is a flat "admin only" — there is no per-row dimension yet, because      │
 * │ catalogs and curricula are global platform data with no owner. Wrapping  │
 * │ that in a Policy would mean seven authorize() calls all returning the    │
 * │ same role check, and would move the rule OUT of the route file where its │
 * │ audience (anyone reading routes/api.php to learn who can call what)      │
 * │ actually looks.                                                          │
 * │                                                                          │
 * │ When tenant isolation lands, the per-row question appears and Policies   │
 * │ become the right tool — they will sit BESIDE this middleware, not        │
 * │ replace it: coarse role gate at the route, fine ownership check at the   │
 * │ model.                                                                   │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ORDERING. This class is registered in bootstrap/app.php's middleware
 * priority list immediately AFTER EnsureUserIsActive, which itself sits after
 * `auth`. Listing middleware in a route's array does not by itself guarantee
 * execution order (Router::sortMiddleware reorders against that list), and a
 * role check that ran before `auth` would see a null user on every request.
 *
 * A REJECTION IS LOGGED to the security channel with the user's actual role,
 * because "an authenticated non-admin probing an admin endpoint" is a
 * different and more interesting signal than an anonymous 401.
 */
final class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // Defensive: `auth` must precede this. If it somehow did not, the
        // answer is still a refusal — never a pass-through.
        if (!$user instanceof User) {
            throw new ForbiddenException();
        }

        $role = $user->getAttribute('role');
        $roleValue = $role instanceof UserRole ? $role->value : (string) $role;

        if ($roles !== [] && !in_array($roleValue, $roles, true)) {
            SecurityLog::warning('Request rejected: insufficient role', [
                'event' => 'auth.role_denied',
                'required' => implode(',', $roles),
                'actual' => $roleValue,
                'username' => $user->getAttribute('username'),
                'target_user_id' => $user->getKey(),
            ]);

            throw new ForbiddenException();
        }

        return $next($request);
    }
}
