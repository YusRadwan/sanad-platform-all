<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

/**
 * Cookie encryption, with ONE deliberate exception.
 *
 * `sanad_csrf` must stay PLAINTEXT in both directions, because
 * frontend/src/services/api.ts reads it with a plain `document.cookie` regex
 * and echoes the raw value back as the X-CSRF-Token header. If Laravel
 * encrypted it, the frontend would send ciphertext, the server would compare
 * ciphertext against the session's plaintext token, and every mutating request
 * would fail with 419.
 *
 * See App\Http\Middleware\VerifyCsrfToken for the full contract. Do not add
 * anything else to this list: every other cookie benefits from encryption, and
 * this one is exempt only because a fixed client already depends on reading it.
 *
 * The SESSION cookie (`sanad_session`) is NOT in this list and stays
 * encrypted + HttpOnly — that is what actually protects the session.
 */
final class EncryptCookies extends Middleware
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * @var array<int, string>
     */
    protected $except = [
        'sanad_csrf',
    ];
}
