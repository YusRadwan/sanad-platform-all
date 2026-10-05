<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\LoginSession;
use Illuminate\Database\Eloquent\Collection;

/**
 * Freeze-table row 56 — GET /admin/login-activity.
 *
 * Port of backend/app/Repositories/LoginSessionRepository::listRecent(), which
 * is the ONLY reader of that table.
 *
 * ┌── CAPPED, NOT PAGINATED — AND THE CAP IS 300, READ NOT GUESSED. ─────────┐
 * │ backend/ says why in a comment: LoginActivity.tsx receives one array and │
 * │ filters/sorts it entirely client-side. It has no page control to send a  │
 * │ `page` parameter with, so an unbounded result set would grow forever     │
 * │ while the page can still only render a slice. Adding pagination here     │
 * │ would be an API change the frontend has no code to consume.              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ORDER BY HAS NO TIE-BREAKER, AND ONE WAS **NOT** ADDED.              ║
 * ║                                                                          ║
 * ║ backend/ is `ORDER BY ls.created_at DESC LIMIT 300` — one column, no     ║
 * ║ secondary key. `login_sessions.created_at` is a DATETIME with            ║
 * ║ second granularity, so two logins in the same second have an             ║
 * ║ UNSPECIFIED relative order, and which of them survives the LIMIT at the  ║
 * ║ 300-row boundary is likewise unspecified.                                ║
 * ║                                                                          ║
 * ║ Appending `, id DESC` would make it deterministic — and would also       ║
 * ║ change the returned ordering on real data, which is a wire-visible       ║
 * ║ difference on a row the freeze table says is a faithful port. Every      ║
 * ║ prior stage's rule has been "port exactly, then record the wart", so the ║
 * ║ non-determinism is PRESERVED and recorded as open decision 10.2 rather   ║
 * ║ than quietly improved.                                                   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── `withTrashed()` ON THE EAGER LOAD IS LOAD-BEARING. ────────────────────┐
 * │ backend/'s query is `LEFT JOIN users u ON u.id = ls.user_id` with NO     │
 * │ `deleted_at IS NULL` — unusually for that codebase, which writes the     │
 * │ soft-delete filter by hand almost everywhere else. So a deleted          │
 * │ account's name STILL appears in the login history, which is the correct  │
 * │ behaviour for an audit log: "who signed in" does not stop being true     │
 * │ when the account is later removed.                                       │
 * │                                                                          │
 * │ App\Models\User uses SoftDeletes, so a plain `with('user')` would apply  │
 * │ the global scope and turn every deleted user's row into `userName: '—'`  │
 * │ — silently erasing history. `withTrashed()` restores the legacy join.    │
 * │ Pinned by LoginActivityTest::test_a_soft_deleted_users_name_is_still     │
 * │ _shown.                                                                  │
 * │                                                                          │
 * │ The eager load also makes this a TWO-query endpoint regardless of row    │
 * │ count. backend/ got that from its JOIN; a naive `$row->user->full_name`  │
 * │ in the Resource would be 301 queries.                                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO REQUEST INPUT AT ALL — no filter, no limit override, no date range. The
 * method takes nothing, so there is nothing a caller can supply that changes
 * the answer, and `?limit=100000` is inert by construction rather than by
 * validation.
 */
final class ListLoginActivity
{
    /** backend/LoginSessionRepository::listRecent()'s default, verbatim. */
    public const LIMIT = 300;

    /** @return Collection<int, LoginSession> */
    public function __invoke(): Collection
    {
        return LoginSession::query()
            ->with(['user' => static fn ($query) => $query->withTrashed()])
            ->orderByDesc('created_at')
            ->limit(self::LIMIT)
            ->get();
    }
}
