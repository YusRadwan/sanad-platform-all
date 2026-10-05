<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * course_groups — the SECOND organisational layer of a course, alongside
 * `sections`. backend/'s GroupService/GroupRepository are this table.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ GRADUATED FROM App\Models\Concerns\PlaceholderModel IN STAGE 5 / A.      ║
 * ║ See App\Models\Section's banner — the same three-part hand-off applies.  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Named CourseGroup, not Group: `Group` is far too generic in an application
 * that also has question groups (App\Models\QuestionGroup) — and those two are
 * owned through completely different columns (`courses.teacher_id` via a join
 * here, `question_groups.teacher_id` directly there), which is precisely the
 * confusion a generic name invites.
 *
 * Columns (migration 2026_01_01_000012): id, course_id, title, is_hidden,
 * order_index. No timestamps.
 *
 * `start_at`/`end_at` (migration 2026_08_24_065335, open decision 10.21) are
 * the REAL columns behind GroupManagement.tsx's visibility-scheduling form —
 * they used to be posted into a request body that silently discarded them.
 * NULL is a permanent state, not "pending": NULL `start_at` means "no start
 * gate", NULL `end_at` means "no end gate". See isCurrentlyVisible().
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ DELETING A GROUP DELETES ITS CONTENT ITEMS. THAT IS THE SCHEMA'S RULE,  │
 * │ NOT AN APPLICATION CHOICE, AND IT MUST NOT BE "SOFTENED".               │
 * │                                                                          │
 * │ fk_content_items_group is ON DELETE **CASCADE** (migration 000014).      │
 * │ Its predecessor in backend/ was ON DELETE SET NULL and migration 0015    │
 * │ had to drop and recreate it, because `content_items` also carries        │
 * │   CHECK ((section_id IS NULL) <> (group_id IS NULL))                     │
 * │ and SET NULL therefore made group deletion IMPOSSIBLE: MySQL nulls       │
 * │ group_id on a row whose section_id is already NULL, violates the CHECK,  │
 * │ and aborts the whole DELETE. Stage 0 ported the FIXED version — verified │
 * │ against the migration, not assumed — and                                 │
 * │ CourseGroupCrudTest::test_the_group_foreign_key_cascades_as_stage_0_     │
 * │ configured_it pins it.                                                   │
 * │                                                                          │
 * │ App\Models\ContentItem is Session B's; nothing here declares that edge.  │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
class CourseGroup extends BaseModel
{
    protected $table = 'course_groups';

    public $timestamps = false;

    protected $fillable = [
        'course_id',
        'title',
        'is_hidden',
        'order_index',
        'start_at',
        'end_at',
    ];

    /**
     * `is_hidden` is TINYINT(1); MySQL hands it back as PHP int 1|0 (proven in
     * tests/Feature/PdoTypeCastingTest.php) and the frontend's groupApiService
     * declares it `boolean`. The cast is the contract, not a nicety.
     */
    protected function casts(): array
    {
        return [
            'is_hidden' => 'boolean',
            'order_index' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    /** The owning course. Null for a soft-deleted one — see Section::course(). */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'id');
    }

    /**
     * The group's content items — the group-owned twin of
     * Section::contentItems(). Same ordering convention, same reasoning: two
     * items CAN share an order_index (the racy MAX()+1 assignment), so the
     * `id` tie-break keeps the public outline from shuffling between requests.
     */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'group_id', 'id')
            ->orderBy('order_index')
            ->orderBy('id');
    }

    /**
     * Whether a student (or the general public) may see this group RIGHT NOW.
     *
     * THREE INDEPENDENT gates, all must pass:
     *   - `is_hidden` is false (the manual switch, unchanged behaviour)
     *   - `start_at` is NULL, or has already passed
     *   - `end_at` is NULL, or has not yet passed
     *
     * An owner/admin bypasses this entirely (same as maySeeHidden() does for
     * hidden content items) — this method is only consulted for everyone
     * else, in CourseController::structure().
     */
    public function isCurrentlyVisible(): bool
    {
        if ($this->getAttribute('is_hidden')) {
            return false;
        }

        $startAt = $this->getAttribute('start_at');
        if ($startAt !== null && $startAt->isFuture()) {
            return false;
        }

        $endAt = $this->getAttribute('end_at');
        if ($endAt !== null && $endAt->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * THE LIST SCOPE FOR `GET /groups` — freeze-table row 74, and the exact
     * shape that document pinned in advance:
     *
     *     whereHas('course', fn ($q) => $q->where('teacher_id', $user->id))
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ SOFT-DELETED COURSES DROP OUT FOR FREE, AND THAT MATTERS.            │
     * │ backend/GroupRepository::listByTeacher() hand-wrote                  │
     * │ `AND c.deleted_at IS NULL` inside its JOIN. Here the predicate runs  │
     * │ against the Course MODEL, so SoftDeletes' global scope supplies it   │
     * │ inside the subquery — there is no clause left to forget.             │
     * │                                                                      │
     * │ ADMINS ARE NOT HANDLED HERE. `GET /groups` branches admin/instructor │
     * │ in the controller (an admin sees every group, unscoped), because a   │
     * │ scope that silently returned "everything" for one role would be a    │
     * │ security rule hiding inside a query builder.                         │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function scopeOwnedBy(Builder $query, User $instructor): Builder
    {
        return $query->whereHas(
            'course',
            fn (Builder $q): Builder => $q->where('teacher_id', $instructor->getKey())
        );
    }
}
