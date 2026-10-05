<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * center_codes — one 6-char code (1 capital letter + 5 digits), bound to
 * exactly one teacher, reusable by many students. See migration
 * 2026_09_06_000002 for the load-bearing unique index this generation relies
 * on (App\Actions\Center\GenerateCenterCode).
 */
class CenterCode extends BaseModel
{
    protected $table = 'center_codes';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'teacher_id',
        'created_by',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id', 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /** Every membership row that was created by joining through this specific code. */
    public function memberships(): HasMany
    {
        return $this->hasMany(CenterMembership::class, 'center_code_id', 'id');
    }

    /** Codes belonging to one teacher only. The admin-side isolation boundary. */
    public function scopeOwnedBy(Builder $query, User $instructor): Builder
    {
        return $query->where($this->qualifyColumn('teacher_id'), $instructor->getKey());
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc($this->qualifyColumn('created_at'))
            ->orderByDesc($this->qualifyColumn('code'));
    }
}
