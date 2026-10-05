<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CenterAttendance;
use App\Models\CenterMembership;
use App\Models\CenterSession;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\LessonProgress;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * GET /center/sessions/{id} — full session detail: group, attached exams and
 * videos, live attendance with per-exam scores and per-video watch
 * percentages, and the total/present/absent counts the "إغلاق الحصة"
 * confirmation modal renders directly, with no second request. Everything
 * is read live from `exam_attempts`/`lesson_progress` — nothing is
 * duplicated onto the Center System's own tables.
 */
final class CenterSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CenterSession $session */
        $session = $this->resource;

        $exams = $session->exams()->with('contentItem')->get();
        $videos = $session->videos()->with('contentItem')->get();
        $attendances = $session->attendances()->with('student.user')->orderByDesc('marked_at')->get();
        $total = $session->group?->students()->count() ?? 0;
        // A "حصة تعويضية" (is_makeup) attendee is deliberately NOT part of
        // this group's roster (MarkAttendance skips the center_group_students
        // insert for them), so counting them here would let `present` exceed
        // `total` and silently hide real absences under the `max(0, …)`
        // floor below. Only roster members count toward present/absent;
        // makeup check-ins are reported separately.
        $present = $attendances->where('is_makeup', false)->count();
        $makeupPresent = $attendances->where('is_makeup', true)->count();

        /*
         * One bulk query for every (video, attendee) pair rather than N+1 —
         * mirrors the "no per-row query" discipline the exam-score loop
         * already followed via ExamAttempt::latestFor(), just batched here
         * since there is no ready-made single-pair helper on LessonProgress.
         */
        $videoIds = $videos->pluck('content_item_id')->all();
        $studentIds = $attendances->pluck('student_id')->all();

        $progressByPair = $videoIds !== [] && $studentIds !== []
            ? LessonProgress::query()
                ->whereIn('content_item_id', $videoIds)
                ->whereIn('student_id', $studentIds)
                ->get()
                ->keyBy(fn (LessonProgress $p) => $p->student_id . '|' . $p->content_item_id)
            : collect();

        /*
         * "كود السنتر" per attendee — the CenterMembership::code() relation,
         * same source decision 10.28 already uses for the students report,
         * NOT students.student_code (see that decision's docblock).
         */
        $centerCodeByStudent = $studentIds !== []
            ? CenterMembership::query()
                ->whereIn('student_id', $studentIds)
                ->where('teacher_id', $session->teacher_id)
                ->with('code')
                ->get()
                ->keyBy(fn (CenterMembership $m) => (string) $m->student_id)
            : collect();

        /*
         * "المجموعة" per attendee — their HOME group (earliest center_group_
         * students row for this teacher), not necessarily this session's own
         * group: a "حصة تعويضية" (is_makeup) attendee's real group is exactly
         * what this column exists to surface.
         */
        $homeGroupByStudent = $studentIds !== []
            ? DB::table('center_group_students')
                ->join('center_groups', 'center_groups.id', '=', 'center_group_students.center_group_id')
                ->whereIn('center_group_students.student_id', $studentIds)
                ->where('center_groups.teacher_id', $session->teacher_id)
                ->orderBy('center_group_students.joined_at')
                ->get(['center_group_students.student_id', 'center_groups.group_name', 'center_groups.center_name'])
                ->groupBy('student_id')
                ->map(fn ($rows) => $rows->first())
            : collect();

        return [
            'id' => (string) $session->getKey(),
            'status' => $session->status,
            'session_date' => $session->session_date?->format('Y-m-d'),
            'opened_at' => $session->opened_at?->format('Y-m-d H:i:s'),
            'closed_at' => $session->closed_at?->format('Y-m-d H:i:s'),
            'group' => [
                'id' => (string) $session->center_group_id,
                'center_name' => $session->group?->center_name,
                'grade_label' => $session->group?->grade_label,
                'group_name' => $session->group?->group_name,
                'schedule_text' => $session->group?->schedule_text,
            ],
            'exams' => $exams->map(fn (Exam $exam): array => [
                'id' => (string) $exam->getKey(),
                'title' => $exam->contentItem?->title,
            ])->values()->all(),
            'videos' => $videos->map(fn (Video $video): array => [
                'id' => (string) $video->getKey(),
                'title' => $video->contentItem?->title,
            ])->values()->all(),
            'total' => $total,
            'present' => $present,
            'absent' => max(0, $total - $present),
            'makeup_present' => $makeupPresent,
            'attendances' => $attendances->map(function (CenterAttendance $attendance) use ($exams, $videos, $progressByPair, $centerCodeByStudent, $homeGroupByStudent): array {
                $studentId = (string) $attendance->student_id;
                $homeGroup = $homeGroupByStudent->get($studentId);
                $scores = $exams->mapWithKeys(function (Exam $exam) use ($attendance): array {
                    $attempt = ExamAttempt::latestFor($exam->getKey(), (string) $attendance->student_id);
                    $taken = $attempt !== null && $attempt->submitted_at !== null;

                    return [
                        (string) $exam->getKey() => [
                            'taken' => $taken,
                            'score' => $taken ? (float) $attempt->score : null,
                        ],
                    ];
                });

                $watch = $videos->mapWithKeys(function (Video $video) use ($attendance, $progressByPair): array {
                    $key = $attendance->student_id . '|' . $video->content_item_id;
                    /** @var LessonProgress|null $progress */
                    $progress = $progressByPair->get($key);

                    $duration = $video->duration;
                    $percent = null;

                    if ($progress !== null && $duration !== null && $duration > 0) {
                        $percent = (int) min(100, round(($progress->progress_seconds / $duration) * 100));
                    }

                    return [
                        (string) $video->getKey() => [
                            'watched' => $progress !== null,
                            'percent' => $percent,
                        ],
                    ];
                });

                return [
                    'attendance_id' => (string) $attendance->getKey(),
                    'student_id' => (string) $attendance->student_id,
                    'name' => $attendance->student?->user?->full_name,
                    'student_code' => $attendance->student?->student_code,
                    'phone' => $attendance->student?->phone,
                    'center_code' => $centerCodeByStudent->get($studentId)?->code?->code,
                    'group_name' => $homeGroup?->group_name,
                    'marked_at' => $attendance->marked_at?->format('Y-m-d H:i:s'),
                    'note' => $attendance->note,
                    'is_makeup' => (bool) $attendance->is_makeup,
                    'exam_scores' => $scores,
                    'video_progress' => $watch,
                ];
            })->values()->all(),
        ];
    }
}
