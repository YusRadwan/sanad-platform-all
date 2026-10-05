<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * subscriptions — a student's enrollment in one course.
 *
 * `student_id` points at `students.user_id` (migration 2026_01_01_000025), the
 * same profile-key shape as Course::$teacher_id. UNIQUE(student_id, course_id)
 * is the authority on "one enrollment per pair"; nothing here re-checks it.
 *
 * TIMESTAMPS ARE OFF: the table has `created_at` and NO `updated_at`, so
 * Eloquent's automatic management would try to UPDATE a column that does not
 * exist. Same treatment as LoginSession.
 */
class Subscription extends BaseModel
{
    protected $table = 'subscriptions';

    public $timestamps = false;

    protected $fillable = [
        'student_id',
        'course_id',
        'status',
        'expires_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THIS SCOPE IS THE **SINGLE CANONICAL DEFINITION** OF "SUBSCRIBED"    │
     * │ IN backend-v2. NOTHING ELSE, ANYWHERE, MAY RE-EXPRESS IT INLINE.    │
     * │                                                                      │
     * │ A subscription is ACTIVE when:                                       │
     * │     status = 'active'                                                │
     * │ AND (expires_at IS NULL   OR   expires_at > now())                   │
     * │                                                                      │
     * │ NULL means "no expiry", i.e. a permanent enrollment — it is NOT      │
     * │ "expired". Getting that backwards locks every lifetime student out   │
     * │ of every course, silently.                                           │
     * │                                                                      │
     * │ WHY THIS IS A SCOPE AND NOT A COPY-PASTEABLE WHERE-CLAUSE:           │
     * │ backend/ writes this exact predicate SIX separate times —            │
     * │   SubscriptionRepository::hasActiveSubscription()                    │
     * │   SubscriptionRepository::listActiveForStudent()                     │
     * │   CourseRepository's enrolled-count query                            │
     * │   the revenue/analytics aggregate                                    │
     * │   the enrollment "already subscribed?" pre-check                     │
     * │   the student dashboard listing                                      │
     * │ — six chances for one of them to drift and either leak content to an │
     * │ expired student or lock out a paying one. Ending that duplication is │
     * │ a stated goal of Stage 3. If you find yourself typing               │
     * │ `where('status', 'active')` next to an `expires_at` comparison       │
     * │ anywhere in this codebase, you are re-introducing the bug: call      │
     * │ ->active() instead.                                                  │
     * │                                                                      │
     * │ THE `orWhere` IS WRAPPED IN A CLOSURE ON PURPOSE. Without the nested │
     * │ group, SQL operator precedence turns the predicate into              │
     * │   (status='active' AND expires_at IS NULL) OR expires_at > now()     │
     * │ which grants access to every CANCELLED subscription that happens to  │
     * │ carry a future expiry date. The parentheses are load-bearing         │
     * │ security, not formatting.                                            │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * NOTE the deliberate asymmetry with App\Enums\SubscriptionStatus's
     * docblock: 'expired' is a STORED state that some later job writes, but
     * this scope does NOT trust it alone — a row still marked 'active' whose
     * `expires_at` has passed is treated as inactive here and now. Access
     * control must not depend on a background job having already run.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Active)
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    /** The enrolled student's PROFILE row (students.user_id is its PK). */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'id');
    }
}
