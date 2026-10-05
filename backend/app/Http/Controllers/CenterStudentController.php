<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Center\JoinCenter;
use App\Http\Requests\Center\JoinCenterRequest;
use App\Http\Resources\CourseResource;
use App\Http\Responses\ApiResponse;
use App\Models\CenterMembership;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "طالب سنتر" — join a teacher's center by code, and list every joined
 * teacher's center courses, EACH TEACHER IN ITS OWN GROUP.
 *
 * ┌── `my-centers` NEVER MERGES TWO TEACHERS' COURSES INTO ONE LIST. ────────┐
 * │ The response is an ARRAY OF {teacher, courses} objects, one per          │
 * │ membership — never a single flat course list. This is section 4 of the   │
 * │ spec's explicit requirement, and it is structural here, not a frontend    │
 * │ rendering choice: a student holding codes from Teacher A and Teacher B    │
 * │ gets two separate entries, and nothing in this payload lets their         │
 * │ courses be confused for one another.                                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class CenterStudentController extends Controller
{
    public function join(JoinCenterRequest $request, JoinCenter $join): JsonResponse
    {
        /** @var User $student */
        $student = $request->user();

        $membership = $join($request->code(), $student);
        $membership->loadMissing('teacher.user');

        return ApiResponse::success([
            'teacher' => [
                'id' => (string) $membership->teacher_id,
                'name' => $membership->teacher?->user?->full_name,
                'avatar_url' => $membership->teacher?->user?->avatar_url,
            ],
        ], 'تم الانضمام إلى سنتر المدرس بنجاح');
    }

    public function myCenters(Request $request): JsonResponse
    {
        /** @var User $student */
        $student = $request->user();

        $memberships = CenterMembership::query()
            ->where('student_id', $student->getKey())
            ->with('teacher.user')
            ->orderBy('joined_at')
            ->get();

        $result = $memberships->map(function (CenterMembership $membership) use ($student): array {
            // Eager-loads the same three relations (teacher.user, grade,
            // subject) every OTHER route feeding CourseCard already loads
            // (CourseController::withCourseRelations) — CourseResource reads
            // them directly, not via whenLoaded(), so skipping this turns a
            // missing grade/subject into a silently blank card instead of an
            // error. Full CourseResource shape (not a hand-picked subset) is
            // what lets the student page render these with the SAME
            // CourseCard component the homepage uses.
            $courses = Course::query()
                ->where('teacher_id', $membership->teacher_id)
                ->where('is_published', true)
                ->centerAccessibleBy($student)
                ->with(['teacher.user', 'grade', 'subject'])
                ->get();

            return [
                'teacher' => [
                    'id' => (string) $membership->teacher_id,
                    'name' => $membership->teacher?->user?->full_name,
                    'avatar_url' => $membership->teacher?->user?->avatar_url,
                ],
                'courses' => CourseResource::collection($courses),
            ];
        })->values()->all();

        return ApiResponse::success(['centers' => $result]);
    }
}
