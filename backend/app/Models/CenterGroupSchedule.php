<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * center_group_schedules — one (day, time) slot for a center_groups row. A
 * group may have several (e.g. "السبت 6:00 مساءً" AND "الثلاثاء 4:00 مساءً"),
 * see migration 2026_09_09_000003.
 */
class CenterGroupSchedule extends BaseModel
{
    protected $table = 'center_group_schedules';

    public $timestamps = false;

    protected $fillable = [
        'center_group_id',
        'day_of_week',
        'time_of_day',
        'order_index',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(CenterGroup::class, 'center_group_id', 'id');
    }
}
