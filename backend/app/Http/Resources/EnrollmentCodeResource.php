<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EnrollmentCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The ADMIN LISTING shape — GET /admin/enrollment-codes (freeze-table row 58).
 *
 * Mirrors backend/EnrollmentCodeService::list() key for key.
 *
 * ┌── WHY THIS IS A DIFFERENT CLASS FROM CreatedEnrollmentCodeResource. ─────┐
 * │ The creation response carries SEVEN keys and this one carries NINE:      │
 * │ a freshly generated code has no redeemer, so backend/ omitted            │
 * │ `used_by_name` and `used_at` from POST's payload rather than sending     │
 * │ them as null. Expressing that as `when()` inside one Resource is the     │
 * │ pattern Stage 5 decision 5.12 rejected outright — a conditional payload  │
 * │ is a leak waiting for the condition to be evaluated wrongly — so the two │
 * │ shapes are two classes. Neither can grow the other's keys by accident.   │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `is_used` IS A REAL BOOLEAN, not MySQL's TINYINT 1|0. The model's cast does
 * the work (see BaseModel's docblock and PdoTypeCastingTest); this class only
 * has to not undo it.
 *
 * `used_by_name` READS THROUGH `redeemer.user`, and it is null in TWO distinct
 * situations that the payload deliberately does not distinguish: the code was
 * never redeemed, or its redeemer's account was later deleted
 * (`used_by_student_id` is ON DELETE SET NULL). The listing shows a dash for
 * both; the DETAIL route is where that distinction is drawn.
 */
final class EnrollmentCodeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var EnrollmentCode $code */
        $code = $this->resource;

        return [
            'id' => (string) $code->getKey(),
            'code' => $code->code,
            'course_id' => (string) $code->course_id,
            'course_title' => $code->course?->title,
            'teacher_name' => $code->course?->teacher?->user?->full_name,
            'is_used' => (bool) $code->is_used,
            'used_by_name' => $code->redeemer?->user?->full_name,
            'used_at' => $code->used_at?->format('Y-m-d H:i:s'),
            'created_at' => $code->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
