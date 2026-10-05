<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * center_course_activations — a free, no-payment grant of ONE course to ONE
 * student. Read by App\Policies\Concerns\ResolvesCourseOwnership::
 * hasCourseActivation() as a fourth, additive way into a course's content.
 * UNIQUE(course_id, student_id) — see migration 2026_09_09_000002.
 */
class CenterCourseActivation extends BaseModel
{
    protected $table = 'center_course_activations';

    public $timestamps = false;

    protected $fillable = [
        'course_id',
        'teacher_id',
        'student_id',
        'center_session_id',
        'activated_by',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'user_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CenterSession::class, 'center_session_id', 'id');
    }
}
