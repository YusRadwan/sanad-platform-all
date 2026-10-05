<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Course;
use App\Models\EnrollmentCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The CREATION shape — POST /admin/enrollment-codes (freeze-table row 57).
 *
 * Seven keys, exactly backend/EnrollmentCodeService::create()'s return value.
 * A brand-new code has no redeemer, so `used_by_name`/`used_at` are ABSENT
 * rather than null — see EnrollmentCodeResource's docblock for why the two
 * shapes are two classes instead of one conditional Resource.
 *
 * `is_used` IS HARD-CODED FALSE and that is not laziness: this Resource is
 * only ever constructed from a row the generator just inserted, and the
 * insert writes `is_used = 0`. Reading it back off the model would be
 * identical in value and would suggest the field is variable here, which it
 * is not.
 *
 * THE COURSE IS PASSED IN, NOT LAZY-LOADED. The controller already resolved it
 * (that resolution is what produces the 404 'الدورة غير موجودة'), so accepting
 * it as a constructor argument avoids a second SELECT for a row that is
 * already in hand — and makes it impossible for the response to describe a
 * different course from the one that was validated.
 */
final class CreatedEnrollmentCodeResource extends JsonResource
{
    public function __construct(EnrollmentCode $code, private readonly Course $course)
    {
        parent::__construct($code);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var EnrollmentCode $code */
        $code = $this->resource;

        return [
            'id' => (string) $code->getKey(),
            'code' => $code->code,
            'course_id' => (string) $this->course->getKey(),
            'course_title' => $this->course->title,
            'teacher_name' => $this->course->teacher?->user?->full_name,
            'is_used' => false,
            'created_at' => $code->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
