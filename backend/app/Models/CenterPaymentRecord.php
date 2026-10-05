<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * center_payment_records — "متابعة مدفوعات السنتر". A pure paid/unpaid
 * tracking log, NEVER a financial record — see migration 2026_09_10_000001's
 * banner for why no amount/price/balance column exists or may be added.
 */
class CenterPaymentRecord extends BaseModel
{
    public const TYPE_SESSION = 'session';

    public const TYPE_MONTH = 'month';

    public const TYPES = [self::TYPE_SESSION, self::TYPE_MONTH];

    public const STATUS_PAID = 'paid';

    public const STATUS_UNPAID = 'unpaid';

    public const STATUSES = [self::STATUS_PAID, self::STATUS_UNPAID];

    protected $table = 'center_payment_records';

    protected $fillable = [
        'teacher_id',
        'student_id',
        'center_group_id',
        'payment_type',
        'period',
        'status',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id', 'user_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'user_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CenterGroup::class, 'center_group_id', 'id');
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by', 'id');
    }

    /** The tenant boundary — every query in CenterPaymentController goes through this. */
    public function scopeForTeacher(Builder $query, User $instructor): Builder
    {
        return $query->where($this->qualifyColumn('teacher_id'), $instructor->getKey());
    }
}
