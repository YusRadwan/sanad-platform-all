<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Exceptions\UnauthorizedException;
use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeSession;
use App\Support\Impersonation;
use App\Support\SecurityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-checks the account's standing on EVERY authenticated request, not just at
 * login. Runs immediately after `auth`.
 *
 * Port of the second half of backend/app/Http/Middleware/AuthMiddleware.php,
 * whose docblock states the requirement plainly: "The session alone is not
 * trusted." A user deactivated (`is_active = 0`) or soft-deleted while signed
 * in must lose access AT ONCE, not whenever their cookie happens to expire —
 * which for a 120-minute session could be two hours after an admin disabled
 * them.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ WHAT LARAVEL ALREADY DOES, AND WHAT IT DOES NOT.                         │
 * │                                                                          │
 * │ DOES: the session guard re-hydrates the user from the database once per   │
 * │ request (EloquentUserProvider::retrieveById). Nothing about the user is   │
 * │ read out of the session payload, so a ROLE CHANGE is picked up on the     │
 * │ very next request for free — there is no stale role to go stale. And      │
 * │ because User uses SoftDeletes, the global scope makes a soft-deleted row  │
 * │ simply not resolve, so `auth` alone already answers 401 for that case.    │
 * │                                                                          │
 * │ DOES NOT: look at `is_active`. That column is application state the guard │
 * │ knows nothing about, and it is the reason this class exists.              │
 * │                                                                          │
 * │ The soft-delete branch below is therefore belt-and-braces rather than the │
 * │ primary defence — but it is kept, and tested, because "deleted users are  │
 * │ locked out" must not silently become false if someone later removes the   │
 * │ SoftDeletes trait or adds a withTrashed() provider.                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ON REJECTION the session is fully torn down (logout + invalidate + token
 * rotation), so the next request starts clean instead of re-triggering this
 * same check with the same dead session — and the frontend's single retry
 * through /auth/refresh cannot resurrect it.
 *
 * ONE EXCEPTION TO "FULL TEARDOWN" (open decision 10.18): if the rejected
 * user is a student currently being impersonated by an admin (see
 * App\Support\Impersonation), rejection restores the admin's own session
 * instead of destroying it — see restoreImpersonatorIfPossible().
 */
final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // No `auth` middleware in front of us, or it let a null through.
        if (!$user instanceof User) {
            $this->terminateSession($request);

            throw new UnauthorizedException('Not authenticated');
        }

        // $user is already this request's fresh read (see the docblock); the
        // soft-delete check is on the hydrated model's own state, not a second
        // query.
        if ($user->trashed() || !$user->is_active) {
            // GRACEFUL RECOVERY, NOT TEARDOWN, WHEN THIS IS AN IMPERSONATED
            // STUDENT (open decision 10.18). An admin reviewing a student's
            // account must not be stranded just because that student got
            // deactivated mid-review — the admin's own identity is still
            // valid, so hand control back to it instead of nuking the whole
            // session. This branch only fires while
            // Impersonation::SESSION_KEY is present; an ordinary session
            // falls through to the teardown below exactly as before.
            if ($this->restoreImpersonatorIfPossible($request)) {
                throw new UnauthorizedException('تم تعطيل حساب الطالب أثناء المراجعة، تم إرجاعك لحساب الأدمن');
            }

            SecurityLog::warning('Request rejected: account disabled or removed', [
                'event' => 'auth.account_unavailable',
                'reason' => $user->trashed() ? 'soft_deleted' : 'inactive',
                'username' => $user->username,
                'target_user_id' => $user->getKey(),
            ]);

            $this->terminateSession($request);

            throw new UnauthorizedException('Account is disabled or no longer exists');
        }

        // ── "إدارة الأدمنز" (decision 10.37) — RE-VALIDATE THE ACTING     ──
        // EMPLOYEE, NOT JUST THE TEACHER. ──────────────────────────────────
        //
        // After App\Actions\Auth\LoginUser's identity swap, `$user` above is
        // PERMANENTLY the teacher's own row for the rest of this session —
        // the block just executed can therefore NEVER observe the employee
        // themselves being disabled or deleted mid-session. Without this
        // second, independent check, a teacher who deletes/deactivates an
        // employee mid-session would have no effect: the swapped session
        // sails on, authenticated as the teacher, until it expires naturally.
        if (!$this->actingEmployeeStillValid($request)) {
            SecurityLog::warning('Request rejected: acting employee account disabled or removed', [
                'event' => 'auth.employee_account_unavailable',
                'target_user_id' => $user->getKey(),
            ]);

            $this->terminateSession($request);

            throw new UnauthorizedException('تم تعطيل حساب الموظف');
        }

        return $next($request);
    }

    /**
     * True when this is NOT an employee-swapped session (nothing to check),
     * or when it IS one and the employee's own `users` row AND `employees`
     * row both still check out. False terminates the whole session — same
     * "no graceful downgrade" choice EnsureUserIsActive already makes for an
     * ordinary account (unlike the impersonation branch above, an employee
     * session has no higher-privilege identity to fall back to).
     */
    private function actingEmployeeStillValid(Request $request): bool
    {
        $employeeUserId = EmployeeSession::actingEmployeeUserId($request);

        if ($employeeUserId === null) {
            return true;
        }

        $employeeUser = User::query()->whereKey($employeeUserId)->first();

        if ($employeeUser === null || $employeeUser->trashed() || !$employeeUser->is_active) {
            return false;
        }

        $employee = Employee::query()->where('user_id', $employeeUserId)->first();

        return $employee !== null && $employee->is_active;
    }

    private function terminateSession(Request $request): void
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /**
     * If, and only if, this request is inside an active impersonation AND the
     * stashed admin still checks out (exists, not trashed, active, still role
     * Admin), swap the session back to that admin and return true. Otherwise
     * leaves the session untouched and returns false, so the caller falls
     * through to the ordinary teardown.
     */
    private function restoreImpersonatorIfPossible(Request $request): bool
    {
        if (!$request->hasSession()) {
            return false;
        }

        $adminId = $request->session()->get(Impersonation::SESSION_KEY);

        if (!is_string($adminId) || $adminId === '') {
            return false;
        }

        $admin = User::query()->whereKey($adminId)->first();

        if ($admin === null || $admin->trashed() || !$admin->is_active || $admin->role !== UserRole::Admin) {
            return false;
        }

        SecurityLog::warning('Impersonated account disabled mid-session; admin restored', [
            'event' => 'admin.impersonation_auto_recovered',
            'admin_id' => $admin->getKey(),
        ]);

        $request->session()->forget(Impersonation::SESSION_KEY);
        Auth::guard('web')->login($admin);

        return true;
    }
}
