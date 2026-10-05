<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\LoginUser;
use App\Actions\Auth\RegisterStudent;
use App\Actions\Auth\StopImpersonation;
use App\Actions\Auth\UpdateCredentials;
use App\Enums\LoginSessionStatus;
use App\Exceptions\UnauthorizedException;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdateCredentialsRequest;
use App\Exceptions\ForbiddenException;
use App\Http\Resources\PublicUser;
use App\Http\Responses\ApiResponse;
use App\Models\LoginSession;
use App\Models\User;
use App\Support\EmployeeSession;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The six auth endpoints, matching frontend/src/services/authService.ts exactly.
 *
 * The controller is deliberately THIN: validation lives in the Form Requests,
 * business rules in the Actions, the response shape in the PublicUser resource.
 * What is left here is HTTP wiring and the exact success messages, which are a
 * contract and are therefore all visible on one screen.
 */
final class AuthController extends Controller
{
    /**
     * POST /auth/login — CSRF-exempt (a client cannot hold a token before it
     * has a session), rate-limited by IP AND by targeted username.
     */
    public function login(LoginRequest $request, LoginUser $login): JsonResponse
    {
        $result = $login(
            $request,
            (string) $request->validated('username'),
            (string) $request->validated('password'),
        );

        return ApiResponse::success(
            ['user' => new PublicUser($result['user'])],
            'تم تسجيل الدخول بنجاح'
        );
    }

    /**
     * POST /auth/register — CSRF-exempt, 3 per hour per IP.
     *
     * Returns 201 with {studentCode, userId}. `studentCode` is what the signup
     * wizard's final step shows the student.
     */
    public function register(RegisterRequest $request, RegisterStudent $register): JsonResponse
    {
        $result = $register($request->validated());

        return ApiResponse::created($result, 'تم إنشاء الحساب بنجاح');
    }

    /**
     * POST /auth/logout — requires an active session.
     *
     * The login_sessions row is closed BEFORE the session is destroyed, because
     * its id is stored in the session being destroyed.
     */
    public function logout(Request $request): JsonResponse
    {
        SecurityLog::info('Logout', [
            'event' => 'logout',
            'username' => $request->user()?->username,
        ]);

        $loginSessionId = $request->session()->get(LoginUser::SESSION_LOGIN_ROW);

        if (is_string($loginSessionId) && $loginSessionId !== '') {
            LoginSession::query()
                ->whereKey($loginSessionId)
                ->update([
                    'status' => LoginSessionStatus::LoggedOut->value,
                    'last_activity_at' => now(),
                ]);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::success(null, 'تم تسجيل الخروج بنجاح');
    }

    /** GET /auth/me — the canonical session-user payload. */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(['user' => new PublicUser($user)], 'OK');
    }

    /**
     * POST /auth/refresh.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THIS ROUTE MUST **NOT** CARRY THE `auth` MIDDLEWARE. NOT AN OVERSIGHT.│
     * │                                                                      │
     * │ frontend/src/services/api.ts installs a response interceptor:         │
     * │                                                                      │
     * │   if (status === 401 && !originalRequest._retry                       │
     * │       && !isAuthFlowEndpoint) {   // refresh, login, register         │
     * │     originalRequest._retry = true;                                    │
     * │     await api.post('/auth/refresh', {});   // <- recovery attempt     │
     * │     return api(originalRequest);           // <- replay               │
     * │   }                                                                   │
     * │                                                                      │
     * │ i.e. a 401 on any call OTHER than login/register/refresh itself       │
     * │ triggers exactly one refresh attempt and one replay; a refresh that   │
     * │ itself fails means "really logged out" and the interceptor clears     │
     * │ sessionStorage and redirects to /login. `/auth/login` and             │
     * │ `/auth/register` are excluded too (not just `/auth/refresh`) — a      │
     * │ rejected login attempt is not "an expired session", and retrying it   │
     * │ through refresh used to swallow the real Arabic error message (wrong  │
     * │ credentials, disabled account, …) and replace it with THIS endpoint's │
     * │ own failure message instead. The contract this endpoint owes the      │
     * │ client is simply: answer 401 cleanly, from INSIDE the handler, when   │
     * │ there is no session. Wrapping it in `auth` would produce the same     │
     * │ status from a layer that cannot log the event and would make the      │
     * │ route's purpose unreadable.                                           │
     * │                                                                      │
     * │ It IS still CSRF-protected: api.ts posts it through the same axios    │
     * │ instance so the X-CSRF-Token interceptor applies (a previous bug      │
     * │ where it did not made every refresh 403 and forced false logouts).    │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * Since we use session cookies and not JWTs, "refresh" means: confirm the
     * session is still valid, slide its expiry and regenerate the id to reduce
     * fixation risk.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user instanceof User) {
            throw new UnauthorizedException('انتهت صلاحية الجلسة، يرجى تسجيل الدخول مرة أخرى');
        }

        // Added to the legacy behaviour on purpose: a session whose account was
        // disabled or deleted mid-flight must not be renewable. Without this,
        // refresh would answer 200 and the client would replay into a 401 —
        // ending in the same logout, one round-trip later. Answering 401 here
        // gets to the correct outcome immediately and cannot loop, because
        // api.ts never retries the refresh call itself.
        if ($user->trashed() || !$user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new UnauthorizedException('Account is disabled or no longer exists');
        }

        // session_regenerate_id(true): new id, old record destroyed, payload
        // (including the auth state) carried over.
        $request->session()->migrate(true);

        $loginSessionId = $request->session()->get(LoginUser::SESSION_LOGIN_ROW);

        if (is_string($loginSessionId) && $loginSessionId !== '') {
            LoginSession::query()
                ->whereKey($loginSessionId)
                ->update(['last_activity_at' => now()]);
        }

        return ApiResponse::success(['refreshed' => true], 'Session refreshed');
    }

    /**
     * POST /auth/stop-impersonation — open decision 10.18. See
     * App\Actions\Auth\StopImpersonation for the full contract; this method
     * is pure HTTP wiring, same division of labour as every other action in
     * this controller.
     */
    public function stopImpersonation(Request $request, StopImpersonation $stop): JsonResponse
    {
        $admin = $stop($request);

        return ApiResponse::success(['user' => new PublicUser($admin)], 'تم الرجوع لحساب الأدمن بنجاح');
    }

    /**
     * PATCH /auth/update-credentials — rate-limited BEFORE the auth check, so
     * abusive traffic costs no database lookup.
     */
    public function updateCredentials(
        UpdateCredentialsRequest $request,
        UpdateCredentials $update
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        // "إدارة الأدمنز" (decision 10.37) — an acting employee/amin session
        // authenticates AS the owning teacher (App\Actions\Auth\LoginUser's
        // identity swap), so `$user` here would otherwise be the TEACHER's
        // own row: without this guard an employee could change the
        // TEACHER's login username/password/email, not their own. In
        // practice `UpdateCredentials`' re-authentication gate already makes
        // this impossible (an employee never knows the teacher's real
        // current password), but that is defence by accident of a DIFFERENT
        // rule — this is the explicit, intentional block the requirement
        // asks for ("حتى لو تم التلاعب بالـ Frontend، يجب أن يمنع Backend").
        // An employee changes their OWN credentials through their OWN
        // `users` row via the ordinary self-service surface for their role,
        // exactly like every other user — this endpoint is simply not it
        // while a swap is active.
        if (EmployeeSession::actingEmployeeUserId($request) !== null) {
            throw new ForbiddenException('لا يمكن للموظف تعديل بيانات دخول المدرّس');
        }

        $result = $update($request, $user, $request->all());

        return ApiResponse::success($result, 'تم تحديث البيانات بنجاح');
    }
}
