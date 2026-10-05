<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Exam\BuildExamStatisticsReport;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Http\Responses\ApiResponse;
use App\Support\AssessmentAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "إحصائيات الامتحانات" — instructor-only, OWNER-ONLY. Deliberately a
 * SEPARATE controller from ExamController rather than a new method on it:
 * that class's own banner is explicit that its every existing route answers
 * one of two narrow questions (a public DEGRADE read, or an enrolled
 * student's own attempt/submission) and warns against "harmonising" new
 * behaviour into it. This is a THIRD, distinct question — "how did every
 * one of MY students do on MY exam" — asked by nobody but the owning
 * instructor, and it is intentionally NOT reachable by `viewQuestions`
 * (owner OR subscriber) the way rows 90-92 are.
 */
final class ExamStatisticsController extends Controller
{
    /**
     * GET /api/v1/exams/{examId}/statistics.
     *
     * Ownership is `manage` on the exam's course — the SAME ability
     * CoursePolicy::manage() answers for every other instructor write
     * surface — not `viewQuestions`, which also admits an active
     * subscriber. A student calling this, even one enrolled in the course,
     * gets the same 403 a foreign instructor would.
     */
    public function show(Request $request, string $examId, BuildExamStatisticsReport $report): JsonResponse
    {
        $exam = AssessmentAccess::findExamOrFail($examId);

        $course = $exam->course
            ?? throw new NotFoundException(AssessmentAccess::COURSE_NOT_FOUND);

        if (Gate::forUser($request->user())->denies('manage', $course)) {
            throw new ForbiddenException(AssessmentAccess::NOT_OWNER_EXAM);
        }

        return ApiResponse::success($report($exam));
    }
}
