<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\EmployeeAccountType;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\EmployeeSession;
use App\Support\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The session-user shape consumed by frontend/src/repositories/authRepository
 * (`sessionStorage.user`) and by every `getInstructorInfo()` helper under
 * frontend/src/components/Instructor/.
 *
 * Port of backend/app/Services/AuthService::publicUser(). It is the ONLY place
 * this shape is built, so /auth/login and /auth/me can never drift apart.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ `full_name` AND `fullName` ARE BOTH PRESENT ON PURPOSE.                  │
 * │                                                                          │
 * │ This is NOT accidental cruft and must NOT be "cleaned up". The Instructor│
 * │ components read the camelCase spelling; without it they silently fall    │
 * │ back to showing the raw username where the teacher's name belongs. That  │
 * │ was a real, already-fixed bug in backend/. Removing the duplicate key    │
 * │ reintroduces it, and nothing in the test suite of a LATER stage would    │
 * │ catch it — the frontend degrades quietly rather than erroring.           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `grades`/`subjects` are attached ONLY for the `instructor` role, read from
 * the teacher's own row. They exist here because CourseService enforces
 * server-side that a course's grade/subject must come from the instructor's
 * assigned list; with an empty client-side list an instructor could not create
 * a course at all. Other roles keep the exact previous shape — the two keys are
 * ABSENT, not null.
 *
 * `created_at` IS EMITTED AS 'Y-m-d H:i:s', NOT ISO-8601.
 * The legacy payload shipped the raw MySQL DATETIME string. The User model
 * casts the column to a Carbon instance (Stage 0), whose default JSON form is
 * ISO-8601 with microseconds — a different string for the same instant. The
 * legacy format is reproduced explicitly so a consumer doing a literal
 * comparison, or a `new Date()` on a browser that is fussy about formats, sees
 * exactly what it saw before. `deleted_at` is deliberately NOT exposed.
 *
 * @mixin User
 */
final class PublicUser extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $role = $user->getAttribute('role');
        $roleValue = $role instanceof UserRole ? $role->value : (string) $role;

        $payload = [
            'id' => $user->getKey(),
            'username' => $user->getAttribute('username'),
            'email' => $user->getAttribute('email'),
            'full_name' => $user->getAttribute('full_name'),
            // Mirrors full_name — see the class docblock. DO NOT REMOVE.
            'fullName' => $user->getAttribute('full_name'),
            'role' => $roleValue,
            // TINYINT(1) arrives as int 1/0; the frontend's TypeScript declares
            // `is_active: boolean`. The model cast does the work, this is just
            // where it surfaces.
            'is_active' => (bool) $user->getAttribute('is_active'),
            'avatar_url' => $user->getAttribute('avatar_url'),
            'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
            // NEW (open decision 10.18) — true while an admin is viewing the
            // platform AS this user (Impersonation::SESSION_KEY present).
            // SignupPage/LoginPage never see this true; it only ever appears
            // on a student session an admin swapped into. The frontend uses
            // it to render the persistent "أنت تشاهد كطالب — رجوع للأدمن" bar.
            'impersonating' => $request->session()->has(Impersonation::SESSION_KEY),
        ];

        if ($roleValue === UserRole::Instructor->value) {
            $teacher = $user->teacher;

            // `?? []` on BOTH keys: the columns are nullable JSON, and an
            // instructor row can legitimately not exist yet. The frontend maps
            // over these, so null would throw where an empty list is harmless.
            $payload['grades'] = $teacher?->grades ?? [];
            $payload['subjects'] = $teacher?->subjects ?? [];
            // "زر تفعيل نظام السنتر" — the ONLY reason this session-scoped
            // payload carries it: InstructorSystem.tsx reads it to hide the
            // whole "نظام السنتر" sidebar section for a teacher whose access
            // is off. The actual enforcement is server-side
            // (App\Http\Middleware\EnsureCenterEnabled) — this is UI
            // convenience only, never the security boundary itself.
            $payload['centerEnabled'] = (bool) ($teacher?->center_enabled ?? false);
        }

        // "إدارة الأدمنز" (decisions 10.37 + 10.40) — UI CONVENIENCE ONLY,
        // same caveat as `centerEnabled` above: the real gate is server-side
        // (App\Http\Middleware\EnsureEmployeePermission). DELIBERATELY NOT
        // inside the Instructor branch above: after App\Actions\Auth\
        // LoginUser's identity swap, `$roleValue` here is genuinely `Admin`
        // for an admin-owned employee session (decision 10.40 — an
        // admin-owned Amin inherits real Super Admin authority, so its
        // session role must stay Admin, not be forced to Instructor) — an
        // Instructor-only guard would silently drop these two keys for
        // every admin-owned employee. Every field ABOVE in the Instructor
        // branch (grades/subjects/centerEnabled, and `full_name` at the top
        // of this resource) reflects the OWNER, which is correct for
        // authorization but would otherwise leave the frontend with no way
        // to know "an employee is actually signed in" or render their own
        // name — these two keys are that signal, for either owner kind.
        $actingEmployee = EmployeeSession::actingEmployee($request);

        $payload['actingEmployee'] = $actingEmployee !== null ? [
            'id' => (string) $actingEmployee->getAttribute('user_id'),
            'name' => $actingEmployee->user?->full_name,
        ] : null;

        // `'all'` for an Amin (never the literal permission list — see
        // EmployeePermissions::grants()'s own rule), a string array for
        // a limited Employee, `null` for an ordinary teacher/admin session.
        $payload['employeePermissions'] = match (true) {
            $actingEmployee === null => null,
            $actingEmployee->account_type === EmployeeAccountType::Amin => 'all',
            default => $actingEmployee->permissions ?? [],
        };

        // Center System (decision 10.24) — the student's own QR code (drawn
        // on the "طالب سنتر" page) encodes exactly this value, and this is
        // the ONLY session-scoped read of it: `student_code` was previously
        // emitted only once, at POST /auth/register, with no student-facing
        // read route afterward. Same per-role-key pattern as grades/subjects
        // above — absent for every other role, not null.
        if ($roleValue === UserRole::Student->value) {
            $payload['student_code'] = $user->student?->student_code;
        }

        return $payload;
    }
}
