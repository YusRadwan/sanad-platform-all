<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * students — the student profile hanging off a `users` row.
 *
 * Like Teacher, this is NOT a BaseModel: the primary key IS `user_id`
 * (migration 000009), so the UUID-generating trait must not be applied.
 *
 * `phone` / `parent_phone` STORE THE VALUE EXACTLY AS SUBMITTED — international
 * from the signup wizard, local from the admin form. Only the derived USERNAME
 * is normalised (see App\Actions\Auth\NormalisePhoneToLocal). Do not "tidy" the
 * stored format: every existing row already uses this column's current meaning.
 */
class Student extends Model
{
    protected $table = 'students';

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'student_code',
        'phone',
        'parent_phone',
        'governorate',
        'gender',
        'educational_stage',
        'education_type',
        'grade_level',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The owning account.
     *
     * RETURNS null FOR A SOFT-DELETED STUDENT, for exactly the reason spelled
     * out in Teacher::user()'s docblock: DELETE /admin/students/{id} writes
     * only `users.deleted_at` and leaves this profile row intact, so the
     * relation stops resolving while the row keeps loading. Use
     * scopeWithLiveUser() on read paths rather than null-chaining at the
     * presenter.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Every enrollment row for this student, ACTIVE OR NOT.
     *
     * Unfiltered on purpose. The admin "تفاصيل كود الطالب" page shows the
     * student's whole subscription history including cancelled and expired
     * rows, so the relation must not pre-filter; callers that want only live
     * enrollments compose `->active()` — Subscription::scopeActive(), the one
     * canonical definition — onto it. Baking the filter in here would leave
     * that page with no way to ask the other question.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'student_id', 'user_id');
    }

    /**
     * Excludes students whose underlying account is soft-deleted. Twin of
     * Teacher::scopeWithLiveUser() — see that docblock for why whereHas() and
     * not a hand-written `deleted_at IS NULL`.
     */
    public function scopeWithLiveUser(Builder $query): Builder
    {
        return $query->whereHas('user');
    }

    /**
     * Students holding an ACTIVE, unexpired subscription to at least one
     * course owned by $instructor.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE `teacher_id` FILTER IS THE ENTIRE TENANT BOUNDARY OF             │
     * │ GET /enrollments/instructor-students.                                │
     * │                                                                      │
     * │ SubscriptionsManagement.tsx does NO client-side owner filtering — it │
     * │ states outright that "the API returns only instructor's students, so │
     * │ we can just use the state directly". If this scope over-returns, the │
     * │ UI renders another instructor's roster with no second line of        │
     * │ defence.                                                             │
     * │                                                                      │
     * │ NOTE THE SHAPE: the teacher condition and the "is subscribed"        │
     * │ condition are in ONE whereHas over `subscriptions`, not two. Split   │
     * │ across two whereHas calls they would be satisfied INDEPENDENTLY —    │
     * │ "has some subscription to this teacher" AND "has some active         │
     * │ subscription (to anyone)" — which admits a student whose enrollment  │
     * │ with THIS teacher has expired but who is active elsewhere. The       │
     * │ freeze table's sketch for row 34 shows the two-call form; this is    │
     * │ the corrected single-call version, and CrossTeacherIsolationTest     │
     * │ pins the difference.                                                 │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * "Is subscribed" is Subscription::scopeActive() and nothing else — the
     * single canonical definition. backend/ re-typed the predicate inline here
     * (`sub.status = 'active' AND (sub.expires_at IS NULL OR …)`), which is one
     * of the six copies Stage 3 exists to have ended.
     */
    public function scopeEnrolledWith(Builder $query, User $instructor): Builder
    {
        return $query->whereHas(
            'subscriptions',
            fn (Builder $q): Builder => $q
                ->active()
                ->whereHas(
                    'course',
                    fn (Builder $c): Builder => $c->where('teacher_id', $instructor->getKey())
                )
        );
    }

    /**
     * Center System — every teacher-center this student has joined, active or
     * not (unfiltered on purpose, same reasoning as subscriptions() above).
     */
    public function centerMemberships(): HasMany
    {
        return $this->hasMany(CenterMembership::class, 'student_id', 'user_id');
    }
}
