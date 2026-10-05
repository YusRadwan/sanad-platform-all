<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Thin, intention-revealing façade over the two Stage-0 log channels.
 *
 * backend/app/Core/Logger.php was a hand-rolled static file logger because the
 * legacy codebase had no dependency able to do it. Laravel's channel system is
 * that same behaviour as a first-party feature, so NOTHING about the writing is
 * re-implemented here — this class only encodes the two *semantic* decisions
 * the legacy logger made, which a bare `Log::channel()` call would otherwise
 * leave to each call site to remember:
 *
 *  1. `security()` always lands on the `security` channel regardless of level.
 *
 *  2. `criticalOperation()` writes the SAME record to BOTH channels
 *     (Logger::critical_operation did exactly this): the `app` log is the
 *     operational history, the `security` log is what an auditor reads end to
 *     end. Losing the duplication would silently remove password changes from
 *     the audit trail.
 *
 * Legacy Logger::requestContext() also auto-attached ip/method/path/user/agent
 * to every line. That is reproduced by context() below so security lines stay
 * as greppable as they were, and — like the legacy version and like
 * App\Exceptions\Handler — it NEVER touches the request body, because a login
 * failure would otherwise write the submitted password into storage/logs.
 */
final class SecurityLog
{
    /** @param array<string,mixed> $context */
    public static function security(string $level, string $message, array $context = []): void
    {
        Log::channel('security')->log(strtolower($level), $message, $context + self::requestContext());
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::security('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::security('warning', $message, $context);
    }

    /**
     * Audit-trail entry for a critical business operation (credential changes,
     * money, access grants). Written to BOTH channels — see the class docblock.
     *
     * @param array<string,mixed> $context
     */
    public static function criticalOperation(string $operation, array $context = []): void
    {
        $merged = $context + self::requestContext();

        Log::channel('app')->notice('CRITICAL_OP ' . $operation, $merged);
        Log::channel('security')->notice('CRITICAL_OP ' . $operation, $merged);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        Log::channel('app')->error($message, $context + self::requestContext());
    }

    /**
     * Plumbing context attached to every line so a call site does not have to
     * repeat it. NOTHING FROM THE REQUEST BODY IS INCLUDED.
     *
     * @return array<string,mixed>
     */
    private static function requestContext(): array
    {
        $request = request();

        return array_filter([
            'ip' => $request?->ip(),
            'method' => $request?->getMethod(),
            'path' => $request?->getPathInfo(),
            'user_id' => $request?->user()?->getAuthIdentifier(),
            'role' => $request?->user()?->role?->value,
            'user_agent' => $request?->userAgent(),
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
