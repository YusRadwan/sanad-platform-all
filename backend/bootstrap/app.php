<?php

declare(strict_types=1);

use App\Exceptions\Handler;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnsureCenterEnabled;
use App\Http\Middleware\EnsureEmployeePermission;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ForceJsonEnvelope;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleWithLockout;
use App\Http\Middleware\VerifyCsrfToken;
use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        /*
         * API-ONLY. There is no routes/web.php: this application serves JSON
         * to a separately-deployed React SPA and renders no HTML. Registering
         * a web route file would only create a second middleware group with
         * different CSRF/session semantics for endpoints that will never
         * exist.
         *
         * Laravel's built-in `health: '/up'` probe is likewise NOT enabled —
         * it returns an HTML page outside our envelope, which would make it
         * the one endpoint on the host that breaks the API contract.
         * GET /api/v1/health is the real probe.
         */
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        apiPrefix: 'api',

        /*
         * ┌──────────────────────────────────────────────────────────────────┐
         * │ STAGE 3 / SESSION 2 — THE TEST-ONLY VERIFICATION ROUTES.         │
         * │                                                                  │
         * │ routes/testing.php is registered ONLY when APP_ENV=testing. In    │
         * │ every other environment this closure registers nothing at all, so │
         * │ `php artisan route:list` cannot print a `_verify/*` path and the  │
         * │ router has no entry to match one against — the routes do not      │
         * │ exist to be reached, rather than existing-but-guarded.            │
         * │                                                                  │
         * │ WHY THE `then:` CALLBACK AND NOT A SERVICE PROVIDER: `then` runs  │
         * │ inside the framework's own route-registration phase, which is the │
         * │ only point where a file can join the ALREADY-CONFIGURED `api`     │
         * │ middleware group (session, CSRF, bindings) declared below. A      │
         * │ provider's boot() runs before that group exists and would have to │
         * │ restate it — a second copy of the stack, free to drift from the   │
         * │ real one, which would make every verification result a statement  │
         * │ about the copy instead of about production.                       │
         * │                                                                  │
         * │ The prefix is spelled out ('api/v1') rather than nested under     │
         * │ routes/api.php's `Route::prefix('v1')` group, because that group  │
         * │ is a closure inside another file and is not reachable from here.  │
         * │                                                                  │
         * │ Guarded by tests/Feature/Authorization/                           │
         * │ VerificationRouteIsolationTest.php, which shells out to a real    │
         * │ `artisan route:list --env=local` and asserts `_verify` is absent. │
         * └──────────────────────────────────────────────────────────────────┘
         */
        then: function (Application $app): void {
            if (! $app->environment('testing')) {
                return;
            }

            Route::middleware('api')
                ->prefix('api/v1')
                ->group(__DIR__ . '/../routes/testing.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * ┌──────────────────────────────────────────────────────────────┐
         * │ SecurityHeaders AND ForceJsonEnvelope ARE **GLOBAL**, NOT    │
         * │ PART OF THE `api` GROUP. THIS IS NOT A STYLE CHOICE.         │
         * │                                                              │
         * │ Route-group middleware only runs once a route has MATCHED.   │
         * │ A request to an undefined path never matches, so the group   │
         * │ never executes and its 404 would ship with NO security       │
         * │ headers and NO envelope — verified the hard way: both were   │
         * │ genuinely absent from a 404 while these sat in the group.    │
         * │                                                              │
         * │ Global middleware wraps the router itself, so it also        │
         * │ processes responses produced by routing failures and by the  │
         * │ exception handler. That is what makes "on EVERY response"    │
         * │ true rather than aspirational.                               │
         * │                                                              │
         * │ (backend/ hit the same problem and solved it by exposing a   │
         * │ static SecurityHeadersMiddleware::apply() that its exception │
         * │ handler called by hand. Global registration is the framework-│
         * │ native version of that fix — one code path, nothing to       │
         * │ remember to call.)                                           │
         * │                                                              │
         * │ Guarded by tests/Feature/SecurityHeadersTest::               │
         * │ test_all_five_headers_present_and_exact_on_a_404.            │
         * └──────────────────────────────────────────────────────────────┘
         *
         * ORDER WITHIN THIS PREPENDED STACK IS ALSO LOAD-BEARING:
         *
         *   SecurityHeaders  MUST be outermost. HandleCors SHORT-CIRCUITS a
         *                    preflight — it builds the 204 and returns without
         *                    calling $next — so anything registered after it
         *                    never sees an OPTIONS request. With CORS first,
         *                    a live `curl -X OPTIONS` came back with NO
         *                    security headers and a leaked
         *                    `X-Powered-By: PHP/8.3.30`. Measured, not assumed.
         *
         *   HandleCors       next, so the preflight is still answered before
         *                    the envelope layer can touch it.
         *
         *   ForceJsonEnvelope INSIDE HandleCors on purpose: a CORS preflight
         *                    must stay a bodiless 204, and wrapping it in a
         *                    JSON envelope would be a protocol violation.
         */
        $middleware->prepend([
            SecurityHeaders::class,
            HandleCors::class,
            ForceJsonEnvelope::class,
        ]);

        /*
         * The API group is declared EXPLICITLY rather than appended to
         * Laravel's default, because that default is stateless (no session,
         * no CSRF) and this application is deliberately session-cookie
         * authenticated — there is no JWT and no Sanctum token.
         *
         * Order within the group:
         *  EncryptCookies       …except `sanad_csrf` (see that class).
         *  StartSession         must precede VerifyCsrfToken, which compares
         *                       the submitted token against the session's.
         *  VerifyCsrfToken      last of the security layers, so a rejection is
         *                       still enveloped and headered by the global
         *                       middleware on the way out.
         */
        $middleware->group('api', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
        ]);

        /*
         * Stage 1 route-middleware aliases.
         *
         *  active           EnsureUserIsActive — re-checks is_active and
         *                   soft-delete status on EVERY authenticated request.
         *                   Always listed immediately after `auth`.
         *
         *  throttle.lockout ThrottleWithLockout — the fixed-window + escalating
         *                   lockout limiter, parameterised by bucket name
         *                   ('throttle.lockout:login'). Named distinctly from
         *                   Laravel's built-in `throttle` rather than shadowing
         *                   it, so a future route that genuinely wants the
         *                   framework's simpler limiter can still ask for it,
         *                   and so nobody reading a route file mistakes one for
         *                   the other. See config/ratelimit.php.
         */
        /*
         * STAGE 2 ADDITION:
         *
         *  role             EnsureUserHasRole — the coarse route-level role
         *                   gate, parameterised ('role:admin'). Replaces
         *                   backend/'s RoleMiddleware::for(...) factory, which
         *                   existed only because that container had no
         *                   middleware-parameter syntax. Always listed AFTER
         *                   `auth` and `active`; the priority entry below is
         *                   what actually enforces that.
         */
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'role' => EnsureUserHasRole::class,
            'throttle.lockout' => ThrottleWithLockout::class,
            // "زر تفعيل نظام السنتر" (migration 2026_09_10_000002) — gates
            // every /center/* instructor route behind teachers.center_enabled.
            // Always listed after `role:instructor` in the route group.
            'center.enabled' => EnsureCenterEnabled::class,
            // "إدارة الأدمنز" (decision 10.37) — page-level permission gate
            // for an employee/amin-swapped session, `employee.permission:
            // <key>`. A no-op for an ordinary teacher/admin session; always
            // listed after `role:instructor` (and after `center.enabled`
            // where both apply).
            'employee.permission' => EnsureEmployeePermission::class,
        ]);

        /*
         * ┌──────────────────────────────────────────────────────────────────┐
         * │ A GUEST IS NEVER REDIRECTED. THERE IS NO LOGIN PAGE TO REDIRECT  │
         * │ TO — THIS APPLICATION RENDERS NO HTML.                           │
         * │                                                                  │
         * │ Laravel's Authenticate middleware, on a request that does NOT    │
         * │ carry `Accept: application/json`, does not throw — it asks for a │
         * │ redirect target, and the framework's default callback is         │
         * │ `route('login')`. There is no route named `login` here, so that  │
         * │ call threw RouteNotFoundException and the endpoint answered      │
         * │ **500 instead of 401**.                                          │
         * │                                                                  │
         * │ FOUND BY A LIVE `curl`, NOT BY THE SUITE, and that is the        │
         * │ lesson: Laravel's getJson()/postJson() test helpers always set   │
         * │ the Accept header, so `expectsJson()` was true in every test and │
         * │ the redirect branch was never once executed. A plain curl — and  │
         * │ any monitor, proxy probe, or browser address-bar hit — takes the │
         * │ other branch.                                                    │
         * │                                                                  │
         * │ Returning null means "no redirect target", which sends           │
         * │ Authenticate down its throw path, and our exception handler maps │
         * │ AuthenticationException to a 401 envelope for every caller       │
         * │ regardless of what they said they accept. That matches backend/, │
         * │ whose AuthMiddleware answered 401 unconditionally.               │
         * │                                                                  │
         * │ Guarded by ExceptionEnvelopeTest::                               │
         * │ test_a_guest_without_an_accept_header_still_gets_401.            │
         * └──────────────────────────────────────────────────────────────────┘
         */
        $middleware->redirectGuestsTo(static fn (): ?string => null);

        /*
         * ┌──────────────────────────────────────────────────────────────────┐
         * │ LISTING MIDDLEWARE IN A ROUTE'S ARRAY DOES **NOT** GUARANTEE THE │
         * │ EXECUTION ORDER. THIS BLOCK IS WHAT ACTUALLY GUARANTEES IT.      │
         * │                                                                  │
         * │ Router::sortMiddleware() reorders a route's middleware against   │
         * │ the framework's priority list, and Laravel's default list places │
         * │ AuthenticatesRequests (i.e. `auth`) BEFORE ThrottleRequests.     │
         * │ Anything not on the list keeps its relative position but is      │
         * │ overtaken by anything that is.                                   │
         * │                                                                  │
         * │ Result, measured not assumed: with only the route-level array,   │
         * │ `->middleware(['throttle.lockout:…', 'auth', 'active'])` ran      │
         * │ `auth` FIRST, so an unauthenticated flood of                     │
         * │ PATCH /auth/update-credentials answered 401 forever and never    │
         * │ reached the limiter — the exact opposite of the intent, which is │
         * │ to reject abusive traffic BEFORE the per-request user lookup.    │
         * │ The test that catches this is                                    │
         * │ RateLimitTest::test_the_limiter_runs_before_auth_on_update_      │
         * │ credentials.                                                     │
         * └──────────────────────────────────────────────────────────────────┘
         *
         * The two entries below are declared against the AuthenticatesRequests
         * CONTRACT rather than the concrete Authenticate class, because that is
         * the key Laravel's own default list uses.
         */
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: ThrottleWithLockout::class,
        );

        // `active` needs the user `auth` resolved, so it must never overtake it.
        $middleware->appendToPriorityList(
            after: AuthenticatesRequests::class,
            append: EnsureUserIsActive::class,
        );

        /*
         * `role` needs the user too, and must run AFTER `active`: a
         * deactivated admin should be answered 401 (account disabled), not 403
         * (wrong role) — the first is the accurate diagnosis and the one whose
         * handling the frontend already has, since `active` also tears the
         * dead session down. Without this entry the ordering would be whatever
         * the route array happened to say, which Router::sortMiddleware is
         * free to rearrange.
         */
        $middleware->appendToPriorityList(
            after: EnsureUserIsActive::class,
            append: EnsureUserHasRole::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Reporting and rendering are both delegated to App\Exceptions\Handler
         * so the whole mapping lives in one readable table rather than being
         * spread across closures here.
         */

        // Laravel's own logging is suppressed for everything we handle (the
        // `false` return), so a failure is recorded exactly once, on the
        // channel Handler chooses.
        $exceptions->report(function (Throwable $e): bool {
            Handler::report($e);

            return false;
        });

        $exceptions->render(function (Throwable $e, $request) {
            // A database outage must be a clean 503 envelope, never a raw
            // framework page and never a message containing the DSN.
            if (Handler::isDatabaseOutage($e)) {
                return ApiResponse::error('الخدمة غير متاحة حالياً', 503, [
                    'status' => 'degraded',
                    'database' => false,
                ]);
            }

            return Handler::render($e, $request);
        });

        // Belt and braces: even if a render callback were ever bypassed, this
        // application must never fall back to an HTML debug page.
        $exceptions->shouldRenderJsonWhen(static fn (): bool => true);
    })
    ->create();
