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
 * POST /auth/stop-impersonation — the "رجوع للأدمن" half of open decision
 * 10.18. Callable ONLY while impersonation is active (see
 * Impersonation::SESSION_KEY's docblock) — there is no `role:admin` gate on
 * this route because, while impersonating, `auth()->user()` resolves to the
 * STUDENT, not the admin; the session key is itself the authorization.
 *
 * DEFENSE IN DEPTH: the stashed admin id is re-resolved and re-checked
 * (exists, not trashed, active, still role Admin) rather than trusted at
 * face value — an admin account disabled or demoted WHILE one of its own
 * impersonation sessions was still open must not be handed back control.
 * On that failure the session is torn down entirely (matching
 * EnsureUserIsActive's own teardown) rather than left in an ambiguous state.
 */
final class StopImpersonation
{
    public function __invoke(Request $request): User
    {
        $adminId = $request->session()->get(Impersonation::SESSION_KEY);

        if (!is_string($adminId) || $adminId === '') {
            throw new UnauthorizedException('لا يوجد دخول نشط كطالب لإنهائه');
        }

        $admin = User::query()->whereKey($adminId)->first();

        if ($admin === null || $admin->trashed() || !$admin->is_active || $admin->role !== UserRole::Admin) {
            SecurityLog::warning('Impersonation could not be reversed: stashed admin no longer valid', [
                'event' => 'admin.impersonation_recovery_failed',
                'admin_id' => $adminId,
            ]);

            $request->session()->forget(Impersonation::SESSION_KEY);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new UnauthorizedException('تعذّرت العودة لحساب الأدمن، يرجى تسجيل الدخول مرة أخرى');
        }

        $impersonatedUser = $request->user();

        $request->session()->forget(Impersonation::SESSION_KEY);
        Auth::guard('web')->login($admin);

        SecurityLog::criticalOperation('admin.impersonation_ended', [
            'admin_id' => $admin->getKey(),
            'admin_username' => $admin->username,
            'target_user_id' => $impersonatedUser?->getKey(),
        ]);

        return $admin;
    }
}
