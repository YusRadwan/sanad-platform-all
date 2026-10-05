<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * center_sessions — one class meeting ("حصة") of one center group.
 * `teacher_id` is a denormalised copy of the owning group's teacher, so
 * ownership filters stay a direct column comparison — see migration
 * 2026_09_06_000006.
 */
class CenterSession extends BaseModel
{
    protected $table = 'center_sessions';

    public $timestamps = false;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'teacher_id',
        'center_group_id',
        'session_date',
        'opened_at',
        'closed_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CenterGroup::class, 'center_group_id', 'id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(CenterAttendance::class, 'center_session_id', 'id');
    }

    public function exams(): BelongsToMany
    {
        return $this->belongsToMany(
            Exam::class,
            'center_session_exams',
            'center_session_id',
            'exam_id'
        );
    }

    /**
     * Attached videos, for per-student watch-percentage tracking. `Video`'s
     * own primary key is `content_item_id` (see that model's docblock), so
     * the related key must be declared explicitly — Eloquent's default guess
     * would look for a `videos.id` column that does not exist.
     */
    public function videos(): BelongsToMany
    {
        return $this->belongsToMany(
            Video::class,
            'center_session_videos',
            'center_session_id',
            'video_content_item_id',
            'id',
            'content_item_id'
        );
    }

    /** Sessions owned by one teacher — the tenant boundary. */
    public function scopeOwnedBy(Builder $query, User $instructor): Builder
    {
        return $query->where($this->qualifyColumn('teacher_id'), $instructor->getKey());
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
