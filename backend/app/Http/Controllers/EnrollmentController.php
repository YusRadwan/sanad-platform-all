<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Student\ListStudentsByInstructor;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/enrollments/instructor-students — backs
 * enrollmentApiService.getInstructorStudents(), consumed by
 * SubscriptionsManagement.tsx and AnnouncementsManagement.tsx.
 *
 * KEPT AS ITS OWN CONTROLLER rather than folded into StudentController, for
 * the reason backend/ gave: StudentController is the admin-only
 * /admin/students CRUD surface, whereas this is an INSTRUCTOR-scoped view of
 * enrolments. Merging them would put a `role:instructor` action inside a class
 * whose every other route is `role:admin`, which is exactly the kind of
 * quiet adjacency that later grows into a missing middleware.
 *
 * IDENTITY FROM THE SESSION ONLY. This method reads no request input, so an
 * instructor cannot ask for another instructor's roster and a supplied
 * `?teacher_id=` has no code path that could observe it. The tenant boundary
 * itself lives in Student::scopeEnrolledWith(); see that scope and
 * App\Actions\Student\ListStudentsByInstructor for the subtler half — the
 * per-row AGGREGATES are scoped to this instructor too, not just the row set.
 */
final class EnrollmentController extends Controller
{
    public function instructorStudents(Request $request, ListStudentsByInstructor $list): JsonResponse
    {
        /** @var User $instructor */
        $instructor = $request->user();

        return ApiResponse::success($list($instructor));
    }
}
