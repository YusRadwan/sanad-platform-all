<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * question_groups — a teacher's reusable question-bank folder.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ GRADUATED FROM App\Models\Concerns\PlaceholderModel IN STAGE 6 / A.      ║
 * ║ The same three-part hand-off Section, CourseGroup and ContentItem went   ║
 * ║ through: the marker is dropped here, the matching expectations leave     ║
 * ║ tests/Unit/Authorization/PolicySkeletonTest.php, and                     ║
 * ║ App\Policies\QuestionGroupPolicy::manage() gets its real body. All three ║
 * ║ move together or none of them do.                                        ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Columns (migration 2026_01_01_000019): id, teacher_id, title, grade,
 * created_at. There is NO updated_at, so `$timestamps = false`; `created_at` is
 * filled by the column's own DEFAULT CURRENT_TIMESTAMP, exactly as backend/'s
 * `INSERT … NOW()` did.
 *
 * ┌── THIS IS THE ONE OWNERSHIP CHAIN IN THE APPLICATION WITH NO COURSE. ────┐
 * │ `question_groups.teacher_id` is a DIRECT FK to `teachers.user_id`. A     │
 * │ question group is not attached to a course, a section or a content item; │
 * │ it is a teacher's private bank that any number of their exams may point  │
 * │ at. That is why App\Policies\QuestionGroupPolicy does NOT use            │
 * │ App\Policies\Concerns\ResolvesCourseOwnership — there is nothing for the │
 * │ trait to resolve — and why the tenant boundary here is one string        │
 * │ comparison rather than a join.                                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── `teacher_id` IS **DELIBERATELY ABSENT** FROM $fillable. ───────────────┐
 * │ It is the tenant column. If it were mass-assignable, a single            │
 * │ `QuestionGroup::create($request->validated())` in any future stage would │
 * │ let a caller file a group under ANOTHER teacher's bank — and then read   │
 * │ it back through their own listing, because the list scope trusts exactly │
 * │ this column. The owner is set once, from the SESSION, in                 │
 * │ App\Http\Controllers\QuestionBankController::storeGroup().               │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
class QuestionGroup extends BaseModel
{
    protected $table = 'question_groups';

    public $timestamps = false;

    /** See the banner: `teacher_id` is set from the session, never filled. */
    protected $fillable = [
        'title',
        'grade',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * The owning instructor's USER row.
     *
     * `teacher_id` -> `teachers.user_id`, and that column IS `users.id` (see
     * App\Models\Course's docblock for the same identity). Pointing this
     * relation at User rather than Teacher keeps the comparison in
     * QuestionGroupPolicy::manage() — `$group->teacher_id === $user->id` — a
     * single hop with nothing to dereference.
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id', 'id');
    }

    /**
     * The bank's questions.
     *
     * ORDERED BY `id`, matching backend/QuestionRepository::listByGroup()'s
     * `ORDER BY id ASC`. `questions` has no order_index and no created_at, so
     * the UUIDv7 primary key — which is time-ordered — is the only stable
     * ordering available, and it is the one the legacy list already used.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'question_group_id', 'id')->orderBy('id');
    }

    /**
     * THE LIST SCOPE FOR `GET /question-groups` — freeze-table row 80.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ ADMINS ARE NOT HANDLED HERE, exactly as CourseGroup::scopeOwnedBy()  │
     * │ argues: a scope that silently returned EVERYTHING for one role would │
     * │ be a privilege rule hiding inside a query builder, where nobody      │
     * │ auditing authorization would look for it. The admin branch lives in  │
     * │ QuestionBankController::indexGroups(), visibly, next to the scope    │
     * │ call it replaces.                                                     │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function scopeOwnedBy(Builder $query, User $instructor): Builder
    {
        return $query->where('teacher_id', $instructor->getKey());
    }
}
