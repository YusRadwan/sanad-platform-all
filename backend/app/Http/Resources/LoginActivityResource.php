<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LoginSession;
use App\Models\User;
use App\Support\UserAgentParser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Freeze-table row 56 — one row of the admin's "سجل الدخول" table.
 *
 * EXACTLY FOURTEEN KEYS, matching the `LoginSession` interface in
 * frontend/src/types/auth.ts and backend/AdminDashboardService::loginActivity()
 * key for key. Nothing is added and nothing is dropped.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `lastActive` AND `lastActivity` ARE THE SAME VALUE UNDER TWO NAMES.      ║
 * ║ THE DUPLICATION IS DELIBERATE AND MUST NOT BE COLLAPSED.                 ║
 * ║                                                                          ║
 * ║ Both are `login_sessions.last_activity_at`. backend/ emits both because  ║
 * ║ the TypeScript interface declares both and the table component reads     ║
 * ║ one of them while a sort comparator reads the other; which is which is   ║
 * ║ not worth discovering, because dropping EITHER key produces `undefined`  ║
 * ║ in a cell or a comparator that sorts on nothing — both of which render   ║
 * ║ as a blank column rather than as an error.                                ║
 * ║                                                                          ║
 * ║ Pinned by LoginActivityTest::test_last_active_and_last_activity_are_the_ ║
 * ║ same_value.                                                              ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `country` AND `city` ARE DELIBERATELY ABSENT. DO NOT ADD THEM.           ║
 * ║                                                                          ║
 * ║ They look like the obvious next columns for a login-audit table, and     ║
 * ║ backend/ leaves a comment saying exactly why they are not there: THIS    ║
 * ║ SYSTEM HAS NO GEO-IP LOOKUP. Deriving a country from an IP without one   ║
 * ║ means inventing it, and inventing it puts fabricated evidence in front   ║
 * ║ of an administrator who is using this page to decide whether an account  ║
 * ║ was compromised. An empty column is a missing feature; a wrong country   ║
 * ║ is a wrong security decision.                                            ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── `userName` FALLS BACK THROUGH THREE VALUES, ENDING IN AN EM DASH. ─────┐
 * │ `full_name ?? username ?? '—'`. That final literal is U+2014 EM DASH     │
 * │ (—), NOT a hyphen-minus (-) and NOT an en dash (–). It is rendered       │
 * │ verbatim in the table cell, and the three characters are visually        │
 * │ distinct at the sizes this table uses.                                    │
 * │                                                                          │
 * │ The `?? ` chain is null-coalescing, so an account whose `full_name` is   │
 * │ the EMPTY STRING renders as an empty cell rather than falling through to │
 * │ `username` — legacy behaviour, preserved. Only NULL falls through.        │
 * │                                                                          │
 * │ The dash case is reachable two ways: a `login_sessions` row whose user   │
 * │ was HARD-deleted (the FK cascades, so this is rare), or — far more       │
 * │ likely — a row whose user has neither name nor username set.             │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── `device` IS A DERIVED DISPLAY STRING, JOINED BY A MIDDLE DOT. ─────────┐
 * │ `trim("{deviceType} · {os}")` — U+00B7 MIDDLE DOT with a space on each   │
 * │ side. The `trim()` is backend/'s and is preserved even though neither    │
 * │ half can be empty (both classifiers always return a non-empty literal),  │
 * │ because the port's contract is "the same expression", not "the same      │
 * │ expression minus the parts I proved redundant".                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * DATE SHAPE is 'Y-m-d H:i:s' — the raw MySQL DATETIME string PDO handed
 * backend/ straight through. Same decision, same reason, as every other
 * Resource in this application; see CurriculumResource for the long form.
 *
 * @mixin LoginSession
 */
final class LoginActivityResource extends JsonResource
{
    /** The em dash (U+2014) shown when the account has no displayable name. */
    public const NO_NAME = '—';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LoginSession $session */
        $session = $this->resource;

        /*
         * The relation is eager-loaded WITH TRASHED by
         * App\Actions\Admin\ListLoginActivity. Reading it here without that
         * eager load would be an N+1 of 300 queries — see that class.
         */
        $user = $session->getRelationValue('user');
        $user = $user instanceof User ? $user : null;

        $userAgent = (string) $session->getAttribute('user_agent');

        $deviceType = UserAgentParser::deviceType($userAgent);
        $os = UserAgentParser::os($userAgent);

        $lastActivity = $session->getAttribute('last_activity_at')?->format('Y-m-d H:i:s');

        return [
            'id' => $session->getKey(),
            'userId' => $session->getAttribute('user_id'),
            'userName' => $user?->getAttribute('full_name')
                ?? $user?->getAttribute('username')
                ?? self::NO_NAME,
            'userEmail' => $user?->getAttribute('email'),
            // UserRole is a backed enum in v2 and a bare column in backend/;
            // ->value is what keeps the wire string identical ('instructor').
            'userType' => $user?->getAttribute('role')?->value,
            'ipAddress' => $session->getAttribute('ip_address'),
            // Likewise LoginSessionStatus — 'Active' / 'Logged Out', spaces and
            // capitals included, exactly as the column stores them.
            'status' => $session->getAttribute('status')?->value,
            'loginDate' => $session->getAttribute('created_at')?->format('Y-m-d H:i:s'),
            'lastActive' => $lastActivity,
            'lastActivity' => $lastActivity,
            'browser' => UserAgentParser::browser($userAgent),
            'os' => $os,
            'deviceType' => $deviceType,
            'device' => trim("{$deviceType} · {$os}"),
        ];
    }
}
