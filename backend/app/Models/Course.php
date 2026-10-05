<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * courses — the tenant boundary of this whole application.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ `teacher_id` POINTS AT `teachers.user_id`, **NOT** AT `users.id`.        │
 * │                                                                          │
 * │ The FK is declared `fk_courses_teacher -> teachers(user_id)` in          │
 * │ migration 2026_01_01_000011 — verified, not assumed. `teachers.user_id`  │
 * │ IS that table's primary key AND is itself an FK to `users.id`, so the    │
 * │ column's VALUE is numerically identical to the owning user's id. That    │
 * │ coincidence is exactly what makes this easy to get wrong: comparing      │
 * │ `courses.teacher_id` to `users.id` happens to work today, but it is a    │
 * │ different claim — it says "this user account owns the course" instead of │
 * │ "this teacher PROFILE owns the course". A student or admin row has no    │
 * │ `teachers` row at all, so it can never legitimately match.               │
 * │                                                                          │
 * │ The relationship below therefore declares BOTH keys explicitly:          │
 * │ foreign key `teacher_id`, owner key `user_id`. Leaving Laravel to guess  │
 * │ would give it `teachers.id`, a column that does not exist.               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * SOFT DELETES: `deleted_at` DATETIME exists on this table (verified in the
 * migration), and backend/ filtered it by hand in each repository query. The
 * trait makes that a global property instead of a per-query one, which removes
 * the class of bug where one forgotten `AND deleted_at IS NULL` republishes a
 * deleted course.
 *
 * `is_published` IS CAST TO BOOLEAN, not left as MySQL's TINYINT(1)/int 1|0 —
 * see BaseModel's docblock and tests/Feature/PdoTypeCastingTest.php.
 * CoursePolicy::view() branches on it directly (`if ($course->is_published)`),
 * and an int 0 would be falsy anyway, but the cast makes the branch a genuine
 * boolean test rather than a lucky one.
 *
 * STAGE 5 / SESSION A gave this model its WRITE side: sections(), groups(),
 * grade(), subject(), scopePublished(), and money casts. `contentItems()` is
 * still absent — Session B owns App\Models\ContentItem and will declare that
 * edge itself.
 */
class Course extends BaseModel
{
    use SoftDeletes;

    protected $table = 'courses';

    protected $fillable = [
        'title',
        'description',
        'price',
        'discount_price',
        'thumbnail_url',
        'teacher_id',
        'grade_id',
        'subject_id',
        'is_published',
        'visibility',
    ];

    /** Course::VISIBILITY_* — Center System course scope. See migration 2026_09_06_000001. */
    public const VISIBILITY_PLATFORM = 'platform';

    public const VISIBILITY_CENTER = 'center';

    public const VISIBILITY_BOTH = 'both';

    /**
     * `price`/`discount_price` are DECIMAL(10,2), which PDO returns as PHP
     * STRINGS (proven in tests/Feature/PdoTypeCastingTest.php).
     *
     * ┌── STAGE 5 ADDED `decimal:2`. IT IS **NOT** A FLOAT CAST. ────────────┐
     * │ Stage 3 left both columns uncast with the note "a float cast would   │
     * │ introduce binary rounding into a money value". That reasoning is     │
     * │ unchanged and is why the cast chosen here is `decimal:2`, exactly as │
     * │ Teacher::$rating: it keeps the attribute a FIXED-PRECISION STRING    │
     * │ ("100.00"), which is what the column holds, and merely normalises    │
     * │ what a freshly-written model instance reports before it is re-read   │
     * │ from MySQL. Without it, `POST /courses` with `price: 9.5` answered   │
     * │ `9.5` while the very next `GET` answered `"9.50"` — the same course, │
     * │ two shapes.                                                          │
     * │                                                                      │
     * │ The JSON contract is unaffected: App\Http\Resources\CourseResource   │
     * │ applies `(float)` on the way out, matching backend/                  │
     * │ CourseService::present()'s `(float) $row['price']` exactly. The cast │
     * │ governs the MODEL; the Resource governs the WIRE.                    │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'discount_price' => 'decimal:2',
            'is_published' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * The owning instructor PROFILE.
     *
     * Both keys are explicit — see the class docblock. `teachers` has no `id`
     * column, so Laravel's convention-based guess would produce invalid SQL.
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id', 'user_id');
    }

    /** Every enrollment row for this course, active or not. */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'course_id', 'id');
    }

    /**
     * Every ledger row this course has produced, refunded or not.
     *
     * ┌── DECLARED SO THAT `withSum()` CAN BE USED INSTEAD OF A JOIN. ───────┐
     * │ backend/SubscriptionRepository::coursesOverview() carries a comment  │
     * │ recording a REAL production bug: joining `subscriptions` AND         │
     * │ `revenue_transactions` to `courses` in one query fans out            │
     * │ cartesianly, so every transaction row repeats once per matching      │
     * │ subscription row and SUM(gross_amount) came back multiplied by the   │
     * │ subscription count (measured live: a course with 3 subscriptions and │
     * │ 200.00 of real revenue reported 600.00). COUNT(DISTINCT s.id) hid    │
     * │ the same fan-out on the count side, which is why only the money      │
     * │ column looked wrong.                                                 │
     * │                                                                      │
     * │ Eloquent's withCount()/withSum() compile to CORRELATED SUBQUERIES in │
     * │ the SELECT list, never to joins, so each aggregate is computed over  │
     * │ its own row set and cannot be multiplied by a sibling's. That is why │
     * │ App\Actions\Enrollment\CoursesOverview uses them and why adding a    │
     * │ `join()` to that query would reintroduce a fixed bug. Pinned by      │
     * │ CoursesOverviewTest::                                                │
     * │ test_three_subscriptions_and_two_transactions_do_not_multiply.       │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function revenueTransactions(): HasMany
    {
        return $this->hasMany(RevenueTransaction::class, 'course_id', 'id');
    }

    /** Enrollment codes issued for this course, used or not. */
    public function enrollmentCodes(): HasMany
    {
        return $this->hasMany(EnrollmentCode::class, 'course_id', 'id');
    }

    /**
     * The syllabus. ORDERING IS DECLARED ON THE RELATION, not restated at each
     * call site — `order_index ASC, id ASC`, byte for byte what backend/
     * SectionRepository::listByCourse() sorted by.
     *
     * The `id` tie-break is load-bearing, not decoration: nextOrderIndex() is
     * racy by an approved decision (Stage 5 decision #4), so two sections CAN
     * legitimately share an order_index. Without the second key MySQL is free
     * to return them in either order on each request and the instructor's
     * syllabus appears to shuffle itself.
     */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class, 'course_id', 'id')
            ->orderBy('order_index')
            ->orderBy('id');
    }

    /**
     * Content GROUPS — `course_groups`, backend/'s GroupService. Same ordering
     * rule and same reason as sections().
     *
     * NOT named groups() by accident: `Group` is ambiguous in an application
     * that also has question groups. See App\Models\CourseGroup.
     */
    public function groups(): HasMany
    {
        return $this->hasMany(CourseGroup::class, 'course_id', 'id')
            ->orderBy('order_index')
            ->orderBy('id');
    }

    /**
     * The catalogue rows `courses.grade_id` / `courses.subject_id` point at.
     *
     * Both FKs are ON DELETE SET NULL (migration 000011), so both relations
     * are legitimately null and every reader must say so explicitly.
     */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class, 'grade_id', 'id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id', 'id');
    }

    /**
     * THE PUBLIC CATALOGUE PREDICATE — `GET /courses`, freeze-table row 8.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THIS SCOPE AND CoursePolicy::view() MUST NEVER DISAGREE.             │
     * │                                                                      │
     * │ The listing does NOT call `view` per row — that would be an N+1      │
     * │ policy evaluation on the busiest read in the application — so the    │
     * │ two express the same rule in two places by construction. What keeps  │
     * │ them honest is a CONSISTENCY TEST, not this comment: anything the    │
     * │ list omits must also 404 individually, and anything the list shows   │
     * │ must be individually readable by the same caller. See                │
     * │ CourseVisibilityTest::test_the_list_and_the_single_read_never_       │
     * │ disagree.                                                            │
     * │                                                                      │
     * │ `deleted_at IS NULL` is absent on purpose: SoftDeletes' global scope │
     * │ already adds it, qualified, to every query on this model.            │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ┌── `is_published` IS NOT THE WHOLE PREDICATE. THE OWNER'S ACCOUNT MUST ┐
     * │ BE LIVE TOO.                                                          │
     * │                                                                       │
     * │ Suspending a teacher writes `users.is_active = 0`, and                │
     * │ Teacher::scopePubliclyVisible() already drops them out of the public   │
     * │ directory for it. Without the second clause here their COURSES stayed  │
     * │ in the storefront — listed, individually readable, and rendering the   │
     * │ suspended instructor's name and avatar on every card. Suspension that  │
     * │ hides the teacher while leaving their catalogue on sale hides nothing. │
     * │                                                                       │
     * │ `whereHas('teacher.user')` and not a join: the subquery runs against   │
     * │ the User model, so SoftDeletes' global scope supplies                  │
     * │ `users.deleted_at IS NULL` for free — the same reason                  │
     * │ Teacher::scopeWithLiveUser() is a whereHas. A join would owe a         │
     * │ hand-written null check that a future edit can forget. It also cannot  │
     * │ fan out: whereHas compiles to `EXISTS(...)`, never to extra rows,      │
     * │ which matters on a listing that is already paginated by COUNT.         │
     * │                                                                       │
     * │ `teachers.is_active` is deliberately NOT consulted, exactly as         │
     * │ Teacher::scopePubliclyVisible() records: that twin column is legacy    │
     * │ and nothing writes it.                                                 │
     * │                                                                       │
     * │ THE BOOLEAN TWIN OF THIS SCOPE IS isPubliclyVisible() BELOW, and the   │
     * │ two must agree row for row — CourseVisibilityTest::test_the_list_and_  │
     * │ the_single_read_never_disagree is what proves it.                      │
     * └───────────────────────────────────────────────────────────────────────┘
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where($this->qualifyColumn('is_published'), true)
            ->where($this->qualifyColumn('visibility'), '!=', self::VISIBILITY_CENTER)
            ->whereHas(
                'teacher.user',
                fn (Builder $accounts): Builder => $accounts->where('is_active', true)
            );
    }

    /**
     * THE ROW-AT-A-TIME TWIN OF scopePublished(). Read its box first.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THIS IS THE **PUBLIC** PREDICATE AND NOTHING ELSE. IT IS NOT AN      │
     * │ OWNERSHIP TEST AND MUST NEVER BE USED AS ONE.                        │
     * │                                                                      │
     * │ CoursePolicy::view() and ExamPolicy::view() call it in their         │
     * │ public-visibility branch ONLY — the branch that may admit a guest.    │
     * │ Their ownership branches (and Gate::before's admin bypass, which      │
     * │ answers before either policy body runs) are untouched by it, which is │
     * │ what keeps a SUSPENDED instructor's own course reachable to that      │
     * │ instructor and to an admin while it is gone from the storefront.      │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ONE QUERY, NOT TWO. `$this->teacher?->user?->is_active` would lazy-load
     * `teachers` and then `users` on every public course read — two round trips
     * on the hottest path in the application, and CourseStructureTest pins the
     * structure endpoint's query count. An EXISTS subquery answers the same
     * question in one, and a course whose `teachers` row is missing entirely
     * answers false, i.e. fails CLOSED for the public.
     *
     * `users.deleted_at IS NULL` is again supplied by the User model's own
     * SoftDeletes global scope inside the subquery.
     */
    public function isPubliclyVisible(): bool
    {
        if (!$this->is_published || $this->visibility === self::VISIBILITY_CENTER) {
            return false;
        }

        return $this->teacher()
            ->whereHas('user', fn (Builder $accounts): Builder => $accounts->where('is_active', true))
            ->exists();
    }

    /**
     * Courses OWNED by one instructor — drafts included, always.
     *
     * Added in Stage 4; pinned in advance by docs/authorization-freeze-table.md
     * ("نطاقات القوائم"), which requires this scope to exist exactly once
     * rather than being re-typed at each aggregate.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THIS IS THE SCOPE FOR A TEACHER'S OWN STATISTICS, AND IT MUST NOT    │
     * │ BE CONFUSED WITH A VISIBILITY SCOPE.                                 │
     * │                                                                      │
     * │ Ownership and visibility answer different questions. CoursePolicy::  │
     * │ view()'s draft-hiding rule (Stage 3) hides unpublished courses from  │
     * │ OTHERS — never from the owner. A teacher's course count, student     │
     * │ count and per-course enrollment table must therefore include their   │
     * │ own drafts; filtering these aggregates through anything              │
     * │ visibility-shaped would under-report the instructor's own dashboard  │
     * │ and make "0 courses" appear the moment they unpublish one.           │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * `deleted_at IS NULL` is NOT written here: the SoftDeletes global scope
     * already adds it, qualified, to every query on this model.
     */
    public function scopeOwnedBy(Builder $query, User $instructor): Builder
    {
        return $query->where($this->qualifyColumn('teacher_id'), $instructor->getKey());
    }

    /**
     * Courses one student is ACTUALLY enrolled in.
     *
     * "Enrolled" is Subscription::scopeActive() and nothing else — the single
     * canonical definition. backend/CourseRepository::listByStudentSubscription
     * re-typed the predicate inline (one of the six copies Stage 3 exists to
     * have ended), which is why this scope composes ->active() rather than
     * repeating `status = 'active' AND (expires_at IS NULL OR …)`.
     */
    public function scopeSubscribedBy(Builder $query, User $student): Builder
    {
        return $query->whereHas(
            'subscriptions',
            fn (Builder $q): Builder => $q->where('student_id', $student->getKey())->active()
        );
    }

    /**
     * Center System — courses one student can reach through a center
     * membership: `visibility` must be `center`/`both`, AND the student must
     * hold a `center_memberships` row with THIS course's owning teacher. No
     * subscription/payment is involved — center access is the membership
     * itself, matching the "reuse existing systems, no new payment system"
     * constraint on this feature.
     */
    public function scopeCenterAccessibleBy(Builder $query, User $student): Builder
    {
        return $query
            ->whereIn($this->qualifyColumn('visibility'), [self::VISIBILITY_CENTER, self::VISIBILITY_BOTH])
            ->whereExists(function ($sub) use ($student): void {
                $sub->selectRaw('1')
                    ->from('center_memberships')
                    ->whereColumn('center_memberships.teacher_id', $this->qualifyColumn('teacher_id'))
                    ->where('center_memberships.student_id', $student->getKey());
            });
    }

    /**
     * "تفعيل كورس سنتر" (decision 10.30/10.35) — courses a teacher granted
     * this ONE student for free, individually, via `center_course_activations`.
     * Deliberately NOT restricted to `center`/`both` visibility (unlike
     * scopeCenterAccessibleBy above) — a platform course can be activated
     * too; see App\Actions\Center\ActivateCenterCourse and
     * ResolvesCourseOwnership::hasCourseActivation(), which this mirrors as
     * an `exists()`-shaped `whereExists` for the same "answer the fact,
     * don't load the row" reason.
     */
    public function scopeActivatedFor(Builder $query, User $student): Builder
    {
        return $query->whereExists(function ($sub) use ($student): void {
            $sub->selectRaw('1')
                ->from('center_course_activations')
                ->whereColumn('center_course_activations.course_id', $this->qualifyColumn('id'))
                ->where('center_course_activations.student_id', $student->getKey());
        });
    }

    /** Courses of one teacher visible to their center students — `center`/`both`. */
    public function scopeCenterVisible(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('visibility'), [self::VISIBILITY_CENTER, self::VISIBILITY_BOTH]);
    }
}
