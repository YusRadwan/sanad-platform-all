<?php

declare(strict_types=1);

namespace App\Actions\Course;

use App\Http\Requests\Course\UpdateCourseRequest;
use App\Models\Course;
use Illuminate\Support\Facades\DB;

/**
 * PUT /api/v1/courses/{id}.
 *
 * Ownership was already settled by UpdateCourseRequest::authorize(), which runs
 * before validation — see that class for why the order is a contract and not a
 * preference. By the time this action is entered the caller is known to be the
 * owner or an admin.
 *
 * ┌── AN EMPTY PAYLOAD IS A NO-OP THAT STILL ANSWERS 200. ───────────────────┐
 * │ backend/CourseRepository::update() opens with `if (empty($fields)) return;`│
 * │ and the service still returns the (unchanged) course with the success    │
 * │ message. Eloquent reproduces this for free: with no attribute dirty,     │
 * │ save() issues no UPDATE at all — so `updated_at` is not touched either,  │
 * │ matching a legacy path that never reached its `touchUpdatedAt: true`.    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * DB::transaction per approved decision #3 — see CreateCourse's docblock.
 */
final class UpdateCourse
{
    public function __construct(private readonly ApplyCourseAttributes $apply)
    {
    }

    public function __invoke(UpdateCourseRequest $request, Course $course): Course
    {
        return DB::transaction(function () use ($request, $course): Course {
            ($this->apply)($course, $request);

            $course->save();

            return $course;
        });
    }
}
