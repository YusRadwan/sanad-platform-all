<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ForbiddenException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates every `/center/*` INSTRUCTOR route behind `teachers.center_enabled`
 * ("زر تفعيل نظام السنتر", migration 2026_09_10_000002) — explicit admin
 * request: a teacher whose access is off must not reach any Center System
 * endpoint at all, not merely have its sidebar link hidden client-side.
 *
 * Always listed AFTER `auth`, `active` and `role:instructor` in the route
 * group — by the time this runs, `$request->user()` is a live, active
 * instructor and `->teacher` is guaranteed non-null (every instructor
 * account has a `teachers` row, enforced at creation — see
 * App\Actions\Teacher\CreateTeacher's single transaction).
 *
 * READS EXACTLY ONE COLUMN AND NOTHING ELSE. It does not touch, count, or
 * filter any `center_*` table — turning access off never deletes or hides a
 * single row of a teacher's existing data, only this gate.
 */
final class EnsureCenterEnabled
{
    public const DISABLED = 'نظام السنتر غير مفعّل لحسابك، يرجى التواصل مع الإدارة';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (!(bool) $user->teacher?->center_enabled) {
            throw new ForbiddenException(self::DISABLED);
        }

        return $next($request);
    }
}
