<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * payouts — money the platform has ACTUALLY paid an instructor
 * (migration 2026_01_01_000028).
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THERE IS NO DENORMALISED BALANCE COLUMN ANYWHERE, AND THAT IS THE WHOLE  ║
 * ║ REASON A PAYOUT WRITE IS NOT TRANSACTIONAL.                              ║
 * ║                                                                          ║
 * ║ An instructor's outstanding balance is DERIVED at read time by           ║
 * ║ App\Actions\Revenue\TeacherStatement as                                  ║
 * ║                                                                          ║
 * ║     SUM(revenue_transactions.teacher_earnings WHERE status='paid')       ║
 * ║   - SUM(payouts.amount)                                                  ║
 * ║                                                                          ║
 * ║ so recording a payout is ONE INSERT into ONE table with nothing to keep  ║
 * ║ in sync. backend/RevenueService::createPayout() carries this exact       ║
 * ║ reasoning in its own comment and deliberately does NOT open a            ║
 * ║ transaction; App\Actions\Revenue\CreatePayout preserves that. Wrapping a ║
 * ║ single-statement write in DB::transaction() buys no atomicity — it only  ║
 * ║ makes a future reader believe there is a second write somewhere that     ║
 * ║ they have failed to find.                                                ║
 * ║                                                                          ║
 * ║ Contrast App\Actions\Revenue\ProcessRefund, where approval genuinely     ║
 * ║ moves two rows in two tables and IS wrapped. The asymmetry is the point. ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * TIMESTAMPS ARE OFF — `created_at` only, no `updated_at`. Like the ledger it
 * settles, a payout row is append-only: nothing in Stage 7 updates one.
 *
 * `created_by` is ON DELETE SET NULL — removing the admin who recorded a
 * payout must never erase the payout. Readers must tolerate the null.
 */
class Payout extends BaseModel
{
    protected $table = 'payouts';

    public $timestamps = false;

    /**
     * `payment_method` is VARCHAR(50) and backend/RevenueRepository::
     * createPayout() defaulted an absent value to the literal 'manual'. That
     * default is part of the contract — TeacherPayouts.tsx renders the column
     * directly — so it lives here as a named constant rather than as a `??`
     * at the one call site that applies it.
     */
    public const METHOD_MANUAL = 'manual';

    protected $fillable = [
        'teacher_id',
        'amount',
        'payment_method',
        'notes',
        'created_by',
    ];

    /**
     * `amount` is DECIMAL(10,2). Cast decimal:2, never float — PDO hands
     * DECIMAL back as a PHP string (tests/Feature/PdoTypeCastingTest.php) and
     * this number is subtracted from a revenue split.
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** The receiving instructor's PROFILE row (teachers.user_id is its PK). */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id', 'user_id');
    }

    /**
     * The admin who recorded it. `withTrashed()` because `created_by` is
     * ON DELETE SET NULL, not CASCADE: the financial record outlives the
     * actor, and a soft-deleted admin must still resolve to a name on an
     * audit screen rather than silently becoming "nobody recorded this".
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id')->withTrashed();
    }
}
