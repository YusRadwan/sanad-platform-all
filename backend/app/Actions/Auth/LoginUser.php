<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\LoginSessionStatus;
use App\Enums\UserRole;
use App\Exceptions\UnauthorizedException;
use App\Models\Employee;
use App\Models\LoginSession;
use App\Models\User;
use App\Support\EmployeeSession;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Port of backend/app/Services/AuthService::login().
 *
 * ORDER IS PART OF THE CONTRACT: password first, THEN is_active, THEN the
 * instructor-only login block.
 *   - unknown username / wrong password -> 'اسم المستخدم أو كلمة المرور غير صحيحة'
 *   - correct password, disabled account -> 'هذا الحساب غير مفعل'
 *   - correct password, ACTIVE account whose teacher profile carries
 *     login_blocked -> 'تم منع هذا الحساب من تسجيل الدخول'
 * Checking is_active first would tell an attacker that a username exists
 * before they have proven anything; checking it never would leave a disabled
 * user staring at "wrong password" forever. This ordering is the legacy
 * behaviour and the frontend's login page renders both strings.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ WHY NOT `Auth::attempt(['username' => …, 'password' => …])`?             │
 * │                                                                          │
 * │ attempt() collapses "no such user" and "bad password" into one boolean   │
 * │ — which is fine — but it gives no way to run the is_active check BETWEEN │
 * │ a successful password verification and the session being established.    │
 * │ Adding `is_active => 1` to the credential array would fold the disabled  │
 * │ account back into the generic failure and lose 'هذا الحساب غير مفعل'.    │
 * │                                                                          │
 * │ Everything else IS the native guard: Auth::login() writes the session    │
 * │ key AND calls Session::migrate(true) internally, which is precisely      │
 * │ session_regenerate_id(true) — the fixation defence the legacy code       │
 * │ called by hand. Hash::check() is password_verify() with the project's    │
 * │ bcrypt configuration.                                                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * The CSRF token is deliberately NOT rotated here (Auth::login migrates the
 * session ID but leaves the token). backend/ behaved the same way, and the
 * fixed frontend caches the token it read from the cookie for the lifetime of
 * the page.
 */
final class LoginUser
{
    /** Session key holding the login_sessions row id, so logout can close it. */
    public const SESSION_LOGIN_ROW = 'login_session_id';

    /**
     * @return array{user: User}
     *
     * @throws UnauthorizedException
     */
    public function __invoke(Request $request, string $username, string $password): array
    {
        $ip = (string) $request->ip();

        $user = User::query()->where('username', $username)->first();

        if ($user === null || !Hash::check($password, $user->getAuthPassword())) {
            // NEVER the submitted password — only the username that was
            // targeted, so repeated failures against one account are visible.
            SecurityLog::warning('Login failed', [
                'event' => 'login.failure',
                'username' => $username,
                'reason' => $user !== null ? 'bad_password' : 'unknown_username',
                'ip' => $ip,
            ]);

            throw new UnauthorizedException('اسم المستخدم أو كلمة المرور غير صحيحة');
        }

        if (!$user->is_active) {
            SecurityLog::warning('Login rejected for inactive account', [
                'event' => 'login.inactive',
                'username' => $username,
                'target_user_id' => $user->getKey(),
                'ip' => $ip,
            ]);

            throw new UnauthorizedException('هذا الحساب غير مفعل');
        }

        // ── THE SECOND, INDEPENDENT GATE: teachers.login_blocked ──────────
        //
        // Migration 000037. STRICTLY AFTER the is_active check, never before:
        // a fully suspended teacher who is ALSO login-blocked must keep getting
        // the original 'هذا الحساب غير مفعل', because suspension is the larger
        // statement (invisible on the site AND locked out) and the admin who
        // suspended them expects that message. This branch only adds a NEW
        // rejection path for the OTHER case — visible on the site, locked out
        // of the account — and changes no existing precedence.
        //
        // A DIFFERENT Arabic sentence on purpose. The two states are not the
        // same thing and the teacher who reads the message needs to know which
        // one applies: 'غير مفعل' means "your account is off"; the string below
        // means "your account is fine, sign-in specifically is barred".
        //
        // ROLE-GATED so it can never touch a student or an admin: only an
        // instructor HAS a `teachers` row, and getRelationValue('teacher')
        // resolves to null for everyone else — so the whole condition
        // short-circuits to false and non-instructor logins are untouched
        // (the ->teacher() relation is lazy-loaded here rather than eager-
        // loaded on the User::query() above, so the extra SELECT happens only
        // on the instructor branch and only after the password already passed).
        if ($user->role === UserRole::Instructor) {
            $teacher = $user->getRelationValue('teacher');

            if ($teacher !== null && (bool) $teacher->getAttribute('login_blocked') === true) {
                SecurityLog::warning('Login rejected for login-blocked teacher', [
                    'event' => 'login.blocked',
                    'username' => $username,
                    'target_user_id' => $user->getKey(),
                    'ip' => $ip,
                ]);

                throw new UnauthorizedException('تم منع هذا الحساب من تسجيل الدخول');
            }
        }

        // ── "إدارة الأدمنز" (decisions 10.37 + 10.40) — THE IDENTITY SWAP ──
        //
        // Every check below this point ran against the EMPLOYEE's own row
        // (the one whose password was just verified) — exactly as an
        // ordinary login would. What happens next is the one deliberate
        // divergence: instead of authenticating the session AS that row, it
        // authenticates AS the OWNER's row — a TEACHER (decision 10.37) or
        // an ADMIN (decision 10.40, "موظفو الأدمن منفصلون عن المدرّسين
        // كلياً") — so every existing `teacher_id === $user->id` ownership
        // check in the codebase (there is no shared trait for it — see
        // Course::scopeOwnedBy and its copies) keeps working completely
        // unmodified, AND — for an admin-owned employee — `Gate::before`'s
        // `role === Admin` bypass fires exactly as it would for the real
        // admin, because the swapped session's role genuinely IS Admin. This
        // is the exact mechanism App\Actions\Auth\StartImpersonation already
        // uses for "admin views the platform as a student" — read that
        // class's docblock before touching this block.
        //
        // `session(EmployeeSession::SESSION_KEY)` is the ONLY signal that a
        // swap happened; App\Http\Middleware\EnsureEmployeePermission reads
        // it to layer page-level gating ON TOP of the now-passing ownership
        // checks, and App\Http\Middleware\EnsureUserIsActive re-validates
        // THIS employee row (not just the owner's) on every request —
        // required because after the swap `$request->user()` is permanently
        // the owner, so the ordinary is_active/trashed() check can never
        // again observe the employee's own account being disabled/deleted.
        $employee = null;

        if ($user->role === UserRole::Employee) {
            $employee = Employee::query()->where('user_id', $user->getKey())->first();

            if ($employee === null || !$employee->is_active) {
                SecurityLog::warning('Login rejected for disabled employee account', [
                    'event' => 'login.employee_disabled',
                    'username' => $username,
                    'target_user_id' => $user->getKey(),
                    'ip' => $ip,
                ]);

                throw new UnauthorizedException('تم تعطيل حساب الموظف');
            }

            $ownerUser = $employee->owner;

            if ($ownerUser === null || $ownerUser->trashed() || !$ownerUser->is_active) {
                SecurityLog::warning('Login rejected: employee\'s owner is unavailable', [
                    'event' => 'login.employee_orphaned',
                    'username' => $username,
                    'target_user_id' => $user->getKey(),
                    'ip' => $ip,
                ]);

                throw new UnauthorizedException('الحساب التابع له غير متاح حالياً');
            }
        }

        // Establishes the session AND regenerates its id (SessionGuard::
        // updateSession -> Session::migrate(true)). For an employee this
        // authenticates the guard as the OWNER, never the employee.
        Auth::guard('web')->login($employee !== null ? $employee->owner : $user);

        if ($employee !== null) {
            $request->session()->put(EmployeeSession::SESSION_KEY, $user->getKey());
        }

        // Attributed to the EMPLOYEE's own id when this is an employee
        // login — the row that genuinely authenticated — exactly the same
        // argument StartImpersonation's docblock makes for why impersonation
        // never writes a login_sessions row for the STUDENT being viewed:
        // the audit trail must record who actually typed the password, not
        // who the guard ends up resolving to afterward.
        $loginSession = LoginSession::query()->create([
            'user_id' => $user->getKey(),
            'ip_address' => $ip,
            // The column is VARCHAR(255); a crafted 8 KB User-Agent would
            // otherwise fail the insert and turn a valid login into a 500.
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'status' => LoginSessionStatus::Active,
            'created_at' => now(),
            'last_activity_at' => now(),
        ]);

        $request->session()->put(self::SESSION_LOGIN_ROW, $loginSession->getKey());

        SecurityLog::info('Login succeeded', [
            'event' => 'login.success',
            'username' => $user->username,
            'target_user_id' => $user->getKey(),
            'target_role' => $user->role->value,
            'ip' => $ip,
        ]);

        return ['user' => $employee !== null ? $employee->owner : $user];
    }
}
