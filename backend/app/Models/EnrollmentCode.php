<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * enrollment_codes — one 8-digit code, bound to exactly ONE course, redeemable
 * exactly ONCE (migration 2026_01_01_000031).
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ TWO DATABASE OBJECTS ARE LOAD-BEARING ON THIS TABLE AND NEITHER IS       │
 * │ RE-EXPRESSED IN PHP:                                                     │
 * │                                                                          │
 * │   UNIQUE KEY uq_enrollment_codes_code (code)                             │
 * │       the authority on global uniqueness. Generation is INSERT-then-     │
 * │       catch (App\Actions\Enrollment\GenerateEnrollmentCode), and the     │
 * │       retry loop MATCHES THIS INDEX NAME inside the caught               │
 * │       QueryException. Renaming the index silently turns every collision  │
 * │       into a 500. Verified live at the start of Stage 7 / Session A.     │
 * │                                                                          │
 * │   is_used TINYINT(1) NOT NULL DEFAULT 0                                  │
 * │       the single-use guarantee, enforced by a GUARDED UPDATE             │
 * │       (`WHERE is_used = 0`) in                                           │
 * │       App\Actions\Enrollment\RedeemEnrollmentCode — never by a read      │
 * │       followed by a write, which is racy. See that class's banner for    │
 * │       why this problem does NOT want lockForUpdate().                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * TIMESTAMPS ARE OFF: the table has `created_at` and NO `updated_at`, so
 * Eloquent's automatic management would UPDATE a column that does not exist.
 * Same treatment as Subscription and LoginSession.
 *
 * `used_by_student_id` IS ON DELETE SET NULL, so a redeemed code whose student
 * was later deleted keeps `is_used = 1` with nothing to describe. Readers must
 * therefore test `used_by_student_id`, not `is_used`, before rendering student
 * data — backend/EnrollmentCodeService::details() says exactly this, and
 * App\Http\Resources\EnrollmentCodeDetailResource preserves it.
 */
class EnrollmentCode extends BaseModel
{
    protected $table = 'enrollment_codes';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'course_id',
        'created_by',
        'is_used',
        'used_by_student_id',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            // TINYINT(1) comes back as PHP int 1|0 (PdoTypeCastingTest); the
            // frontend's `is_used` is declared boolean.
            'is_used' => 'boolean',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** The one course this code unlocks. Authoritative — never the request body. */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'id');
    }

    /** The admin who generated it. NULLABLE (FK is ON DELETE SET NULL). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /** The redeeming student's PROFILE row. NULLABLE for the reason above. */
    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'used_by_student_id', 'user_id');
    }

    /**
     * Admin listing order — `created_at DESC, code DESC`, byte for byte what
     * backend/EnrollmentCodeRepository::listRecent() sorted by.
     *
     * The `code` tie-break is load-bearing rather than decorative: `created_at`
     * is a DATETIME with one-second resolution and a bulk generation run
     * produces many codes inside the same second, so without a second key
     * MySQL may return them in a different order on each request and the
     * admin's list appears to shuffle itself between refreshes.
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc($this->qualifyColumn('created_at'))
            ->orderByDesc($this->qualifyColumn('code'));
    }
}
