<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * teacher_applications — one public "طلب مدرس جديد" submission (migration
 * 2026_08_31_000001, open decision 10.23). See that migration's docblock for
 * why this row carries plain snapshot strings rather than catalog foreign
 * keys.
 *
 * TIMESTAMPS ARE OFF: the table has `created_at` and no `updated_at` — same
 * treatment as EnrollmentCode and Subscription.
 */
class TeacherApplication extends BaseModel
{
    protected $table = 'teacher_applications';

    public $timestamps = false;

    protected $fillable = [
        'full_name',
        'phone',
        'whatsapp',
        'governorate',
        'age',
        'years_of_experience',
        'subject',
        'section_name',
        'grades',
        'social_links',
    ];

    protected function casts(): array
    {
        return [
            'age' => 'integer',
            'years_of_experience' => 'integer',
            'grades' => 'array',
            'social_links' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** Admin listing order — newest submission first. */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc($this->qualifyColumn('created_at'));
    }
}
