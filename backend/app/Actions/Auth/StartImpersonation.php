<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\UserRole;
use App\Exceptions\UnauthorizedException;
use App\Models\User;
use App\Support\Impersonation;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * POST /admin/students/{id}/impersonate — open decision 10.18. "Switch to
 * the student's account to review it as they see it, with a way back."
 *
 * ┌── THE UNDERLYING SESSION/COOKIE NEVER CHANGES OWNER. ─────────────────────┐
 * │ This does NOT create a new `login_sessions` row for the student, and it   │
 * │ does NOT touch the admin's own row. `Auth::guard('web')->login($target)`  │
 * │ only swaps which user the SAME session resolves to; the admin's real      │
 * │ browser session (and its `login_sessions` audit row) stays exactly as it  │
 * │ was. Two reasons: (1) inserting a `login_sessions` row here would make    │
 * │ the student's own login history show a "login" they never performed —    │
 * │ LoginActivity.tsx would then lie about who signed in and when; (2) the    │
 * │ admin's own row must survive the round trip so StopImpersonation has      │
 * │ something real to hand control back to.                                   │
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * Deliberately NOT `Auth::login($target, remember: true)` and deliberately no
 * `session()->migrate(true)` here — regenerating the session id would be
 * fine on its own, but there is no fixation risk to defend against (the
 * caller already holds a valid, just-authenticated admin session), and
 * skipping it keeps this action a pure identity swap with no other
 * side effect to reason about.
 */
final class StartImpersonation
{
    public function __invoke(Request $request, User $admin, User $target): void
    {
        if ($target->role !== UserRole::Student) {
            throw new UnauthorizedException('لا يمكن الدخول إلا إلى حساب طالب');
        }

        if ($target->trashed() || !$target->is_active) {
            throw new UnauthorizedException('لا يمكن الدخول إلى حساب غير نشط');
        }

        $request->session()->put(Impersonation::SESSION_KEY, $admin->getKey());

        Auth::guard('web')->login($target);

        SecurityLog::criticalOperation('admin.impersonation_started', [
            'admin_id' => $admin->getKey(),
            'admin_username' => $admin->username,
            'target_user_id' => $target->getKey(),
            'target_username' => $target->username,
        ]);
    }
}
