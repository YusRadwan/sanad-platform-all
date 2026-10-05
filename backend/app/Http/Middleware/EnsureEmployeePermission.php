<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ForbiddenException;
use App\Support\EmployeeSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "إدارة الأدمنز" (decision 10.37) — page-level permission gate, aliased
 * `employee.permission`, used as `employee.permission:<key>` (keys live in
 * App\Support\EmployeePermissions::CATALOG).
 *
 * ┌── ADDITIVE, NEVER A REPLACEMENT FOR OWNERSHIP CHECKS. ────────────────────┐
 * │ For an ORDINARY teacher/admin session (no employee swap took place) this │
 * │ is ALWAYS a no-op pass-through — `EmployeeSession::hasPermission()`      │
 * │ returns true unconditionally when there is no acting employee. It only    │
 * │ restricts anything once App\Actions\Auth\LoginUser's identity swap has    │
 * │ put an `acting_employee_id` in the session — i.e. it is a NARROWING      │
 * │ layer sitting on top of every ownership check the route already had      │
 * │ (`role:instructor`, `manage` policies, `scopeOwnedBy`, …), never a       │
 * │ substitute for any of them. This is exactly why it needs no `role:`      │
 * │ check of its own and no re-derivation of "does this teacher own this     │
 * │ resource" — that question is already answered correctly by the time this │
 * │ runs, because the swap made `$request->user()` the real owning teacher.  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Always listed AFTER `role:instructor` (and after `center.enabled` where
 * both apply), matching that middleware's own documented convention — by the
 * time this runs, `$request->user()` is a live, active instructor (real or
 * swapped-to).
 */
final class EnsureEmployeePermission
{
    public function handle(Request $request, Closure $next, string $key): Response
    {
        if (!EmployeeSession::hasPermission($request, $key)) {
            throw new ForbiddenException('لا تملك صلاحية الوصول إلى هذه الصفحة');
        }

        return $next($request);
    }
}
