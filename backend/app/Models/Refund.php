<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * refunds — a refund request raised against ONE revenue transaction
 * (migration 2026_01_01_000029).
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ WHY THIS MODEL EXISTS IN SESSION A THOUGH EVERY REFUND **ROUTE** IS      │
 * │ SESSION B's.                                                             │
 * │                                                                          │
 * │ Session B must be able to answer, cheaply and in one place, "is there    │
 * │ already a refund in flight against this transaction?" — the double-      │
 * │ refund guard. That question is a property of the LEDGER, and Session A   │
 * │ is the session that defines the ledger. Leaving it to Session B would    │
 * │ mean the predicate is authored by the code that consumes it, which is    │
 * │ how a guard ends up written inline at each of the two refund routes and  │
 * │ drifting between them.                                                   │
 * │                                                                          │
 * │ WHAT SESSION A DOES NOT DO: the guard itself. No route here creates,     │
 * │ approves or rejects a refund; nothing in this session reads this table.  │
 * │ Only the vocabulary is fixed.                                            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO NEW INDEX WAS ADDED, AND THAT IS A MEASURED DECISION, NOT AN OMISSION.
 * The obvious candidate is a composite `(revenue_transaction_id, status)`, but
 * `fk_refunds_transaction (revenue_transaction_id)` already exists (InnoDB
 * requires an index on every FK column) and the cardinality of
 * refunds-per-transaction is bounded by a very small number — one, if the
 * guard works. MySQL reaches the tiny matching set through the existing index
 * and filters `status` from the rows themselves; a second index would add
 * write cost to every refund insert to save a filter over ~1 row. Recorded in
 * docs/open-decisions.md (7.4) so Session B does not re-open it silently.
 *
 * TIMESTAMPS ARE OFF — `created_at` only, no `updated_at`.
 */
class Refund extends BaseModel
{
    protected $table = 'refunds';

    public $timestamps = false;

    protected $fillable = [
        'revenue_transaction_id',
        'amount',
        'reason',
        'status',
    ];

    /** `amount` is DECIMAL(10,2) — string-preserving cast, never float. */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => RefundStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function revenueTransaction(): BelongsTo
    {
        return $this->belongsTo(RevenueTransaction::class, 'revenue_transaction_id', 'id');
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE CANONICAL "A REFUND ALREADY EXISTS HERE" PREDICATE.              │
     * │                                                                      │
     * │ Pending OR approved — and the inclusion of PENDING is the entire     │
     * │ point. Guarding on 'approved' alone lets an admin stack five refund  │
     * │ requests against one transaction while the first is still awaiting   │
     * │ review, and approving them later pays the same sale out five times.  │
     * │ A REJECTED refund must NOT block: rejection is precisely the signal  │
     * │ that the request was not legitimate, so a corrected re-request is    │
     * │ the intended flow.                                                   │
     * │                                                                      │
     * │ SESSION B CONSUMES THIS SCOPE. It must not re-express the predicate  │
     * │ inline at either refund route — that is the duplication              │
     * │ Subscription::scopeActive() exists as a standing warning about.      │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), [
            RefundStatus::Pending->value,
            RefundStatus::Approved->value,
        ]);
    }
}
