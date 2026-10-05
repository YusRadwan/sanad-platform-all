<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Course;
use App\Models\EnrollmentCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /admin/enrollment-codes/{code} — freeze-table row 59. Read-only.
 *
 * Backs the admin "تفاصيل كود الاشتراك" page and mirrors
 * backend/EnrollmentCodeService::details() key for key, including its nesting.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `student` IS GATED ON `used_by_student_id`, **NOT** ON `is_used`.        ║
 * ║                                                                          ║
 * ║ `fk_enrollment_codes_student` is ON DELETE SET NULL. Deleting a student  ║
 * ║ therefore leaves the code at `is_used = 1` with no row left to describe. ║
 * ║ Keying the block off `is_used` alone would render an object full of      ║
 * ║ nulls — a student with no name, no code and no phone — which reads as a  ║
 * ║ data-corruption bug to whoever is looking at the screen. The absence of  ║
 * ║ the block is the honest answer, and backend/ says so in its own comment. ║
 * ║                                                                          ║
 * ║ Both conditions are required: `is_used` alone is insufficient (above),   ║
 * ║ and `used_by_student_id` alone would be, too, if any future path ever    ║
 * ║ set it without flipping the flag.                                        ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * `course.is_deleted` EXISTS BECAUSE A SOFT-DELETED COURSE KEEPS ITS CODES.
 * The FK only cascades on a HARD delete, so the code row outlives the course.
 * The admin is told explicitly rather than shown a course that is no longer
 * reachable anywhere else in the product — which is also why the controller
 * resolves the course with `withTrashed()` for THIS route only. Every other
 * route in this session treats a trashed course as absent.
 *
 * `price`/`discount_price` ARE `(float)` ON THE WIRE, matching backend/'s
 * `(float) $row['course_price']`. The MODEL keeps them as fixed-precision
 * strings (Course::$casts, `decimal:2`) because they are money; the RESOURCE
 * casts on the way out because the frontend's TypeScript declares `number`.
 * The cast governs the model, the Resource governs the wire — the same split
 * CourseResource documents.
 */
final class EnrollmentCodeDetailResource extends JsonResource
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

        $isUsed = (bool) $code->is_used;
        $student = $code->redeemer;

        return [
            'code' => $code->code,
            'is_used' => $isUsed,
            'created_at' => $code->created_at?->format('Y-m-d H:i:s'),
            'used_at' => $code->used_at?->format('Y-m-d H:i:s'),
            'course' => [
                'id' => (string) $this->course->getKey(),
                'title' => $this->course->title,
                'price' => (float) $this->course->price,
                'discount_price' => $this->course->discount_price !== null
                    ? (float) $this->course->discount_price
                    : null,
                'is_published' => (bool) $this->course->is_published,
                'is_deleted' => $this->course->trashed(),
                'teacher_id' => (string) $this->course->teacher_id,
                // backend/ falls back to the username when full_name is empty.
                'teacher_name' => $this->course->teacher?->user?->full_name
                    ?: $this->course->teacher?->user?->username,
            ],
            'created_by' => [
                'id' => $code->created_by !== null ? (string) $code->created_by : null,
                'name' => $code->creator?->full_name,
                'username' => $code->creator?->username,
            ],
            'student' => $isUsed && $student !== null ? [
                'id' => (string) $student->getKey(),
                'name' => $student->user?->full_name,
                'username' => $student->user?->username,
                'student_code' => $student->student_code,
                'phone' => $student->phone,
                'parent_phone' => $student->parent_phone,
            ] : null,
        ];
    }
}
