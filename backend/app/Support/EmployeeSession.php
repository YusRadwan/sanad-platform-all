<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Employee;
use Illuminate\Http\Request;

/**
 * The one session key "an employee logged in and the session was swapped to
 * their owning teacher's identity" (decision 10.37) reads and writes — same
 * shape and same reasoning as App\Support\Impersonation, which this is
 * modelled on directly: no separate boolean flag, no database row, PRESENCE
 * of this key IS the only signal.
 *
 * ┌── THE ONE STRUCTURAL DIFFERENCE FROM Impersonation. ──────────────────────┐
 * │ Impersonation stores the ORIGINAL (higher-privilege) admin's id so the    │
 * │ swap can be reversed on demand. This stores the ACTING (lower-privilege)  │
 * │ employee's id — there is no "reverse" action; an employee logs OUT, they  │
 * │ never "return" to their own view, because `Auth::guard('web')` was never  │
 * │ their identity to begin with post-login. `App\Http\Middleware\            │
 * │ EnsureUserIsActive` uses this value to re-validate the ACTING employee's  │
 * │ own row on every request — the teacher-row check already covers the      │
 * │ identity `$request->user()` resolves to, but never the employee's own.    │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class EmployeeSession
{
    public const SESSION_KEY = 'acting_employee_id';

    /** The acting employee's OWN `users.id`, or null for an ordinary session. */
    public static function actingEmployeeUserId(Request $request): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }

        $value = $request->session()->get(self::SESSION_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The acting Employee row itself, or null if this is not an employee
     * session, or if the row has since vanished (deleted/deactivated —
     * callers that need to REJECT that case, not just treat it as "not an
     * employee", must check the row's own `is_active` explicitly; this
     * method only resolves, it does not judge).
     */
    public static function actingEmployee(Request $request): ?Employee
    {
        $userId = self::actingEmployeeUserId($request);

        if ($userId === null) {
            return null;
        }

        return Employee::query()->where('user_id', $userId)->first();
    }

    /**
     * Does the CURRENT request's caller hold `$key`? True unconditionally for
     * an ordinary teacher/admin session (no swap took place, so nothing to
     * restrict) — this is the "additive gate on top of, never instead of,
     * existing ownership checks" property the whole feature is built on.
     */
    public static function hasPermission(Request $request, string $key): bool
    {
        $employee = self::actingEmployee($request);

        if ($employee === null) {
            return true;
        }

        return EmployeePermissions::grants($employee, $key);
    }

    /** Static-only. */
    private function __construct()
    {
    }
}
