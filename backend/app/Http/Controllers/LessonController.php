<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Lesson\SaveLessonProgress;
use App\Enums\UserRole;
use App\Http\Resources\LessonProgressResource;
use App\Http\Responses\ApiResponse;
use App\Models\ContentItem;
use App\Models\LessonProgress;
use App\Models\User;
use App\Support\LessonAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lessons — freeze-table rows 27, 28 and 29. Backs VideoPlayerPage.tsx.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ ALL THREE ROUTES ARE BEHIND `auth`. THEY ARE THE **NOT**-DEGRADE HALF   ║
 * ║ OF SESSION C, AND THE CONTRAST WITH ContentItemController IS THE POINT. ║
 * ║                                                                          ║
 * ║ The public reads hand a logged-out visitor a REDUCED payload. These      ║
 * ║ hand out `videos.video_url` — the paid product itself — so there is no   ║
 * ║ reduced shape to degrade to and a refusal is the only correct answer.    ║
 * ║ Row 27's whole reason for existing is that without it, knowing a         ║
 * ║ content-item UUID would hand out the stream URL.                         ║
 * ║                                                                          ║
 * ║ Consequently `Gate::denies` + ForbiddenException here, where the public  ║
 * ║ reads use `Gate::forUser(...)->allows` + a Resource choice. Two          ║
 * ║ different idioms because they are two different contracts, not because   ║
 * ║ the style drifted.                                                       ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * The refusal ladder is identical on all three and lives in
 * App\Support\LessonAccess: 404 'Lesson not found' for a missing OR non-video
 * id, then 404 'Course not found' for an orphan, then 403 'You do not have
 * access to this lesson'.
 */
final class LessonController extends Controller
{
    /**
     * GET /api/v1/lessons/{id}/video. Row 27.
     *
     * ┌── THE RESPONSE IS camelCase, AND ONLY THIS ROUTE IS. ────────────────┐
     * │ VideoPlayerPage.tsx reads `videoData.videoUrl`, `.thumbnailUrl` and  │
     * │ `.courseId`. Every other content payload in the application ships    │
     * │ snake_case (`video_url`, `thumbnail_url`, `course_id`) through       │
     * │ ContentItemResource. Both are live; the inconsistency is backend/'s  │
     * │ and is ported rather than harmonised, because harmonising it breaks  │
     * │ the player.                                                          │
     * │                                                                      │
     * │ It is NOT built from ContentItemResource for exactly that reason —   │
     * │ and for a second one: this shape is a hand-picked SEVEN keys, not    │
     * │ the item's full detail. `is_hidden`, `order_index`, `section_id` and │
     * │ the rest are simply not part of what the player asked for.           │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * No Resource class: the shape is used once, by one consumer, and a
     * fourth Resource whose only job is to rename three keys for one endpoint
     * would be indirection without a second call site to justify it.
     */
    public function video(Request $request, string $id): JsonResponse
    {
        [$user, $lesson] = $this->resolve($request, $id);

        $video = $lesson->video;

        return ApiResponse::success([
            'video' => [
                'id' => $lesson->getKey(),
                'title' => $lesson->getAttribute('title'),
                'description' => $lesson->getAttribute('description'),
                // camelCase — see the docblock.
                'videoUrl' => $video?->getAttribute('video_url'),
                'thumbnailUrl' => $video?->getAttribute('thumbnail_url'),
                'courseId' => $lesson->getAttribute('course_id'),
                'duration' => $video?->getAttribute('duration') === null
                    ? null
                    : (int) $video->getAttribute('duration'),
                'provider' => $video?->getAttribute('provider'),
                'isPreview' => (bool) $video?->getAttribute('is_preview'),
            ],
        ]);
    }

    /**
     * GET /api/v1/lessons/{id}/progress. Row 28.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ A NON-STUDENT GETS A ZEROED RECORD, **NOT** AN ERROR — AND THE       ║
     * ║ DATABASE IS NOT EVEN CONSULTED FOR THEM.                             ║
     * ║                                                                      ║
     * ║ `lesson_progress.student_id` is an FK into `students.user_id`. An    ║
     * ║ admin or an instructor has no such row and never can, so there is    ║
     * ║ genuinely nothing to look up — the query is skipped rather than run  ║
     * ║ and found empty.                                                     ║
     * ║                                                                      ║
     * ║ It answers 200 because VideoPlayerPage loads the video and its       ║
     * ║ progress TOGETHER: an error here would blank the whole player for an ║
     * ║ instructor reviewing their own lesson, which is a legitimate and     ║
     * ║ common thing to do. The zeroed shape is the same one a student who   ║
     * ║ has never opened the lesson receives, and the client neither can nor ║
     * ║ needs to tell the two apart.                                         ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * PROGRESS IS READ BY SESSION IDENTITY ONLY. No id is taken from the
     * request, so there is no shape in which one student can name another —
     * `?student_id=` has no code path that observes it, which is a stronger
     * guarantee than validating and rejecting it.
     */
    public function progress(Request $request, string $id): JsonResponse
    {
        [$user, $lesson] = $this->resolve($request, $id);

        $row = $user->role === UserRole::Student
            ? LessonProgress::findFor((string) $user->getKey(), (string) $lesson->getKey())
            : null;

        return ApiResponse::success(['progress' => LessonProgressResource::forRow($row)]);
    }

    /**
     * POST /api/v1/lessons/{id}/progress. Row 29.
     *
     * The no-op-for-non-students rule, the clamping and the sticky-completion
     * upsert all live in App\Actions\Lesson\SaveLessonProgress and
     * App\Models\LessonProgress::recordFor(). Read the Action's banner before
     * changing anything here: it carries THE THIRD DOCUMENTED `Gate::before`
     * ESCAPE HATCH, and the reason the refusal cannot live in a Policy.
     *
     * The success message is backend/'s literal 'تم حفظ التقدم' — and it is
     * returned for the no-op too, because the caller did nothing wrong.
     *
     * NO FormRequest. There is nothing to validate: both fields are clamped
     * rather than rejected (no 422 exists on this route at all — see the
     * Action), and the only authorization question is the shared ladder every
     * one of these three routes runs. A Form Request here would be an empty
     * rules() array plus a duplicated authorize().
     */
    public function saveProgress(Request $request, string $id, SaveLessonProgress $save): JsonResponse
    {
        [$user, $lesson] = $this->resolve($request, $id);

        $row = $save($user, $lesson, $request->all());

        return ApiResponse::success(
            ['progress' => LessonProgressResource::forRow($row)],
            'تم حفظ التقدم'
        );
    }

    /**
     * The ladder all three routes share, in backend/'s order.
     *
     * ┌── THE TYPE CHECK RUNS **BEFORE** THE ACCESS CHECK. ──────────────────┐
     * │ backend/ calls requireVideoItem() first in all three methods. The    │
     * │ order is observable and is the safer one: a note's id answers 404    │
     * │ 'Lesson not found' for everybody, so the status code cannot be used  │
     * │ to distinguish "not a video" from "not entitled" — and therefore     │
     * │ cannot be used to probe an id's type without authorization.          │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * `$request->user()` is non-null by the route's `auth` middleware; the
     * cast is the belt to that brace and keeps ContentItemPolicy::watch()'s
     * non-nullable `User` honest without a nullable hop.
     *
     * @return array{0: User, 1: ContentItem}
     */
    private function resolve(Request $request, string $id): array
    {
        $lesson = LessonAccess::findVideoOrFail($id);

        /** @var User $user */
        $user = $request->user();

        LessonAccess::assertWatchable($user, $lesson);

        return [$user, $lesson];
    }
}
