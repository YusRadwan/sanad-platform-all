<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Central exception -> HTTP status -> envelope translator, plus the single
 * place failed requests get logged.
 *
 * Registered from bootstrap/app.php. Ported from
 * backend/app/Core/ExceptionHandler.php, preserving its status mapping
 * EXACTLY — including the two places that deviate from Laravel's defaults.
 *
 * ┌─ MAPPING ───────────────────────────────┬────────┬──────────────────────┐
 * │ ValidationException                     │ 422    │ data.errors = {...}  │
 * │ AuthenticationException                 │ 401    │ —                    │
 * │ AuthorizationException / AccessDenied   │ 403    │ —                    │
 * │  …with ->status() (Response::denyAs…)   │ that   │ authored message     │
 * │ TokenMismatchException (CSRF)           │ 403    │ — (NOT Laravel's 419)│
 * │ ModelNotFound / NotFoundHttpException   │ 404    │ —                    │
 * │ MethodNotAllowedHttpException           │ 404    │ — (NOT 405)          │
 * │ TooManyRequests                         │ 429    │ data.retry_after     │
 * │ anything else                           │ 500    │ generic message      │
 * └─────────────────────────────────────────┴────────┴──────────────────────┘
 *
 * TWO DELIBERATE DEVIATIONS FROM LARAVEL DEFAULTS, both preserving the old
 * API contract:
 *
 *  * 405 -> 404. backend/'s Router::dispatch() matched on method AND path
 *    together and threw NotFoundException when nothing matched, so a
 *    wrong-method request against an existing path has ALWAYS answered 404.
 *    Laravel distinguishes the two and would answer 405. Anything already
 *    written against the old behaviour (monitoring, client retry logic) would
 *    see a behaviour change, so 405 is folded into 404. It is also the more
 *    conservative answer: it does not confirm that a path exists.
 *
 *  * 419 -> 403. Laravel's CSRF failure is 419 (a Laravel-specific code);
 *    backend/'s CsrfMiddleware threw ForbiddenException, i.e. 403.
 *
 * VALIDATION ERRORS ARE NESTED UNDER `data.errors`, not at the top level.
 * Laravel's default shape is {message, errors:{...}}; the contract here is
 * {success, message, data:{errors:{...}}}. Consumers read `data.errors`.
 *
 * LOGGING happens on REPORT, not on render, so a failure is recorded even if
 * something later swallows or rewrites the response:
 *    401 -> security channel, WARNING, 'Unauthorized access'
 *    403 -> security channel, WARNING, 'Forbidden'
 *   >=500 -> app channel, ERROR, with trace
 *    429 -> NOT logged here (the limiter owns that decision)
 *   4xx  -> app channel, INFO
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │ THE LOG RECORD NEVER CONTAINS THE REQUEST BODY.                      │
 * │ context() below assembles ip/method/path/user/agent by hand and      │
 * │ deliberately omits input — a 422 on the login form would otherwise   │
 * │ write the submitted password into storage/logs in plaintext.         │
 * └──────────────────────────────────────────────────────────────────────┘
 */
final class Handler
{
    /**
     * Maps a throwable to its HTTP status, or null to fall through to 500.
     */
    public static function statusFor(Throwable $e): ?int
    {
        /*
         * CSRF NEEDS TWO CHECKS, NOT ONE.
         *
         * Laravel's own Handler::prepareException() rewrites the exception
         * BEFORE any custom render callback runs:
         *
         *   $e instanceof TokenMismatchException
         *       => new HttpException(419, $e->getMessage(), $e)
         *
         * So by the time this method is reached, a CSRF failure is usually an
         * HttpException carrying 419, with the TokenMismatchException demoted
         * to `previous`. Matching only on TokenMismatchException therefore
         * silently misses every real CSRF rejection and lets 419 through —
         * measured, not assumed: the contract tests returned 419 until this
         * branch was added.
         */
        if ($e instanceof TokenMismatchException
            || $e->getPrevious() instanceof TokenMismatchException
            || ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 419)
        ) {
            return 403;
        }

        /*
         * ┌──────────────────────────────────────────────────────────────────┐
         * │ STAGE 9 — AN AuthorizationException MAY CARRY ITS OWN STATUS.    │
         * │                                                                  │
         * │ A policy that returns `Illuminate\Auth\Access\Response::         │
         * │ denyAsNotFound()` is declaring that the resource's very          │
         * │ EXISTENCE is the secret — the oracle argument documented at      │
         * │ App\Support\Authorization\DenyMode. Laravel records that as a    │
         * │ `status` on the Response, which `authorize()` copies onto the    │
         * │ exception via `withStatus()`.                                    │
         * │                                                                  │
         * │ Without this branch the mapping below would flatten it to 403 —  │
         * │ i.e. the framework would confirm that a guessed UUID names a     │
         * │ real hidden lesson, which is exactly the disclosure the policy   │
         * │ took the trouble to avoid. `status()` is null for every ordinary │
         * │ denial, so nothing else changes.                                 │
         * │                                                                  │
         * │ ContentItemPolicy::downloadFile (freeze-table row 73) is the     │
         * │ first and currently only user.                                   │
         * └──────────────────────────────────────────────────────────────────┘
         */
        $authorization = self::authorizationExceptionIn($e);

        if ($authorization?->status() !== null) {
            return $authorization->status();
        }

        return match (true) {
            $e instanceof ValidationException => 422,
            $e instanceof AuthenticationException => 401,
            $e instanceof AuthorizationException => 403,
            $e instanceof AccessDeniedHttpException => 403,
            $e instanceof ModelNotFoundException => 404,
            $e instanceof NotFoundHttpException => 404,
            // Preserved from backend/: a wrong method on an existing path is a
            // 404, not a 405. See the class docblock.
            $e instanceof MethodNotAllowedHttpException => 404,
            $e instanceof TooManyRequestsHttpException => 429,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            default => null,
        };
    }

    /**
     * Builds the JSON envelope for a throwable.
     */
    public static function render(Throwable $e, Request $request): \Illuminate\Http\JsonResponse
    {
        $status = self::statusFor($e);

        // ── 422: errors nested under data.errors
        if ($e instanceof ValidationException) {
            return ApiResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : 'Validation failed',
                422,
                ['errors' => $e->errors()]
            );
        }

        // ── 429: retry_after in the payload AND as a real header
        if ($e instanceof TooManyRequestsHttpException) {
            $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);
            $retryAfter = max(1, $retryAfter);

            $response = ApiResponse::error(
                "عدد المحاولات كبير جداً. يرجى المحاولة مرة أخرى بعد {$retryAfter} ثانية",
                429,
                ['retry_after' => $retryAfter]
            );
            $response->headers->set('Retry-After', (string) $retryAfter);

            return $response;
        }

        if ($status !== null && $status < 500) {
            return ApiResponse::error(self::messageFor($e, $status), $status);
        }

        // ── 5xx: never leak internals unless APP_DEBUG is explicitly on.
        $debug = (bool) config('app.debug');

        return ApiResponse::error(
            $debug ? $e->getMessage() : 'Internal server error',
            $status ?? 500,
            $debug ? ['exception' => $e::class, 'trace' => $e->getTraceAsString()] : null
        );
    }

    /**
     * Central logging. Called on REPORT.
     */
    public static function report(Throwable $e): void
    {
        $status = self::statusFor($e) ?? 500;

        // The rate limiter owns its own logging decision; recording a 429 here
        // as well would double-count every throttled request.
        if ($status === 429) {
            return;
        }

        $context = self::context($e, $status);

        if ($status === 401) {
            Log::channel('security')->warning('Unauthorized access', $context);

            return;
        }

        if ($status === 403) {
            Log::channel('security')->warning('Forbidden', $context);

            return;
        }

        if ($status >= 500) {
            Log::channel('app')->error('Server error', $context + [
                'trace' => $e->getTraceAsString(),
            ]);

            return;
        }

        Log::channel('app')->info('Client error', $context);
    }

    /**
     * Log context. Assembled explicitly, field by field.
     *
     * NOTHING FROM THE REQUEST BODY IS INCLUDED — see the class docblock.
     */
    private static function context(Throwable $e, int $status): array
    {
        $request = request();

        return array_filter([
            'status' => $status,
            'exception' => $e::class,
            'error' => $e->getMessage(),
            'ip' => $request?->ip(),
            'method' => $request?->getMethod(),
            'path' => $request?->getPathInfo(),
            'user_id' => $request?->user()?->getAuthIdentifier(),
            'user_agent' => $request?->userAgent(),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * A safe, non-leaking message for a 4xx.
     *
     * A NotFoundHttpException's own message embeds the matched route/model
     * class ("No query results for model [App\Models\User] 123"), which
     * discloses internals, so 404s always get a generic phrase.
     *
     * STAGE 1 ADDITION — the PublicMessage opt-out. Genericising EVERY 401 was
     * correct while no endpoint had anything to say at 401. The auth flow does:
     * backend/ answered 'اسم المستخدم أو كلمة المرور غير صحيحة' and
     * 'هذا الحساب غير مفعل' with that status and the login page renders both.
     * An exception that implements App\Exceptions\PublicMessage is declaring
     * that its message was authored for the user, so it is passed through.
     * Everything else — every framework exception, every future accidental
     * throw — still gets the generic phrase. The default stays closed.
     */
    private static function messageFor(Throwable $e, int $status): string
    {
        if ($e instanceof PublicMessage && $e->getMessage() !== '') {
            return $e->getMessage();
        }

        /*
         * STAGE 9 — THE SECOND OPT-OUT, AND IT IS NARROWER THAN IT LOOKS.
         *
         * A policy that returns `Response::deny('You do not have access to
         * this file')` authored that sentence for the user, exactly as a
         * PublicMessage exception does. Laravel keeps the Response on the
         * exception (`setResponse`), so the authored message is recoverable —
         * and only then.
         *
         * A policy that returns a bare `false` is wrapped by the Gate in a
         * Response with a NULL message, so `message()` is null and the
         * generic phrase below still applies. That is the important half: the
         * default stays closed, and an ability has to opt in by writing a
         * sentence to get one through.
         */
        $authored = self::authorizationExceptionIn($e)?->response()?->message();

        if (is_string($authored) && $authored !== '') {
            return $authored;
        }

        return match ($status) {
            401 => 'Unauthenticated',
            403 => 'Forbidden',
            404 => 'Not found',
            default => $e->getMessage() !== '' ? $e->getMessage() : 'Error',
        };
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ AN AuthorizationException IS USUALLY NOT AN AuthorizationException   ║
     * ║ BY THE TIME IT REACHES US — THE SAME TRAP THE CSRF BRANCH ABOVE      ║
     * ║ DOCUMENTS, AND IT COST THE SAME DEBUGGING SESSION TO FIND.           ║
     * ║                                                                      ║
     * ║ Laravel's own Handler::prepareException() REWRITES it before any     ║
     * ║ custom render callback runs:                                          ║
     * ║                                                                      ║
     * ║   $e instanceof AuthorizationException && $e->hasStatus()             ║
     * ║       => new HttpException($e->status(), …->message(), $e)            ║
     * ║   $e instanceof AuthorizationException && ! $e->hasStatus()            ║
     * ║       => new AccessDeniedHttpException($e->getMessage(), $e)          ║
     * ║                                                                      ║
     * ║ So the real exception is demoted to `previous`, and matching only on ║
     * ║ the outer type silently misses EVERY genuine policy refusal. Measured║
     * ║ not assumed: row 73 answered 403 'Forbidden' and 404 'Not found' —   ║
     * ║ the right statuses with the wrong bodies — until this walked the     ║
     * ║ chain.                                                                ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    private static function authorizationExceptionIn(Throwable $e): ?AuthorizationException
    {
        $candidate = $e;

        while ($candidate !== null) {
            if ($candidate instanceof AuthorizationException) {
                return $candidate;
            }

            $candidate = $candidate->getPrevious();
        }

        return null;
    }

    /**
     * A database connectivity failure must surface as a clean 503 envelope,
     * never as a raw framework error page — and never with the DSN, user or
     * host in the message, all of which a PDOException happily includes.
     */
    public static function isDatabaseOutage(Throwable $e): bool
    {
        $candidate = $e;
        while ($candidate !== null) {
            if ($candidate instanceof PDOException) {
                return true;
            }
            $candidate = $candidate->getPrevious();
        }

        return false;
    }
}
