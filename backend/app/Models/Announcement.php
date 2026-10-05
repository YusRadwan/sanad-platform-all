<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * announcements — a teacher's (or the platform's) notice board entry. STAGE 8.
 *
 * Columns per migration 2026_01_01_000030: id, course_id (NULLABLE),
 * teacher_id (NULLABLE), title, body (the message text — presented as
 * `content`), link_url, start_date, end_date, is_published, created_at.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `teacher_id IS NULL` IS A **PLATFORM ANNOUNCEMENT**, DECIDED, NOT A BUG. ║
 * ║ (Approved decision 3.2; recorded as open decision 8.3.)                  ║
 * ║                                                                          ║
 * ║ backend/AnnouncementService::create() writes `teacher_id => $userId`     ║
 * ║ UNCONDITIONALLY. `announcements.teacher_id` is a foreign key into        ║
 * ║ `teachers.user_id`, and an ADMIN has no `teachers` row — so an admin     ║
 * ║ creating an announcement in backend/ today produces a raw MySQL foreign  ║
 * ║ key violation and a **500**. That is a live bug, not a contract.         ║
 * ║                                                                          ║
 * ║ backend-v2 writes NULL for an admin author instead. The row is then      ║
 * ║ owned by nobody, and AnnouncementPolicy::manage() says so EXPLICITLY —   ║
 * ║ `false` for every non-admin, with admins already exempted upstream by    ║
 * ║ Gate::before. backend/'s assertOwns() reached the same answer by         ║
 * ║ accident (`null !== $userId` is true, so it 403'd), which is fail-closed ║
 * ║ but undocumented and one refactor away from becoming fail-open.          ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * `course_id` is a SEPARATE axis: it scopes the AUDIENCE, never the owner. A
 * course-scoped announcement is still owned via `teacher_id`, which is why
 * AnnouncementPolicy does not use ResolvesCourseOwnership — the same shape as
 * QuestionGroupPolicy. Course ownership, when a course_id is supplied on
 * create, is a DISTINCT check performed against CoursePolicy.
 */
class Announcement extends BaseModel
{
    protected $table = 'announcements';

    /**
     * `announcements` has created_at and no updated_at (migration 30) — the
     * same shape as `notifications`, and for the same reason UPDATED_AT is
     * used rather than `$timestamps = false`. See App\Models\Notification.
     */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** Nullable: a global announcement is not tied to one course. */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'id');
    }

    /**
     * The author's TEACHER PROFILE, not their user row: the FK points at
     * `teachers.user_id`, so the local and foreign keys are both the user id
     * while the related model is Teacher. Same spelling as Teacher::courses().
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id', 'user_id');
    }
}
