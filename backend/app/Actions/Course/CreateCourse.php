<?php

declare(strict_types=1);

namespace App\Actions\Course;

use App\Enums\UserRole;
use App\Http\Requests\Course\StoreCourseRequest;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/courses.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `teacher_id` IS FORCED FROM THE SESSION FOR AN INSTRUCTOR — THE REQUEST  ║
 * ║ BODY IS NOT CONSULTED AT ALL ON THAT BRANCH.                             ║
 * ║                                                                          ║
 * ║ backend/CourseService::create():                                         ║
 * ║   $teacherId = $role === 'admin' ? ($data['teacher_id'] ?? $userId)      ║
 * ║                                  : $userId;                              ║
 * ║                                                                          ║
 * ║ The property that matters is not "the value is validated" but "there is  ║
 * ║ no code path on which an instructor's `teacher_id` is read" — the same   ║
 * ║ guarantee Stage 4 gives GET /teachers/statistics. A caller may post      ║
 * ║ `teacher_id: <someone else>` and it has literally nowhere to land.       ║
 * ║ StoreCourseRequest reinforces it by not even declaring the rule for a    ║
 * ║ non-admin, so the key cannot appear in validated() either.               ║
 * ║                                                                          ║
 * ║ Admins MAY name a teacher — creating a course on an instructor's behalf  ║
 * ║ is a real admin-console flow — and default to themselves when they do    ║
 * ║ not. (An admin defaulting to themselves then fails the                    ║
 * ║ `fk_courses_teacher` FK, since an admin has no `teachers` row; that is   ║
 * ║ backend/'s behaviour too and is left alone rather than silently          ║
 * ║ redefined.)                                                              ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── DB::transaction — APPROVED DECISION #3 OF STAGE 5 ─────────────────────┐
 * │ backend/ runs none of these writes in a transaction. Wrapping them is an │
 * │ approved SAFETY improvement, not a behaviour change: a successful        │
 * │ request is byte-identical, and a failing one leaves no half-written row  │
 * │ instead of leaving one.                                                  │
 * │                                                                          │
 * │ Honest note on THIS action specifically: the course insert is a single   │
 * │ statement, so the transaction buys little today — its value is that      │
 * │ Session B will hang content items off the same create path, and a        │
 * │ transaction added later is a transaction someone has to remember. The    │
 * │ reorder and the group operations are the cases where it earns its keep   │
 * │ immediately.                                                             │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class CreateCourse
{
    public function __construct(private readonly ApplyCourseAttributes $apply)
    {
    }

    public function __invoke(StoreCourseRequest $request, User $actor): Course
    {
        return DB::transaction(function () use ($request, $actor): Course {
            $course = new Course();

            // Defaults the schema supplies but a brand-new model does not, so
            // the created row is complete before the semantics writer runs.
            $course->setAttribute('price', 0);
            $course->setAttribute('is_published', false);

            $course->setAttribute('teacher_id', $this->ownerFor($request, $actor));

            ($this->apply)($course, $request);

            $course->save();

            return $course;
        });
    }

    /** @see the class docblock — the whole security argument lives there. */
    private function ownerFor(StoreCourseRequest $request, User $actor): string
    {
        if ($actor->role === UserRole::Admin) {
            return (string) ($request->input('teacher_id') ?: $actor->getKey());
        }

        return (string) $actor->getKey();
    }
}
