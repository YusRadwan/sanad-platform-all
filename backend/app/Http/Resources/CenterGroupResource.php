<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CenterGroup;
use App\Models\CenterGroupSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CenterGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CenterGroup $group */
        $group = $this->resource;

        return [
            'id' => (string) $group->getKey(),
            'center_name' => $group->center_name,
            'grade_label' => $group->grade_label,
            'group_name' => $group->group_name,
            'schedule_text' => $group->schedule_text,
            'billing_type' => $group->billing_type,
            // Raw slots for the edit form's day/time picker — `schedule_text`
            // above stays the ready-to-display summary every OTHER screen
            // (attendance/report group pickers) already reads unchanged.
            'schedules' => $group->relationLoaded('schedules')
                ? $group->schedules->map(fn (CenterGroupSchedule $s): array => [
                    'day_of_week' => $s->day_of_week,
                    'time' => substr((string) $s->time_of_day, 0, 5),
                ])->values()->all()
                : [],
            'created_at' => $group->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
