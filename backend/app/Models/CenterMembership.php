<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * center_memberships — the single source of truth for "this student belongs
 * to this teacher's center". Every cross-teacher isolation check in the
 * Center System (attendance, reports, center-course access) ultimately tests
 * a row here. UNIQUE(student_id, teacher_id) — see migration 000003.
 */
class CenterMembership extends BaseModel
{
    protected $table = 'center_memberships';

    public $timestamps = false;

    protected $fillable = [
        'student_id',
        'teacher_id',
        'center_code_id',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'user_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id', 'user_id');
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(CenterCode::class, 'center_code_id', 'id');
    }

    /** Memberships belonging to one teacher — the isolation boundary for reports. */
    public function scopeForTeacher(Builder $query, User $instructor): Builder
    {
        return $query->where($this->qualifyColumn('teacher_id'), $instructor->getKey());
    }
}
