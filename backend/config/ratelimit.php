<?php

declare(strict_types=1);

/**
 * Rate-limiting configuration, ported value-for-value from
 * backend/config/ratelimit.php.
 *
 * Each bucket is a FIXED WINDOW plus an ESCALATING LOCKOUT: exceeding the
 * window increments a "strike" counter and the client is blocked for
 * lockout_seconds × strikes (capped by max_lockout_seconds). Strikes decay once
 * strike_decay_seconds elapse without a violation.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ WHY NOT Laravel's `throttle` MIDDLEWARE / RateLimiter FACADE?            │
 * │                                                                          │
 * │ Laravel's Limit is a single fixed window with ONE decay. It has no       │
 * │ concept of strikes, so a bot that hits the wall every minute waits the   │
 * │ same 60 s forever. The whole point of the legacy design is that the      │
 * │ SECOND offence costs 10 minutes and the tenth costs an hour, which is    │
 * │ what actually makes credential stuffing uneconomic.                      │
 * │                                                                          │
 * │ App\Http\Middleware\ThrottleWithLockout therefore keeps the state        │
 * │ machine but drops the legacy's hand-rolled flock()/JSON-file storage in  │
 * │ favour of the cache store (see that class). No new package.              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * STORAGE NOTE vs backend/: the legacy limiter wrote flat files under
 * storage/cache/ratelimit because "no framework, PDO/file-based only" was a
 * hard constraint. Here the state lives in Laravel's cache store, whose DEFAULT
 * driver in this project is `file` — i.e. the same storage medium, reached
 * through the framework's own atomic-lock abstraction instead of a hand-written
 * flock() loop, and swappable for Redis later with no code change. Laravel's
 * cache also has its own expiry, so the legacy's probabilistic GC sweep
 * (gc_probability / gc_max_age_seconds) has no equivalent here and is gone.
 */

return [

    'enabled' => (bool) env('RATE_LIMIT_ENABLED', true),

    /*
     * Applied to every bucket, then overridden per bucket below.
     */
    'defaults' => [
        'max_attempts' => 30,
        'window_seconds' => 60,
        'lockout_seconds' => 60,
        'max_lockout_seconds' => 3600,
        'strike_decay_seconds' => 3600,
    ],

    /**
     * Per-endpoint buckets.
     *
     * `identifier_field` = keep a SECOND counter keyed on a request field (the
     * username being tried), so a distributed attack from many IPs against ONE
     * account is throttled too. Counter 1 (caller) stops one attacker hammering
     * many accounts; counter 2 stops many attackers hammering one account.
     * Both are required — either alone leaves a hole.
     */
    'buckets' => [

        'login' => [
            // ThrottleWithLockout counts EVERY request through this route
            // unconditionally, success or failure — it runs before the
            // controller and never learns which. At 5/60s, a legitimate
            // user just opening and closing their own account a handful of
            // times inside a minute (completely normal exploratory use, no
            // failed password anywhere in the sequence) tripped the same
            // lockout meant for a brute-force attacker, and got locked out
            // of their own account for 5 minutes up to an hour. Raised so
            // ordinary repeated logins clear it, while a real brute-force
            // script (dozens of attempts per second) still trips it fast.
            'max_attempts' => 20,
            'window_seconds' => 60,
            'lockout_seconds' => 300,      // 5 min after the first violation,
            'max_lockout_seconds' => 3600, // growing to 1 h for repeat offenders
            'identifier_field' => 'username',
            'identifier_max_attempts' => 30, // per-account cap across all IPs
        ],

        // Mass account-creation abuse.
        'register' => [
            'max_attempts' => 3,
            'window_seconds' => 3600,
            'lockout_seconds' => 3600,
            'max_lockout_seconds' => 21600,
        ],

        // The current password is verified here, so this is a brute-force
        // target too and gets its own bucket ahead of the auth check.
        'update-credentials' => [
            'max_attempts' => 5,
            'window_seconds' => 300,
            'lockout_seconds' => 900,
            'max_lockout_seconds' => 3600,
        ],

        /*
         * Declared now, unused until the stages that own these endpoints, so
         * the ported values stay in one place rather than being rediscovered
         * from backend/config/ratelimit.php three stages from now.
         */
        'upload' => [
            'max_attempts' => 20,
            'window_seconds' => 60,
            'lockout_seconds' => 120,
            'max_lockout_seconds' => 1800,
        ],

        'submit' => [
            'max_attempts' => 10,
            'window_seconds' => 60,
            'lockout_seconds' => 120,
            'max_lockout_seconds' => 1800,
        ],

        'broadcast' => [
            'max_attempts' => 10,
            'window_seconds' => 300,
            'lockout_seconds' => 300,
            'max_lockout_seconds' => 1800,
        ],

        // Public "طلب مدرس جديد" form — same spam-defence shape as `register`
        // (mass-submission abuse from one IP), open decision 10.23.
        'teacher-application' => [
            'max_attempts' => 5,
            'window_seconds' => 3600,
            'lockout_seconds' => 3600,
            'max_lockout_seconds' => 21600,
        ],
    ],
];
