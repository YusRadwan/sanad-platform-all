<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * center_attendances — one student's presence row for one session.
 * UNIQUE(center_session_id, student_id) — see migration 2026_09_06_000008.
 */
class CenterAttendance extends BaseModel
{
    protected $table = 'center_attendances';

    public $timestamps = false;

    protected $fillable = [
        'center_session_id',
        'center_group_id',
        'student_id',
        'marked_at',
        'note',
        'is_makeup',
    ];

    protected function casts(): array
    {
        return [
            'marked_at' => 'datetime',
            'is_makeup' => 'boolean',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CenterSession::class, 'center_session_id', 'id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CenterGroup::class, 'center_group_id', 'id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'user_id');
    }
}
