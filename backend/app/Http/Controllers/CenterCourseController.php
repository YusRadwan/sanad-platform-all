<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Center\ActivateCenterCourse;
use App\Exceptions\NotFoundException;
use App\Http\Requests\Center\ActivateCenterCourseRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Course;
use App\Models\Exam;
use App\Models\User;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Center System — the course/materials picker behind "اختر الكورس" on the
 * attendance page (decision 10.26). Deliberately scoped to `center`/`both`
 * visibility courses ONLY, per the explicit requirement that a center
 * session can only track material the teacher actually assigned to their
 * center — a platform-only course (and its videos/exams) never appears here.
 */
final class CenterCourseController extends Controller
{
    /** GET /center/courses — the caller's own center-visible courses. */
    public function index(Request $request): JsonResponse
    {
        /** @var User $teacher */
        $teacher = $request->user();

        $courses = Course::query()
            ->where('teacher_id', $teacher->getKey())
            ->whereIn('visibility', [Course::VISIBILITY_CENTER, Course::VISIBILITY_BOTH])
            ->orderBy('title')
            ->get(['id', 'title']);

        return ApiResponse::success([
            'courses' => $courses->map(fn (Course $c) => [
                'id' => (string) $c->getKey(),
                'title' => $c->title,
            ])->values()->all(),
        ]);
    }

    /**
     * GET /center/courses/{id}/materials — the course's own videos and exams,
     * for the session's "اختر أكثر من فيديو / أكثر من امتحان" pickers.
     */
    public function materials(Request $request, string $id): JsonResponse
    {
        /** @var User $teacher */
        $teacher = $request->user();

        $course = Course::query()
            ->where('teacher_id', $teacher->getKey())
            ->whereIn('visibility', [Course::VISIBILITY_CENTER, Course::VISIBILITY_BOTH])
            ->whereKey($id)
            ->first()
            ?? throw new NotFoundException('الكورس غير موجود');

        $videos = Video::query()->forCourse($course->getKey())->with('contentItem')->get();
        $exams = Exam::query()->forCourse($course->getKey())->with('contentItem')->get();

        return ApiResponse::success([
            'videos' => $videos->map(fn (Video $v) => [
                'id' => (string) $v->getKey(),
                'title' => $v->contentItem?->title,
            ])->values()->all(),
            'exams' => $exams->map(fn (Exam $e) => [
                'id' => (string) $e->getKey(),
                'title' => $e->contentItem?->title,
            ])->values()->all(),
        ]);
    }

    /**
     * POST /center/courses/{id}/activate — "تفعيل كورس سنتر". See
     * App\Actions\Center\ActivateCenterCourse for the two mutually-exclusive
     * modes the request body picks between.
     */
    public function activate(ActivateCenterCourseRequest $request, ActivateCenterCourse $activate): JsonResponse
    {
        /** @var User $teacher */
        $teacher = $request->user();

        $result = $activate($request->course(), $teacher, $request->sessionId(), $request->identifier());

        $message = $result['mode'] === 'session'
            ? "تم تفعيل الكورس لـ {$result['granted']} طالب من أصل {$result['total_attendees']} ممن حضروا هذه الحصة"
            : "تم تفعيل الكورس للطالب \"{$result['student_name']}\" بنجاح";

        return ApiResponse::success($result, $message);
    }
}
