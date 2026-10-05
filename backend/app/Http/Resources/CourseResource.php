<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Course;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The course payload — a byte-for-byte port of
 * backend/app/Services/CourseService::present().
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ EVERY DUPLICATED KEY BELOW IS DELIBERATE. DO NOT "TIDY UP" THIS SHAPE.  ║
 * ║                                                                          ║
 * ║   discount_price / discountPrice     snake AND camel                     ║
 * ║   thumbnail_url  / image             two names for one column            ║
 * ║   grade_name     / grade             likewise                            ║
 * ║   subject_name   / subject           likewise                            ║
 * ║   teacher_name+teacher_avatar / teacher{name,image}   flat AND nested    ║
 * ║                                                                          ║
 * ║ frontend/src/types/course.ts declares BOTH conventions and different     ║
 * ║ components read different ones (CourseCard reads `image`,                ║
 * ║ CourseManagement reads `thumbnail_url`; CourseStructureManagement        ║
 * ║ filters on `course.grade_name || course.grade`). Dropping either half    ║
 * ║ blanks a picker or an image with no error anywhere. backend/'s present() ║
 * ║ populates both for exactly this reason and says so.                      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── `price` SHIPS AS A JSON **NUMBER**, `(float)`, MATCHING backend/. ─────┐
 * │ The model casts it `decimal:2` (a fixed-precision STRING, which is what  │
 * │ the DECIMAL(10,2) column holds and what avoids binary rounding in        │
 * │ storage). The cast governs the MODEL; this line governs the WIRE, and    │
 * │ the wire contract is `(float) $row['price']` — the frontend's            │
 * │ `price: number` and its own `isFree` arithmetic depend on it.            │
 * │                                                                          │
 * │ `discount_price` is `(float)` ONLY WHEN NON-NULL. `(float) null` is 0.0, │
 * │ and 0.0 means "this course has a 100% discount" to the storefront —      │
 * │ null means "no discount at all". backend/ writes the same guard          │
 * │ (`isset($row['discount_price']) ? (float) … : null`) and the two are not │
 * │ interchangeable.                                                         │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── `isFree` IS DERIVED FROM `price <= 0`, NOT FROM `discount_price`. ─────┐
 * │ Ported verbatim. It is also why Part 7.2 added the non-negative price    │
 * │ check: a negative price made a paid course render as free.               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── RELATION ACCESS IS `whenLoaded`-FREE ON PURPOSE. ──────────────────────┐
 * │ Every route in Stage 5 that emits this resource eager-loads              │
 * │ `teacher.user`, `grade` and `subject` (see CourseController's            │
 * │ withCourseRelations()). Reading them through the relation directly —     │
 * │ rather than through whenLoaded(), which would silently OMIT a key when a │
 * │ future caller forgets the load — turns that mistake into an N+1 query    │
 * │ instead of a missing field. A slow response is debuggable; a silently    │
 * │ absent `grade_name` empties a dropdown with no symptom.                  │
 * │                                                                          │
 * │ backend/ had the opposite problem and it is worth recording: its         │
 * │ findById()/paginate() queries did NOT join `grades`/`subjects`, so       │
 * │ `grade_name` was ALWAYS null on GET /courses and GET /courses/{id} and   │
 * │ only populated on the two listings that joined. Stage 5 loads them       │
 * │ everywhere, which fills in keys that used to be null and removes no key  │
 * │ and changes no type. Recorded as decision 5.3.                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * @mixin Course
 */
final class CourseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Course $course */
        $course = $this->resource;

        $price = (float) $course->getAttribute('price');
        $discount = $course->getAttribute('discount_price') === null
            ? null
            : (float) $course->getAttribute('discount_price');

        /** @var Teacher|null $teacher */
        $teacher = $course->getRelationValue('teacher');
        $teacherUser = $teacher?->getRelationValue('user');

        $teacherName = $teacherUser?->getAttribute('full_name');
        $teacherAvatar = $teacherUser?->getAttribute('avatar_url');

        $gradeName = $course->getRelationValue('grade')?->getAttribute('name');
        $subjectName = $course->getRelationValue('subject')?->getAttribute('name');

        return [
            'id' => $course->getKey(),
            'title' => $course->getAttribute('title'),
            'description' => $course->getAttribute('description'),

            'price' => $price,
            'discount_price' => $discount,
            'discountPrice' => $discount,

            'thumbnail_url' => $course->getAttribute('thumbnail_url'),
            'image' => $course->getAttribute('thumbnail_url'),

            'teacher_id' => $course->getAttribute('teacher_id'),
            'teacher_name' => $teacherName,
            'teacher_avatar' => $teacherAvatar,
            'teacher' => [
                'name' => $teacherName,
                'image' => $teacherAvatar,
            ],

            'grade_id' => $course->getAttribute('grade_id'),
            'grade_name' => $gradeName,
            'grade' => $gradeName,

            'subject_id' => $course->getAttribute('subject_id'),
            'subject_name' => $subjectName,
            'subject' => $subjectName,

            'is_published' => (bool) $course->getAttribute('is_published'),
            'visibility' => $course->getAttribute('visibility'),
            'isFree' => $price <= 0,

            'created_at' => $course->getAttribute('created_at'),
            'updated_at' => $course->getAttribute('updated_at'),
        ];
    }
}
