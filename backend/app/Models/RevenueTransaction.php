<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * revenue_transactions — ONE row per money-moving enrollment
 * (migration 2026_01_01_000026). This is the platform's accounting ledger.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ROW IS A SNAPSHOT, NOT A VIEW. THIS IS THE WHOLE DESIGN.             ║
 * ║                                                                          ║
 * ║ `teacher_profit_percentage` and `teacher_earnings` are STORED at the     ║
 * ║ moment of sale by App\Actions\Revenue\RecordRevenueTransaction. They are ║
 * ║ deliberately NOT derived at read time from                               ║
 * ║ teacher_profit_settings.profit_percentage.                               ║
 * ║                                                                          ║
 * ║ Deriving them would be a real accounting bug, not a style preference:    ║
 * ║ an admin who lowers a teacher's share from 60% to 40% today would        ║
 * ║ retroactively rewrite what that teacher earned on every sale they ever   ║
 * ║ made — including sales already paid out, which would then reconcile      ║
 * ║ against a number that no longer exists anywhere. The columns exist on    ║
 * ║ this table for exactly this reason.                                      ║
 * ║                                                                          ║
 * ║ Pinned by RevenueRecordingTest::                                         ║
 * ║ test_changing_the_percentage_afterwards_never_touches_a_recorded_row.    ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * TIMESTAMPS ARE OFF — `created_at` only, no `updated_at`. A ledger row is
 * append-only by intent; nothing in Stage 7 / Session A updates one after
 * insert.
 *
 * `subscription_id` IS NULLABLE and is ON DELETE SET NULL: deleting a
 * subscription must never destroy the record that money changed hands. Readers
 * that join through it must tolerate the null.
 */
class RevenueTransaction extends BaseModel
{
    protected $table = 'revenue_transactions';

    public $timestamps = false;

    /**
     * ┌── THE TWO PAYMENT METHODS THIS SESSION WRITES. ──────────────────────┐
     * │ `payment_method` is VARCHAR(50), not an ENUM, so the database will   │
     * │ accept any string — which is precisely why the two values the        │
     * │ application produces live here as constants instead of as literals   │
     * │ scattered over two Actions. Both strings are part of the API         │
     * │ contract: they surface as `paymentMethod` on GET /admin/subscriptions │
     * │ and they are what Session B's revenue reports filter on. A typo in   │
     * │ one call site would not fail anywhere — it would quietly create a    │
     * │ third payment method that no report counts.                          │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public const METHOD_ENROLLMENT_CODE = 'enrollment_code';

    public const METHOD_MANUAL_ADMIN = 'manual_admin';

    /** `status` is likewise VARCHAR(30) with a DB default of 'paid'. */
    public const STATUS_PAID = 'paid';

    /**
     * The ONE non-'paid' status this application ever writes, and it is
     * written from exactly one place: App\Actions\Revenue\ProcessRefund, on
     * approval. Session A's banner calls this table append-only "in Session
     * A"; Session B adds this single status transition and nothing else.
     *
     * The money columns are NOT rewritten when a sale is refunded — the
     * snapshot stays exactly as it was and the STATUS is what removes it from
     * every report, via scopePaid() below.
     */
    public const STATUS_REFUNDED = 'refunded';

    /**
     * The three filter names accepted on GET /revenue/platform and GET
     * /revenue/transactions, in the spelling Revenue.tsx puts on the wire
     * (camelCase query parameters). Public so the controller builds its
     * whitelist from the same list scopeFiltered() consumes.
     */
    public const FILTER_KEYS = ['dateFrom', 'dateTo', 'paymentMethod'];

    /**
     * The sentinel `paymentMethod` value meaning "no filter". Revenue.tsx's
     * select ships the literal string 'All' for its default option rather than
     * omitting the parameter, and backend/RevenueRepository::
     * transactionFilters() special-cased it. Without this branch the default
     * state of the screen would filter on a payment method that does not
     * exist and every table would render empty.
     */
    public const PAYMENT_METHOD_ALL = 'All';

    protected $fillable = [
        'subscription_id',
        'student_id',
        'course_id',
        'teacher_id',
        'gross_amount',
        'payment_method',
        'status',
        'teacher_profit_percentage',
        'teacher_earnings',
    ];

    /**
     * ALL THREE MONEY-ISH COLUMNS ARE `decimal:N`, NOT `float`.
     *
     * PDO returns DECIMAL as a PHP string (proven in
     * tests/Feature/PdoTypeCastingTest.php). `decimal:2` keeps the attribute a
     * fixed-precision STRING — which is what the column holds — and merely
     * normalises what a freshly-created instance reports before it is re-read
     * from MySQL. Same reasoning as Course::$price and
     * TeacherProfitSetting::$profit_percentage: a float cast would put binary
     * rounding into a revenue split.
     *
     * `teacher_profit_percentage` is DECIMAL(5,2) — two decimals, not five.
     */
    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'teacher_profit_percentage' => 'decimal:2',
            'teacher_earnings' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE CANONICAL DEFINITION OF "THIS MONEY COUNTS".                     │
     * │                                                                      │
     * │ backend/ writes `status = "paid"` inline in four separate places     │
     * │ (platformSummary, teacherStatement, coursesOverview's revenue        │
     * │ subquery, and the available-balance computation) — the same          │
     * │ duplication Subscription::scopeActive() exists to have ended for     │
     * │ enrollment. One of those four copies is already inconsistent in      │
     * │ backend/: `myStatement` does NOT filter status, so a refunded sale   │
     * │ still appears on the teacher's own statement while being excluded    │
     * │ from their available balance — two numbers on one screen that        │
     * │ disagree by design.                                                  │
     * │                                                                      │
     * │ SESSION B OWNS THAT ROUTE AND WILL CALL THIS SCOPE, closing the      │
     * │ inconsistency by construction rather than by remembering. It is      │
     * │ declared here, in the session that first WRITES the table, so there  │
     * │ is never a moment where two spellings of "paid" coexist.             │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), self::STATUS_PAID);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE ONE PLACE THE REPORT FILTERS ARE EXPRESSED. BOTH ROUTES USE IT.  ║
     * ║                                                                      ║
     * ║ GET /revenue/platform (the summary cards) and GET /revenue/          ║
     * ║ transactions (the table underneath them) are rendered on ONE screen  ║
     * ║ by Revenue.tsx, which sends the SAME dateFrom/dateTo/paymentMethod   ║
     * ║ to both. If the two queries ever disagree about what slice they are  ║
     * ║ describing, the user reads all-time totals sitting on top of a       ║
     * ║ date-filtered table and has no way to tell. backend/ avoided that by ║
     * ║ calling one shared transactionFilters() helper from both; this scope ║
     * ║ is that helper, and the two Actions may not re-express any clause    ║
     * ║ inline.                                                              ║
     * ║                                                                      ║
     * ║ WHAT THIS SCOPE DELIBERATELY DOES **NOT** DO: filter on `status`.    ║
     * ║ The summary counts paid money only (it composes ->paid() on top),    ║
     * ║ while the transaction TABLE shows refunded rows too so an admin can  ║
     * ║ see what was refunded. That asymmetry is backend/'s and is real; it  ║
     * ║ is kept OUT of this scope so it stays visible at each call site      ║
     * ║ instead of hiding inside a shared helper.                            ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * DATES ARE WIDENED TO WHOLE DAYS, exactly as backend/ did: `dateFrom` is
     * anchored at 00:00:00 and `dateTo` at 23:59:59. `created_at` is DATETIME,
     * so a bare '2026-03-01' on the upper bound would silently exclude every
     * transaction made that day after midnight — a whole day of revenue
     * missing from a report whose date picker says it is included.
     *
     * `!empty()` rather than `isset()`: '' means "no filter" here, matching
     * the controller's own trim-and-drop whitelist. A blank date must never
     * become the string ' 00:00:00'.
     *
     * @param array<string, string> $filters
     */
    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        if (! empty($filters['dateFrom'])) {
            $query->where($this->qualifyColumn('created_at'), '>=', $filters['dateFrom'] . ' 00:00:00');
        }

        if (! empty($filters['dateTo'])) {
            $query->where($this->qualifyColumn('created_at'), '<=', $filters['dateTo'] . ' 23:59:59');
        }

        if (! empty($filters['paymentMethod']) && $filters['paymentMethod'] !== self::PAYMENT_METHOD_ALL) {
            $query->where($this->qualifyColumn('payment_method'), $filters['paymentMethod']);
        }

        return $query;
    }

    /**
     * The paying student's ACCOUNT row, soft-deleted ones included.
     *
     * `student()` above resolves the `students` PROFILE; this resolves the
     * `users` row the way backend/'s `LEFT JOIN users su ON su.id =
     * rt.student_id` did — an unfiltered join, which therefore still produced
     * a name for a deleted account. `withTrashed()` reproduces that exactly.
     * Without it a deleted student's historical sales would render a blank
     * name column on an accounting screen, which reads as data loss rather
     * than as a deleted user.
     */
    public function studentAccount(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id', 'id')->withTrashed();
    }

    /** The earning instructor's ACCOUNT row. Same reasoning as above. */
    public function teacherAccount(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id', 'id')->withTrashed();
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id', 'id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'id');
    }

    /** The earning instructor's PROFILE row (teachers.user_id is its PK). */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id', 'user_id');
    }

    /** The paying student's PROFILE row (students.user_id is its PK). */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'user_id');
    }

    /**
     * Refund requests raised against this transaction.
     *
     * DECLARED IN SESSION A THOUGH SESSION B OWNS THE ROUTES, so the
     * double-refund guard has a relation to hang off instead of inventing a
     * second spelling of the same edge. See Refund::scopeBlocking().
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'revenue_transaction_id', 'id');
    }
}
