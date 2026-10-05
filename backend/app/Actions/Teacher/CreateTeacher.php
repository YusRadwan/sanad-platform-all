<?php

declare(strict_types=1);

namespace App\Actions\Teacher;

use App\Enums\UserRole;
use App\Models\Teacher;
use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * POST /api/v1/admin/teachers — creates the `users` row, the `teachers`
 * profile and (optionally) the `teacher_social_links` children as ONE unit.
 *
 * Port of backend/TeacherService::create().
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE TRANSACTION IS NOT DEFENSIVE PROGRAMMING — IT FIXES A REAL,          │
 * │ ALREADY-OBSERVED PRODUCTION BUG. DO NOT REMOVE IT.                       │
 * │                                                                          │
 * │ Before backend/ wrapped this pair of writes, a failure on the `teachers` │
 * │ insert left a `users` row with role='instructor' AND NO PROFILE. The     │
 * │ resulting account was in a state nothing could resolve:                  │
 * │   · it could log in (the users row is complete and valid);               │
 * │   · it PASSED the instructor role gate (RoleMiddleware reads users.role);│
 * │   · every instructor endpoint then 404'd with "Teacher not found",       │
 * │     because TeacherService::find() looks in `teachers`.                  │
 * │ An admin could neither use the account nor recreate it — the username    │
 * │ was taken by the orphan.                                                 │
 * │                                                                          │
 * │ DB::transaction() is the native form of backend/'s manual               │
 * │ inTransaction()/beginTransaction()/rollBack() dance, and it NESTS        │
 * │ correctly via savepoints if a future caller wraps this action — which is │
 * │ exactly what the legacy `$ownsTransaction` flag was hand-rolling.        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * SOCIAL LINKS ARE VALIDATED **INSIDE** THE TRANSACTION, on purpose. An unsafe
 * url raises a ValidationException from NormaliseSocialLinks, which rolls the
 * whole thing back — so a create carrying one hostile link produces 422 and NO
 * account, rather than an account whose links quietly went missing.
 *
 * `password_hash` IS WRITTEN WITH forceFill: it is deliberately absent from
 * User::$fillable so it can never be mass-assigned from request input. Being
 * explicit here is that guard working, not an obstacle to route around.
 */
final class CreateTeacher
{
    public function __construct(
        private readonly NormaliseSocialLinks $normaliseSocialLinks,
        private readonly ReplaceSocialLinks $replaceSocialLinks,
    ) {
    }

    /**
     * @param array<string, mixed> $data Validated input from StoreTeacherRequest.
     */
    public function __invoke(array $data): Teacher
    {
        $username = (string) $data['username'];

        // Resolved BEFORE the transaction: it is pure string work, and a
        // ValidationException from the link normaliser inside the transaction
        // should be the only thing that can abort it.
        $email = $this->resolveEmail($data, $username);

        try {
            $teacher = DB::transaction(function () use ($data, $username, $email): Teacher {
                $user = new User();

                $user->forceFill([
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => Hash::make((string) $data['password']),
                    'full_name' => (string) $data['fullName'],
                    'role' => UserRole::Instructor,
                    'is_active' => true,
                    // The card photo lives on the USERS row. AddTeacher.tsx
                    // sends it as `avatarUrl` at the top level, not inside the
                    // profile fields.
                    'avatar_url' => $data['avatarUrl'] ?? null,
                ])->save();

                $teacher = Teacher::query()->create([
                    'user_id' => $user->getKey(),
                    'phone' => $data['phone'] ?? null,
                    'specialization' => $data['specialization'] ?? null,
                    'bio' => $data['bio'] ?? null,
                    // `?? []`, not `?? null`: the columns are JSON and the
                    // frontend maps over them unconditionally.
                    'subjects' => $data['subjects'] ?? [],
                    'grades' => $data['grades'] ?? [],
                    'banner_url' => $data['bannerUrl'] ?? null,
                    'profile_photo_url' => $data['profilePhotoUrl'] ?? null,
                    'whatsapp_support_numbers' => $data['whatsappNumbers'] ?? [],
                    // Migration 000037. `?? false` is the column default
                    // restated, so the ordinary create path is untouched; the
                    // key is honoured when an admin provisions an account that
                    // must not be signed into yet. (bool) because the `boolean`
                    // validation rule accepts "1"/"0" as well as true/false.
                    // This blocks LOGIN only — a teacher created with it set is
                    // as publicly visible as any other.
                    'login_blocked' => (bool) ($data['loginBlocked'] ?? false),
                    // "زر تفعيل نظام السنتر" — absent = false. A brand-new
                    // teacher gets NO Center System access until the admin
                    // explicitly ticks the box, the opposite of this
                    // column's own schema default (true), which exists only
                    // to protect already-live Center System teachers from
                    // migration 2026_09_10_000002 itself.
                    'center_enabled' => (bool) ($data['centerEnabled'] ?? false),
                ]);

                // array_key_exists, not !empty: sending an EMPTY array is a
                // meaningful instruction (create with no links) and must not
                // be confused with omitting the key.
                if (array_key_exists('socialLinks', $data)) {
                    ($this->replaceSocialLinks)(
                        $teacher,
                        ($this->normaliseSocialLinks)($data['socialLinks'])
                    );
                }

                return $teacher;
            });
        } catch (Throwable $e) {
            SecurityLog::error('Teacher creation failed and was rolled back', [
                'event' => 'teacher.create_failure',
                'username' => $username,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        SecurityLog::warning('Teacher account created', [
            'event' => 'teacher.created',
            'target_user_id' => $teacher->getKey(),
            'username' => $username,
        ]);

        return $teacher;
    }

    /**
     * The deterministic derived address `username@sanad.edu` when no usable
     * email was sent.
     *
     * NOT mock data, despite how it looks. The admin form has NO email field,
     * so the client always computes this exact address and posts it; applying
     * the same default server-side means both sides agree on one real, stable
     * address per teacher whether or not the field made it into the payload.
     *
     * The `!== ''` half matters: AddTeacher.tsx sends `email: ''` rather than
     * omitting the key when its (hidden) field is blank, and an empty string
     * would otherwise be written as the login address.
     */
    private function resolveEmail(array $data, string $username): string
    {
        $email = $data['email'] ?? null;

        return is_string($email) && trim($email) !== ''
            ? trim($email)
            : $username . '@sanad.edu';
    }
}
