<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * center_groups — a teacher's roster group at one physical center. Distinct
 * from App\Models\CourseGroup (content organisation inside one course) and
 * App\Models\QuestionGroup (question bank folder) — see migration
 * 2026_09_06_000004.
 */
class CenterGroup extends BaseModel
{
    protected $table = 'center_groups';

    /** The only two valid "شهر أو حصة" values — a fixed, non-free-text set. */
    public const BILLING_TYPES = ['شهر', 'حصة'];

    /** Saturday-first week, matching the existing "السبت 6:00 مساءً" example this replaces. */
    public const DAYS = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];

    protected $fillable = [
        'teacher_id',
        'center_name',
        'grade_label',
        'group_name',
        'schedule_text',
        'billing_type',
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

    public function sessions(): HasMany
    {
        return $this->hasMany(CenterSession::class, 'center_group_id', 'id')
            ->orderByDesc('session_date')
            ->orderByDesc('opened_at');
    }

    /** This group's (day, time) slots — see CenterGroupController for how `schedule_text` is derived from these. */
    public function schedules(): HasMany
    {
        return $this->hasMany(CenterGroupSchedule::class, 'center_group_id', 'id')->orderBy('order_index');
    }

    /** Students who have ever been marked present in this group. */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(
            Student::class,
            'center_group_students',
            'center_group_id',
            'student_id',
            'id',
            'user_id'
        )->withPivot('joined_at');
    }

    /** Groups owned by one teacher — the tenant boundary for every Center controller. */
    public function scopeOwnedBy(Builder $query, User $instructor): Builder
    {
        return $query->where($this->qualifyColumn('teacher_id'), $instructor->getKey());
    }
}
