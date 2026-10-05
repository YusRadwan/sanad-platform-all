<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CenterCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /admin/center-codes/{code} — admin "تفاصيل كود سنتر" page. Lists every
 * student who joined THROUGH THIS SPECIFIC CODE (a teacher may have several
 * codes; each shows only its own joiners).
 */
final class CenterCodeDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CenterCode $code */
        $code = $this->resource;

        return [
            'id' => (string) $code->getKey(),
            'code' => $code->code,
            'is_active' => (bool) $code->is_active,
            'created_at' => $code->created_at?->format('Y-m-d H:i:s'),
            'teacher' => [
                'id' => (string) $code->teacher_id,
                'name' => $code->teacher?->user?->full_name,
            ],
            'created_by' => [
                'id' => $code->created_by !== null ? (string) $code->created_by : null,
                'name' => $code->creator?->full_name,
            ],
            'students' => $code->memberships->map(fn ($membership) => [
                'membership_id' => (string) $membership->getKey(),
                'id' => (string) $membership->student_id,
                'name' => $membership->student?->user?->full_name,
                'student_code' => $membership->student?->student_code,
                'phone' => $membership->student?->phone,
                'parent_phone' => $membership->student?->parent_phone,
                'joined_at' => $membership->joined_at?->format('Y-m-d H:i:s'),
            ])->values()->all(),
        ];
    }
}
