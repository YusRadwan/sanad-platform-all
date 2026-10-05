<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one session key "admin views the platform as a student" (open decision
 * 10.18) reads and writes, shared by four otherwise-unrelated classes
 * (App\Actions\Auth\StartImpersonation, StopImpersonation,
 * App\Http\Middleware\EnsureUserIsActive, App\Http\Resources\PublicUser) so
 * none of them can drift onto a different literal string.
 *
 * PRESENCE OF THIS KEY IN THE SESSION IS THE ONLY SIGNAL THAT IMPERSONATION
 * IS ACTIVE — there is no separate boolean flag and no database row. While
 * present, `auth()->user()` resolves to the STUDENT being viewed; the value
 * stored here is the ORIGINAL ADMIN's id, the only piece of state needed to
 * reverse the swap. It is deliberately NOT tied to `login_sessions` (see
 * StartImpersonation's docblock) — the underlying browser session/cookie
 * never changes owner, only which user `Auth::guard('web')` currently
 * resolves to.
 */
final class Impersonation
{
    public const SESSION_KEY = 'impersonator_admin_id';
}
