<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The PUBLIC instructor-directory shape — GET /api/v1/teachers, no auth.
 *
 * Port of backend/TeacherService::presentPublic(). Consumed by
 * teacherService.getAllTeachers() (which reads `data.teachers`),
 * CourseDetailsPage.tsx (matches on `t.full_name || t.name`, then reads
 * `found.id` / `found.avatar_url`) and useTeachers.ts. The dual naming below
 * is what lets all three read the same payload without a frontend change.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THIS RESOURCE IS **NOT** WHAT KEEPS email/phone OUT OF THE RESPONSE.     │
 * │                                                                          │
 * │ Teacher::scopePubliclyVisible() is. It SELECTs an explicit column list   │
 * │ that contains neither, so the models this resource receives do not carry │
 * │ the attributes at all — there is nothing here to leak, and nothing a     │
 * │ future edit to this file could expose. Read that scope's docblock before │
 * │ changing either.                                                         │
 * │                                                                          │
 * │ The corollary is a real constraint on THIS class: it must not reference  │
 * │ any column outside that SELECT list. Doing so would raise a "property    │
 * │ does not exist" on a hydrated-but-partial model, or — worse, if someone  │
 * │ "fixed" it by widening the SELECT — quietly re-introduce the leak.       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * The joined `users` columns (full_name, avatar_url, username) arrive as plain
 * attributes on the Teacher model because the scope selects them into the same
 * result row. That is why there is no ->user dereference here, and why this
 * resource needs no relation loaded.
 *
 * @mixin Teacher
 */
final class PublicTeacher extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Teacher $teacher */
        $teacher = $this->resource;

        $avatar = $teacher->getAttribute('avatar_url');
        $banner = $teacher->getAttribute('banner_url');
        $photo = $teacher->getAttribute('profile_photo_url');
        $name = $teacher->getAttribute('full_name');

        return [
            // `id` and `teacher_id` are the same value under two names —
            // CourseDetailsPage reads `found.id`, useTeachers reads
            // `teacher_id`. Both are the teachers.user_id primary key.
            'id' => $teacher->getKey(),
            'teacher_id' => $teacher->getKey(),
            'username' => $teacher->getAttribute('username'),
            'name' => $name,
            'full_name' => $name,
            'avatar_url' => $avatar,
            'avatarUrl' => $avatar,
            'banner_url' => $banner,
            'bannerUrl' => $banner,
            // The THIRD teacher image, rendered by TeacherProfilePage.tsx's
            // "نبذة عن المدرس" section — not the avatar, not the hero cover.
            'profile_photo_url' => $photo,
            'profilePhotoUrl' => $photo,
            'socialLinks' => SocialLink::collection(
                $teacher->getRelationValue('socialLinks') ?? collect()
            ),
            'specialization' => $teacher->getAttribute('specialization'),
            'bio' => $teacher->getAttribute('bio'),
            'subjects' => $teacher->getAttribute('subjects') ?? [],
            'grades' => $teacher->getAttribute('grades') ?? [],
            // Per-teacher WhatsApp support numbers (e.g. "مسؤول المادة:
            // 01012345678"), free-text label+number strings, not structured
            // fields — shown as a support icon on TeacherProfilePage.tsx.
            'whatsappNumbers' => $teacher->getAttribute('whatsapp_support_numbers') ?? [],
            'is_verified' => (bool) $teacher->getAttribute('is_verified'),
        ];
    }
}
