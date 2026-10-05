<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * teachers — the instructor profile hanging off a `users` row.
 *
 * NOT a BaseModel: this table has NO surrogate id. Its primary key IS
 * `user_id` (CHAR(36) FK to users.id, see migration 000003), so HasUuids —
 * which would generate a fresh uuid for the key on create — is exactly wrong
 * here and would break the 1:1 link. $incrementing/$keyType are set by hand
 * instead.
 *
 * `grades` and `subjects` are JSON columns carrying flat arrays of names. The
 * 'array' cast is load-bearing, not cosmetic: App\Http\Resources\PublicUser
 * ships them verbatim to the frontend, where every `getInstructorInfo()` helper
 * under frontend/src/components/Instructor/ reads `currentUser.grades` to build
 * the grade/subject pickers for course, group, link and note creation. Without
 * the cast the frontend would receive a JSON *string* and every picker would
 * render empty — which is the exact bug that made these two keys part of the
 * /auth/me payload in the first place.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ $fillable IS AN EXPLICIT ALLOW-LIST, NOT `$guarded = []`. STAGE 4.       │
 * │                                                                          │
 * │ `whatsappNumbers` used to be accepted-and-silently-dropped, like the     │
 * │ `nationality` field used to be (that catalog, and the field, were both   │
 * │ removed platform-wide since). A later, deliberate product decision added │
 * │ `whatsapp_support_numbers` as a real column (see migration 000036), and  │
 * │ this field is now genuinely persisted, not merely tolerated. With        │
 * │ `$guarded = []` any stray key in a fill() would instead reach MySQL and  │
 * │ raise a "column not found" 500 on a request that should have succeeded.  │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
class Teacher extends Model
{
    protected $table = 'teachers';

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'phone',
        'specialization',
        'bio',
        'subjects',
        'grades',
        'banner_url',
        'profile_photo_url',
        'whatsapp_support_numbers',
        'rating',
        'is_verified',
        'is_active',
        'login_blocked',
        'center_enabled',
    ];

    /**
     * `rating` is DECIMAL(3,2) and comes back from MySQL as a PHP *string*
     * (proven in tests/Feature/PdoTypeCastingTest.php).
     *
     * STAGE 4 CHANGED THIS CAST FROM 'float' TO 'decimal:2', deliberately.
     * Stage 0 chose float so the JSON carried a number; but `decimal:2` keeps
     * the value a fixed-precision STRING ("4.50"), which is what the column
     * actually holds and what every other money-shaped value in this codebase
     * does (Course::$price is left uncast for the same reason). Nothing in
     * Stage 0-3 read this attribute — verified by grep — so the change has no
     * consumer to break, and it is made now rather than in Stage 7 because
     * this is the stage that gives Teacher its real body.
     */
    protected function casts(): array
    {
        return [
            'subjects' => 'array',
            'grades' => 'array',
            'whatsapp_support_numbers' => 'array',
            'rating' => 'decimal:2',
            'is_verified' => 'boolean',
            'is_active' => 'boolean',
            // Migration 000037. Read by App\Actions\Auth\LoginUser AND BY
            // NOTHING ELSE — never by a visibility/directory decision. See
            // that migration's docblock; scopePubliclyVisible below does not
            // select it, on purpose.
            'login_blocked' => 'boolean',
            // Migration 2026_09_10_000002. Read by
            // App\Http\Middleware\EnsureCenterEnabled AND BY NOTHING ELSE —
            // never by a visibility/listing/financial decision.
            'center_enabled' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The owning account.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THIS RETURNS **null** FOR A SOFT-DELETED TEACHER.                    │
     * │                                                                      │
     * │ All three deletes in Stage 4 write ONLY `users.deleted_at`; the      │
     * │ `teachers` row is deliberately left intact (backend/ does this on    │
     * │ purpose — see TeacherService::delete's comment). User uses           │
     * │ SoftDeletes, so its global scope makes this relation resolve to null │
     * │ the moment the account is deleted, while the profile row itself      │
     * │ still loads perfectly.                                               │
     * │                                                                      │
     * │ Every dereference of ->user must therefore be explicit about that    │
     * │ case. It is NOT enough to lean on `?->`: a presenter that            │
     * │ null-chains produces a well-formed 200 describing a deleted teacher, │
     * │ which is worse than a 404. scopeWithLiveUser() below is the guard    │
     * │ that keeps deleted accounts out of the read paths in the first place.│
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Social icons on the public profile.
     *
     * The ordering is part of the contract, not a nicety: backend/'s
     * TeacherRepository sorted `order_index ASC, platform ASC` in BOTH of its
     * social-link queries, and AddTeacher.tsx restores its toggle rows in the
     * order it receives them. Declared on the relation so every caller —
     * eager-load, lazy-load, count — inherits it rather than re-stating it.
     */
    public function socialLinks(): HasMany
    {
        return $this->hasMany(TeacherSocialLink::class, 'teacher_id', 'user_id')
            ->orderBy('order_index')
            ->orderBy('platform');
    }

    /**
     * Courses this teacher OWNS — drafts included, always.
     *
     * There is deliberately NO `->published()` filter here, and none may be
     * added. A teacher's own statistics must count their own unpublished work;
     * CoursePolicy::view()'s draft-hiding rule (Stage 3) hides drafts from
     * OTHERS, never from the owner. Attaching a visibility filter to the
     * OWNERSHIP relation would silently under-report every instructor's own
     * dashboard.
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'teacher_id', 'user_id');
    }

    /**
     * Question banks. `question_groups.teacher_id` is a DIRECT owner column —
     * a question group hangs off a teacher, not off a course, which is why
     * QuestionGroupPolicy is the one skeleton policy that does not use
     * ResolvesCourseOwnership.
     *
     * QuestionGroup is still a PlaceholderModel (Stage 6 owns it). Pointing a
     * relationship AT a placeholder is exactly the forward reference Stage 3
     * built that pattern to allow: the stub already declares the table and key
     * configuration correctly, and the constraint that a placeholder declare
     * no behaviour of its own is untouched by an edge defined here.
     */
    public function questionGroups(): HasMany
    {
        return $this->hasMany(QuestionGroup::class, 'teacher_id', 'user_id');
    }

    /**
     * Notice-board entries. STAGE 8.
     *
     * `announcements.teacher_id` is the same DIRECT owner column shape as
     * question_groups.teacher_id above — a FK into `teachers.user_id`, so the
     * local key is `user_id` here too — which is why AnnouncementPolicy is the
     * second policy that does not use ResolvesCourseOwnership.
     *
     * NOTE this relation can never surface a PLATFORM announcement
     * (teacher_id NULL, authored by an admin): those belong to no teacher by
     * construction. The admin list route reads Announcement::query() directly
     * for exactly that reason.
     */
    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class, 'teacher_id', 'user_id');
    }

    /**
     * Revenue share. hasOne, not belongsTo: `teacher_profit_settings.
     * teacher_id` is that table's PRIMARY KEY and an FK to teachers.user_id
     * (migration 000027), so the foreign key lives on the other side — the
     * same 1:1-via-primary-key shape as User::teacher().
     *
     * Stage 4 declares the edge and nothing more. Payouts, statements and
     * transactions are Stage 7's.
     */
    public function profitSetting(): HasOne
    {
        return $this->hasOne(TeacherProfitSetting::class, 'teacher_id', 'user_id');
    }

    /**
     * Every ledger row this instructor has earned from, refunded or not.
     *
     * ┌── DECLARED SO `withSum()` CAN REPLACE A JOIN ON /revenue/teachers. ──┐
     * │ App\Actions\Revenue\ListTeacherPercentages needs TWO independent     │
     * │ one-to-many aggregates per teacher — earnings and payouts — and      │
     * │ joining both onto `teachers` in one pass produces their CROSS        │
     * │ PRODUCT: a teacher with 3 transactions and 2 payouts would report    │
     * │ 2x the earnings and 3x the payouts. Exactly the fan-out backend/     │
     * │ hit on courses/overview (see Course::revenueTransactions), one table │
     * │ over.                                                                │
     * │                                                                      │
     * │ backend/RevenueRepository::listTeacherPercentages() avoided it by    │
     * │ hand-writing two correlated subqueries. withSum() compiles to the    │
     * │ same thing without the SQL. Pinned by                                │
     * │ TeacherPercentagesTest::                                             │
     * │ test_three_transactions_and_two_payouts_do_not_multiply.             │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function revenueTransactions(): HasMany
    {
        return $this->hasMany(RevenueTransaction::class, 'teacher_id', 'user_id');
    }

    /** Money already paid out to this instructor. See Payout's banner. */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'teacher_id', 'user_id');
    }

    /**
     * Excludes teachers whose underlying account is soft-deleted.
     *
     * whereHas() runs against the User model, so SoftDeletes' global scope is
     * applied inside the subquery for free — there is no hand-written
     * `deleted_at IS NULL` to forget, which is the class of bug the trait
     * exists to remove. This is the guard that makes GET
     * /admin/teachers/{id} answer 404 for a deleted teacher whose profile row
     * still exists.
     */
    public function scopeWithLiveUser(Builder $query): Builder
    {
        return $query->whereHas('user');
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE PUBLIC DIRECTORY (GET /api/v1/teachers). NO AUTH.                │
     * │                                                                      │
     * │ THE EXCLUSION OF `email` AND `phone` IS ENFORCED AT THE **QUERY**    │
     * │ LEVEL, NOT IN THE RESOURCE.                                          │
     * │                                                                      │
     * │ backend/'s TeacherRepository::listPublic() already hand-wrote this   │
     * │ column list for the same reason, and it is reproduced here rather    │
     * │ than replaced with a Resource that merely omits the two keys: a      │
     * │ Resource is a presentation choice one edit away from leaking,        │
     * │ whereas a column absent from the SELECT cannot be serialised by ANY  │
     * │ presenter, by any future code change, or by an accidental            │
     * │ ->toArray(). The hydrated model literally does not carry the         │
     * │ attributes.                                                          │
     * │                                                                      │
     * │ Pinned by a test that inspects the GENERATED SQL, not the response   │
     * │ JSON — asserting on the JSON would pass just as happily for a query  │
     * │ that fetched both columns and a Resource that dropped them, which is │
     * │ precisely the weaker guarantee this scope exists to replace.         │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * THREE FILTERS, all on `users`, all load-bearing:
     *   deleted_at IS NULL   a deleted teacher must not appear
     *   is_active = 1        a SUSPENDED teacher must not appear
     *   role = 'instructor'  a `teachers` row whose account was later demoted
     *                        is not an instructor any more
     *
     * NOTE `teachers.is_active` is NOT consulted. Suspension is written to
     * `users.is_active` by every path in this application (that column is what
     * EnsureUserIsActive reads); the teachers-table twin is legacy and unused.
     * Filtering on it too would make a teacher's visibility depend on a column
     * nothing writes.
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->select([
                'teachers.user_id',
                'teachers.specialization',
                'teachers.bio',
                'teachers.subjects',
                'teachers.grades',
                'teachers.banner_url',
                'teachers.profile_photo_url',
                'teachers.whatsapp_support_numbers',
                'teachers.is_verified',
                'users.full_name',
                'users.avatar_url',
                'users.username',
            ])
            ->join('users', 'users.id', '=', 'teachers.user_id')
            ->whereNull('users.deleted_at')
            ->where('users.is_active', true)
            ->where('users.role', UserRole::Instructor->value)
            ->orderBy('users.full_name');
    }
}
