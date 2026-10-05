<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CenterCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin listing/search shape for center codes.
 *
 * Carries the same `students` array CenterCodeDetailResource does — the
 * "تفاصيل كود سنتر" page renders one flat table (code + registered student's
 * name/phone/parent phone/teacher/dates + unlink action) directly off the
 * search results, with no per-code detail fetch. An unused code contributes
 * one row with `students: []`, rendered with dashes and no unlink action.
 */
final class CenterCodeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CenterCode $code */
        $code = $this->resource;

        return [
            'id' => (string) $code->getKey(),
            'code' => $code->code,
            'teacher_id' => (string) $code->teacher_id,
            'teacher_name' => $code->teacher?->user?->full_name,
            'is_active' => (bool) $code->is_active,
            'created_at' => $code->created_at?->format('Y-m-d H:i:s'),
            'students' => $code->memberships->map(fn ($membership) => [
                'membership_id' => (string) $membership->getKey(),
                'student_id' => (string) $membership->student_id,
                'name' => $membership->student?->user?->full_name,
                'student_code' => $membership->student?->student_code,
                'phone' => $membership->student?->phone,
                'parent_phone' => $membership->student?->parent_phone,
                'joined_at' => $membership->joined_at?->format('Y-m-d H:i:s'),
            ])->values()->all(),
        ];
    }
}
