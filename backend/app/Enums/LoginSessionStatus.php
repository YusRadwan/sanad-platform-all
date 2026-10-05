<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * login_sessions.status.
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │ THESE LITERALS CONTAIN SPACES AND MIXED CASE ON PURPOSE.             │
 * │                                                                      │
 * │ 'Logged Out', 'Session Expired', 'Suspicious Activity' are shipped   │
 * │ verbatim to the frontend (frontend/src/types/auth.ts LoginSession)   │
 * │ and rendered as-is in the session table the user sees. They are a    │
 * │ WIRE CONTRACT, not an internal code.                                 │
 * │                                                                      │
 * │ Normalising them to snake_case would be the single most tempting     │
 * │ "tidy-up" in this enum and would silently break the UI, because the  │
 * │ frontend is fixed and does not translate them.                       │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * The PHP case NAMES are PascalCase (idiomatic); only the backing VALUES are
 * the legacy literals. That gets type-safe call sites without touching the
 * contract.
 */
enum LoginSessionStatus: string
{
    use HasValues;

    case Active = 'Active';
    case LoggedOut = 'Logged Out';
    case SessionExpired = 'Session Expired';
    case Blocked = 'Blocked';
    case SuspiciousActivity = 'Suspicious Activity';
}
