<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\SecurityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Fixed-window rate limiter WITH AN ESCALATING LOCKOUT.
 *
 *     ->middleware('throttle.lockout:login')
 *
 * Port of backend/app/Http/Middleware/RateLimitMiddleware.php. Bucket
 * definitions live in config/ratelimit.php, which also documents why Laravel's
 * own `throttle` middleware is not sufficient (it has one fixed window and no
 * strike concept, so the tenth offence costs the same as the first).
 *
 * KEYING STRATEGY — TWO INDEPENDENT COUNTERS PER REQUEST
 * -----------------------------------------------------
 *  1. the CALLER counter — the authenticated user id when a session exists,
 *     otherwise the client IP. Using the user id for authenticated endpoints
 *     avoids punishing every student sitting behind one school/NAT address.
 *
 *  2. the IDENTIFIER counter (opt-in, `identifier_field`) — keyed on a request
 *     field such as the username being tried. Counter 1 stops one attacker
 *     hammering many accounts; counter 2 stops a botnet hammering ONE account
 *     from many IPs. Both are required; either alone leaves a hole.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE CALLER KEY IS READ STRAIGHT OUT OF THE SESSION, NOT VIA Auth::id().  │
 * │                                                                          │
 * │ Auth::id() would resolve the guard, which issues a SELECT to hydrate the │
 * │ user. This middleware runs BEFORE the auth check precisely so abusive    │
 * │ traffic is rejected without touching the database — making the cheapest  │
 * │ defence issue a query would turn it into its own DoS amplifier. Reading  │
 * │ the guard's session key gives the same id for free.                      │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * STORAGE: one cache entry per counter, mutated under an atomic cache LOCK so
 * two concurrent workers cannot both read "4 of 5" and both be allowed through
 * — the framework-native equivalent of the legacy flock() on a JSON file.
 * Counter keys are HASHED so no IP or username is ever written to the cache in
 * clear text (also carried over from the legacy design).
 *
 * FAILS OPEN, deliberately: if the cache store is unavailable the request is
 * allowed through with a warning, rather than locking every user out of the
 * platform because of an infrastructure problem. Same decision the legacy
 * limiter made for an unwritable directory.
 */
final class ThrottleWithLockout
{
    public function handle(Request $request, Closure $next, string $bucket): Response
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('ratelimit', []);

        if (!($config['enabled'] ?? true)) {
            return $next($request);
        }

        // A preflight carries no credentials and no body; counting it would
        // burn a legitimate user's budget before their real request arrives.
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $rules = array_merge(
            (array) ($config['defaults'] ?? []),
            (array) (($config['buckets'] ?? [])[$bucket] ?? [])
        );

        $caller = $this->callerKey($request);

        $this->consume(
            $bucket,
            $rules,
            $caller,
            (int) $rules['max_attempts'],
            $caller
        );

        $field = $rules['identifier_field'] ?? null;

        if (is_string($field)) {
            $value = $request->input($field);

            if (is_string($value) && $value !== '') {
                $this->consume(
                    $bucket,
                    $rules,
                    'id:' . mb_strtolower($value),
                    (int) ($rules['identifier_max_attempts'] ?? $rules['max_attempts']),
                    $field . '=' . $value
                );
            }
        }

        return $next($request);
    }

    /**
     * The authenticated user id if the session already holds one, else the IP.
     * No database access — see the class docblock.
     */
    private function callerKey(Request $request): string
    {
        if ($request->hasSession()) {
            // e.g. 'login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d'
            $sessionKey = Auth::guard('web')->getName();
            $id = $request->session()->get($sessionKey);

            if (is_string($id) && $id !== '') {
                return 'user:' . $id;
            }
        }

        return 'ip:' . (string) $request->ip();
    }

    /**
     * Reads, mutates and persists ONE counter under an exclusive lock.
     *
     * The state machine is a faithful port; each branch matters:
     *   blocked_until  — an active lockout short-circuits before anything else
     *   strikes decay  — a user who misbehaved once must not sit on the longest
     *                    tier forever
     *   window roll    — a fixed window, reset by elapsed time
     *   violation      — strike++, penalty = lockout × strikes (capped), and
     *                    the window counter is RESET so the client cannot
     *                    accumulate a second violation inside one lockout
     *
     * @param array<string, mixed> $rules
     *
     * @throws TooManyRequestsHttpException
     */
    private function consume(string $bucket, array $rules, string $subject, int $maxAttempts, string $logSubject): void
    {
        $now = time();
        $window = (int) $rules['window_seconds'];
        $lockout = (int) $rules['lockout_seconds'];
        $maxLockout = (int) $rules['max_lockout_seconds'];
        $decay = (int) $rules['strike_decay_seconds'];

        // Hashed: no IP or username in clear text in the cache store.
        $key = 'ratelimit:' . $bucket . ':' . hash('sha256', $bucket . '|' . $subject);
        $ttl = max($window, $maxLockout, $decay) + 60;

        $lock = Cache::lock($key . ':lock', 5);

        try {
            // block() waits briefly for a competing worker rather than giving
            // up; 3 s is far longer than the microseconds this section takes.
            $lock->block(3);
        } catch (\Throwable $e) {
            SecurityLog::warning('Rate limiter lock unavailable, request allowed', [
                'bucket' => $bucket,
                'error' => $e->getMessage(),
            ]);

            return; // fail OPEN
        }

        try {
            $state = Cache::get($key);
            $state = is_array($state) ? $state : [];
            $state += [
                'count' => 0,
                'window_start' => $now,
                'strikes' => 0,
                'blocked_until' => 0,
                'last_strike' => 0,
            ];

            // Still serving an active lockout.
            if ((int) $state['blocked_until'] > $now) {
                $retryAfter = (int) $state['blocked_until'] - $now;
                Cache::put($key, $state, $ttl);
                $this->reject($bucket, $retryAfter, $logSubject, (int) $state['strikes']);
            }

            // Strikes decay after a quiet period.
            if ((int) $state['last_strike'] > 0 && $now - (int) $state['last_strike'] > $decay) {
                $state['strikes'] = 0;
            }

            // New fixed window?
            if ($now - (int) $state['window_start'] >= $window) {
                $state['window_start'] = $now;
                $state['count'] = 0;
            }

            $state['count'] = (int) $state['count'] + 1;

            if ((int) $state['count'] > $maxAttempts) {
                $state['strikes'] = (int) $state['strikes'] + 1;
                $state['last_strike'] = $now;

                $penalty = min($lockout * (int) $state['strikes'], $maxLockout);

                $state['blocked_until'] = $now + $penalty;
                $state['count'] = 0;
                $state['window_start'] = $now;

                Cache::put($key, $state, $ttl);
                $this->reject($bucket, $penalty, $logSubject, (int) $state['strikes']);
            }

            Cache::put($key, $state, $ttl);
        } finally {
            $lock->release();
        }
    }

    /**
     * The 429 itself.
     *
     * TooManyRequestsHttpException carries Retry-After in its headers, which
     * App\Exceptions\Handler turns into BOTH the `Retry-After` HTTP header and
     * `data.retry_after`, with the exact Arabic message. That mapping was built
     * in Stage 0; this is the first throw site that actually exercises it.
     *
     * Logged HERE and not in the Handler — the Handler deliberately skips 429
     * so a throttled request is not double-counted.
     */
    private function reject(string $bucket, int $retryAfter, string $subject, int $strikes): never
    {
        $retryAfter = max(1, $retryAfter);

        SecurityLog::warning('Rate limit exceeded', [
            'event' => 'ratelimit.exceeded',
            'bucket' => $bucket,
            'subject' => $subject,
            'strikes' => $strikes,
            'retry_after' => $retryAfter,
        ]);

        throw new TooManyRequestsHttpException(
            $retryAfter,
            'عدد المحاولات كبير جداً. يرجى المحاولة مرة أخرى بعد ' . $retryAfter . ' ثانية'
        );
    }
}
