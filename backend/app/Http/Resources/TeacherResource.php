<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The ADMIN-facing teacher shape — GET/POST/PATCH /admin/teachers[/{id}].
 *
 * Port of backend/TeacherService::present(). Consumed by
 * Admin/ManageTeachers.tsx (the table) and by Admin/AddTeacher.tsx, which
 * receives a row of that table as `location.state.editTeacher` and rebuilds its
 * whole form from it — including the social-link toggles. That hand-off is why
 * `socialLinks` must be present on the LISTING and not only on the single read.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE DUPLICATED KEYS ARE A CONTRACT, NOT CRUFT. DO NOT "CLEAN UP".       │
 * │                                                                          │
 * │ `profilePhotoUrl` AND `profile_photo_url` are both emitted because       │
 * │ different frontend components read different spellings — the same        │
 * │ situation PublicUser documents for `full_name`/`fullName`. Removing      │
 * │ either one degrades the UI SILENTLY (a missing photo, not an error), so  │
 * │ no test in a later stage would catch the regression.                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ `isActive` COMES FROM **users**.is_active, NOT teachers.is_active.       │
 * │                                                                          │
 * │ backend/present() reads `$row['user_active'] ?? $row['is_active'] ??      │
 * │ true` — the users column first, aliased as user_active by its join, with │
 * │ the teachers column only as a fallback for callers that did not join.    │
 * │ Here the relation is loaded, so the users column is always available and │
 * │ the fallback chain collapses to one source. That matters: suspension is  │
 * │ written to users.is_active by every path in this application (it is what │
 * │ EnsureUserIsActive reads), so presenting the teachers-table twin would   │
 * │ show an admin "active" for an account that cannot log in.                │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * THE `user` RELATION IS DEREFERENCED WITHOUT `?->` ON PURPOSE. Every read
 * path that builds this resource has already filtered through
 * Teacher::scopeWithLiveUser(), so a null here is a BUG, not a state — and a
 * null-safe chain would hide it behind a well-formed response describing a
 * deleted teacher. See Teacher::user()'s docblock.
 *
 * @mixin Teacher
 */
final class TeacherResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Teacher $teacher */
        $teacher = $this->resource;

        /** @var User $user */
        $user = $teacher->getRelationValue('user');

        return [
            'teacher_id' => $teacher->getKey(),
            'username' => $user->getAttribute('username'),
            'name' => $user->getAttribute('full_name'),
            'email' => $user->getAttribute('email'),
            'phone' => $teacher->getAttribute('phone'),
            'bio' => $teacher->getAttribute('bio'),
            'specialization' => $teacher->getAttribute('specialization'),
            // `?? []`: the columns are nullable JSON and the frontend maps over
            // both, so null would throw where an empty list is harmless.
            'subjects' => $teacher->getAttribute('subjects') ?? [],
            'grades' => $teacher->getAttribute('grades') ?? [],
            // Feeds AddTeacher.tsx's edit hand-off — `editTeacher.whatsappNumbers`
            // (see the file's own docblock on that hand-off pattern).
            'whatsappNumbers' => $teacher->getAttribute('whatsapp_support_numbers') ?? [],
            // The card photo lives on the USERS row, not the teachers row.
            // Omitting it once made ManageTeachers.tsx render a placeholder for
            // teachers who do have a photo, and its edit hand-off then POSTed
            // avatarUrl:'' back — a silent wipe.
            'avatarUrl' => $user->getAttribute('avatar_url'),
            'bannerUrl' => $teacher->getAttribute('banner_url'),
            'profilePhotoUrl' => $teacher->getAttribute('profile_photo_url'),
            'profile_photo_url' => $teacher->getAttribute('profile_photo_url'),
            'socialLinks' => SocialLink::collection(
                $teacher->getRelationValue('socialLinks') ?? collect()
            ),
            'is_verified' => (bool) $teacher->getAttribute('is_verified'),
            'isActive' => (bool) $user->getAttribute('is_active'),
            // teachers.login_blocked (migration 000037) — the SECOND, separate
            // switch: true means this instructor cannot SIGN IN, and means
            // nothing at all about what the site shows. It is emitted HERE, on
            // the admin-only resource, and deliberately NOT on
            // App\Http\Resources\PublicTeacher: an unauthenticated visitor has
            // no business learning that an account is locked. ManageTeachers.tsx
            // reads it to label its منع/السماح بتسجيل الدخول button.
            'loginBlocked' => (bool) $teacher->getAttribute('login_blocked'),
            // "زر تفعيل نظام السنتر" — AddTeacher.tsx reads it to check/
            // uncheck the toggle in both its add and edit modes.
            'centerEnabled' => (bool) $teacher->getAttribute('center_enabled'),
            // 'Y-m-d H:i:s', not ISO-8601: the legacy payload shipped the raw
            // MySQL DATETIME string and consumers compare it literally. Same
            // decision, same reason, as PublicUser::created_at.
            'created_at' => $teacher->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
