<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * users.role — the single source of truth for role vocabulary across the whole
 * stack. Matches frontend/src/types/user.ts ('student'|'instructor'|'admin')
 * exactly — `Employee` is the one deliberate exception, see its own docblock.
 *
 * There is NO alias vocabulary. backend/'s own migration comment records that
 * 'teacher' and 'staff' were once documented as loose aliases that "map to"
 * instructor/admin — that was never true, no mapping layer ever existed, and
 * the frontend checks written against those strings were dead code. Do not
 * reintroduce them.
 */
enum UserRole: string
{
    use HasValues;

    case Student = 'student';
    case Instructor = 'instructor';
    case Admin = 'admin';

    /**
     * "إدارة الأدمنز" (decision 10.37) — a teacher-scoped Employee/Amin
     * sub-account. `$request->user()->role` NEVER holds this value in an
     * ordinary authenticated request: App\Actions\Auth\LoginUser detects it
     * ONLY at the login instant (against the employee's own `users` row,
     * before any session exists), then authenticates the session AS the
     * OWNING TEACHER's row instead (App\Support\EmployeeSession, mirroring
     * App\Support\Impersonation's identity-swap exactly) so every existing
     * `teacher_id === $user->id` ownership check in the codebase keeps
     * working unmodified. This role exists purely so the employee's own row
     * is distinguishable from an ordinary instructor at that one moment —
     * treat any `role === Employee` observed AFTER login as a bug.
     */
    case Employee = 'employee';
}
