<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Center\CloseCenterSession;
use App\Actions\Center\MarkAttendance;
use App\Actions\Center\OpenCenterSession;
use App\Exceptions\NotFoundException;
use App\Http\Requests\Center\AttachCenterExamsRequest;
use App\Http\Requests\Center\AttachCenterVideosRequest;
use App\Http\Requests\Center\MarkAttendanceRequest;
use App\Http\Requests\Center\OpenCenterSessionRequest;
use App\Http\Requests\Center\UpdateAttendanceNoteRequest;
use App\Http\Resources\CenterSessionResource;
use App\Http\Responses\ApiResponse;
use App\Models\CenterAttendance;
use App\Models\CenterSession;
use App\Models\Exam;
use App\Models\User;
use App\Models\Video;
use App\Support\CenterAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "حضور الطلاب" — opening a session, attaching exams, marking/removing
 * attendance by student code/phone/QR, per-attendance notes, and closing.
 * Every mutating action resolves ownership through App\Support\CenterAccess
 * before touching the row — see that class and MarkAttendance's own banner
 * for why this is the isolation boundary the spec's Tests 7-10 exist to prove.
 */
final class CenterSessionController extends Controller
{
    public function store(OpenCenterSessionRequest $request, OpenCenterSession $open): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $session = $open($request->group(), $user, $request->sessionDate());

        return ApiResponse::created(
            ['session' => new CenterSessionResource($session->load('group'))],
            'تم فتح الحصة بنجاح'
        );
    }

    public function attachExams(AttachCenterExamsRequest $request): JsonResponse
    {
        $session = $request->session();

        /*
         * Every exam id must actually belong to THIS teacher — resolved
         * through the same content_item -> course -> teacher_id chain
         * ExamPolicy uses, never trusted from the request alone.
         */
        $examIds = Exam::query()
            ->whereIn('id', $request->examIds())
            ->get()
            ->filter(fn (Exam $exam): bool => (string) $exam->course?->teacher_id === (string) $session->teacher_id)
            ->pluck('id')
            ->all();

        if ($examIds === []) {
            throw ValidationException::withMessages(['exam_ids' => ['لا يوجد امتحان صالح ضمن كورساتك']]);
        }

        $this->attachPivotRows('center_session_exams', 'exam_id', $session->getKey(), $examIds);

        return ApiResponse::success(
            ['session' => new CenterSessionResource($session->fresh('group'))],
            'تم ربط الامتحانات بالحصة'
        );
    }

    public function attachVideos(AttachCenterVideosRequest $request): JsonResponse
    {
        $session = $request->session();

        /*
         * Same ownership chain as attachExams(): a video id must resolve
         * through content_item -> course -> teacher_id to THIS teacher.
         */
        $videoIds = Video::query()
            ->whereIn('content_item_id', $request->videoIds())
            ->get()
            ->filter(fn (Video $video): bool => (string) $video->contentItem?->course?->teacher_id === (string) $session->teacher_id)
            ->pluck('content_item_id')
            ->all();

        if ($videoIds === []) {
            throw ValidationException::withMessages(['video_ids' => ['لا يوجد فيديو صالح ضمن كورساتك']]);
        }

        $this->attachPivotRows('center_session_videos', 'video_content_item_id', $session->getKey(), $videoIds);

        return ApiResponse::success(
            ['session' => new CenterSessionResource($session->fresh('group'))],
            'تم ربط الفيديوهات بالحصة'
        );
    }

    /**
     * Insert-if-missing rows into a `center_session_*` pivot table.
     *
     * NOT `BelongsToMany::syncWithoutDetaching()` — every one of these pivot
     * tables carries its OWN UUID `id` primary key (matching this schema's
     * convention on every other table), which Eloquent's sync never
     * populates. Same fix, same reasoning, as
     * App\Actions\Center\MarkAttendance's `center_group_students` upsert.
     *
     * @param list<string> $relatedIds
     */
    private function attachPivotRows(string $table, string $relatedColumn, string $sessionId, array $relatedIds): void
    {
        DB::transaction(function () use ($table, $relatedColumn, $sessionId, $relatedIds): void {
            $existing = DB::table($table)
                ->where('center_session_id', $sessionId)
                ->whereIn($relatedColumn, $relatedIds)
                ->pluck($relatedColumn)
                ->all();

            $missing = array_diff($relatedIds, $existing);

            foreach ($missing as $relatedId) {
                DB::table($table)->insert([
                    'id' => (string) Str::uuid7(),
                    'center_session_id' => $sessionId,
                    $relatedColumn => $relatedId,
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $session = CenterAccess::findSessionOrFail($id);
        CenterAccess::assertOwnsSession($session);

        return ApiResponse::success(['session' => new CenterSessionResource($session->load('group'))]);
    }

    public function markAttendance(MarkAttendanceRequest $request, MarkAttendance $mark): JsonResponse
    {
        $session = $request->session();

        $mark($session, $request->identifier(), $request->confirmed());

        return ApiResponse::success(
            ['session' => new CenterSessionResource($session->fresh('group'))],
            'تم تسجيل الحضور'
        );
    }

    public function deleteAttendance(Request $request, string $id, string $attendanceId): JsonResponse
    {
        $session = CenterAccess::findSessionOrFail($id);
        CenterAccess::assertOwnsSession($session);

        $attendance = CenterAttendance::query()
            ->where('center_session_id', $session->getKey())
            ->whereKey($attendanceId)
            ->first()
            ?? throw new NotFoundException('سجل الحضور غير موجود');

        $attendance->delete();

        return ApiResponse::success(null, 'تم حذف الحضور');
    }

    public function updateNote(UpdateAttendanceNoteRequest $request): JsonResponse
    {
        $attendance = $request->attendance();
        $attendance->setAttribute('note', $request->note());
        $attendance->save();

        return ApiResponse::success(null, 'تم تحديث الملاحظة');
    }

    public function close(Request $request, string $id, CloseCenterSession $close): JsonResponse
    {
        $session = CenterAccess::findSessionOrFail($id);
        CenterAccess::assertOwnsSession($session);

        $summary = $close($session);

        return ApiResponse::success($summary, 'تم إغلاق الحصة بنجاح');
    }

    /**
     * POST /center/sessions/{id}/reopen — "استعادة حضور الحصة" from the
     * session report. A closed session is otherwise a dead end:
     * MarkAttendance refuses new check-ins once `status = closed` (by
     * design — see that Action's banner), so editing an already-closed
     * session's roster requires explicitly reopening it first, rather than
     * quietly lifting the guard everywhere.
     */
    public function reopen(Request $request, string $id): JsonResponse
    {
        $session = CenterAccess::findSessionOrFail($id);
        CenterAccess::assertOwnsSession($session);

        if ($session->isOpen()) {
            return ApiResponse::success(
                ['session' => new CenterSessionResource($session->load('group'))],
                'الحصة مفتوحة بالفعل'
            );
        }

        $session->forceFill(['status' => CenterSession::STATUS_OPEN, 'closed_at' => null])->save();

        return ApiResponse::success(
            ['session' => new CenterSessionResource($session->load('group'))],
            'تم استعادة الحصة، يمكنك الآن تعديل الحضور'
        );
    }

    /**
     * DELETE /center/sessions/{id} — "حذف الحصة بالكامل" from the session
     * report. A hard delete: `center_attendances`/`center_session_exams`/
     * `center_session_videos` all cascade on the session's own FK (see their
     * migrations), so nothing is left orphaned.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $session = CenterAccess::findSessionOrFail($id);
        CenterAccess::assertOwnsSession($session);

        $session->delete();

        return ApiResponse::success(null, 'تم حذف الحصة بالكامل');
    }
}
