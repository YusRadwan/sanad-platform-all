<?php

declare(strict_types=1);

namespace App\Actions\Lesson;

use App\Models\ContentItem;
use App\Models\LessonProgress;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\VideoWatchSession;

/**
 * "إحصائيات المشاهدات" — one video's full watch report for its owning
 * instructor. The teacher-facing twin of BuildExamStatisticsReport, same
 * shape and same reasoning, read-only.
 *
 * "نسبة المشاهدة" (watch percentage) is `progress_seconds / video.duration`,
 * clamped to 100 — `duration` lives on App\Models\Video, not on
 * `lesson_progress` itself, so a video with no stored duration (legacy/
 * external upload) falls back to `lesson_progress.completed` alone: 100%
 * if completed, 0% otherwise, rather than a divide-by-zero or a fabricated
 * percentage.
 *
 * "لم يشاهدوا" (didn't watch) — every student with a currently ACTIVE
 * `Subscription` to the video's course who has no `lesson_progress` row at
 * all. Same union-not-sum `total_students` reasoning as the exam report:
 * a student who watched and later unsubscribed still appears in "المشاهدون".
 */
final class BuildVideoWatchStatisticsReport
{
    /** @return array<string, mixed> */
    public function __invoke(ContentItem $item): array
    {
        $courseId = (string) $item->getAttribute('course_id');
        $duration = (int) ($item->video?->duration ?? 0);

        $enrolledIds = Subscription::query()
            ->where('course_id', $courseId)
            ->active()
            ->pluck('student_id')
            ->map(fn ($id): string => (string) $id)
            ->unique();

        $progressByStudent = LessonProgress::query()
            ->where('content_item_id', $item->getKey())
            ->get()
            ->keyBy(fn (LessonProgress $p): string => (string) $p->getAttribute('student_id'));

        $sessionsByStudent = VideoWatchSession::query()
            ->where('content_item_id', $item->getKey())
            ->orderByDesc('started_at')
            ->get()
            ->groupBy(fn (VideoWatchSession $session): string => (string) $session->getAttribute('student_id'));

        // A valid watch session also counts as a view if a progress heartbeat
        // was interrupted before lesson_progress could be written.
        $watchedIds = $progressByStudent->keys()->merge($sessionsByStudent->keys())->unique();

        $students = Student::query()
            ->with('user')
            ->whereIn('user_id', $enrolledIds->merge($watchedIds)->unique()->values())
            ->get()
            ->keyBy(fn (Student $s): string => (string) $s->getKey());

        $watched = $watchedIds->map(function (string $studentId) use ($progressByStudent, $sessionsByStudent, $students, $duration): array {
            $progress = $progressByStudent->get($studentId);
            $sessions = $sessionsByStudent->get($studentId, collect());
            $student = $students->get($studentId);
            $seconds = (int) ($progress?->getAttribute('progress_seconds') ?? 0);
            $completed = (bool) ($progress?->getAttribute('completed') ?? false);
            $sessionSeconds = (int) $sessions->sum('watched_seconds');

            $percent = $duration > 0
                ? min(100.0, round(($seconds / $duration) * 100, 1))
                : ($completed ? 100.0 : 0.0);

            $lastActivity = $sessions->first()?->getAttribute('last_activity_at')
                ?? $progress?->getAttribute('last_watched_at');

            return [
                'student_id' => $studentId,
                'name' => $student?->user?->full_name,
                'phone' => $student?->phone,
                'parent_phone' => $student?->parent_phone,
                'grade_level' => $student?->grade_level,
                'watched_seconds' => $seconds,
                'watch_percent' => $percent,
                'completed' => $completed,
                'last_watched_at' => $lastActivity?->format('Y-m-d H:i:s'),
                'sessions_count' => $sessions->count(),
                'session_watched_seconds' => $sessionSeconds,
                'sessions' => $sessions->map(fn (VideoWatchSession $session): array => [
                    'id' => (string) $session->getKey(),
                    'watched_seconds' => (int) $session->getAttribute('watched_seconds'),
                    'started_at' => $session->getAttribute('started_at')?->format('Y-m-d H:i:s'),
                    'last_activity_at' => $session->getAttribute('last_activity_at')?->format('Y-m-d H:i:s'),
                ])->values()->all(),
            ];
        })->values();

        $notWatched = $enrolledIds->diff($watchedIds)->map(function (string $studentId) use ($students): array {
            $student = $students->get($studentId);

            return [
                'student_id' => $studentId,
                'name' => $student?->user?->full_name,
                'phone' => $student?->phone,
                'parent_phone' => $student?->parent_phone,
                'grade_level' => $student?->grade_level,
            ];
        })->values();

        $watchedCount = $watchedIds->count();
        $notWatchedCount = $enrolledIds->diff($watchedIds)->count();
        $totalStudents = $enrolledIds->merge($watchedIds)->unique()->count();

        $percents = $watched->pluck('watch_percent');
        $averagePercent = $percents->isNotEmpty() ? round((float) $percents->avg(), 1) : 0.0;

        $completedCount = $watched->filter(fn (array $r): bool => $r['completed'])->count();
        $completionRate = $watchedCount > 0 ? round(($completedCount / $watchedCount) * 100, 1) : null;

        return [
            'video' => [
                'id' => (string) $item->getKey(),
                'title' => $item->getAttribute('title'),
                'duration_seconds' => $duration,
            ],
            'stats' => [
                'average_watch_percent' => $averagePercent,
                'not_watched_count' => $notWatchedCount,
                'watched_count' => $watchedCount,
                'total_students' => $totalStudents,
                'completed_count' => $completedCount,
                'completion_rate_percent' => $completionRate,
                'total_watch_sessions' => $sessionsByStudent->flatten()->count(),
                'total_session_watch_seconds' => (int) $sessionsByStudent->flatten()->sum('watched_seconds'),
            ],
            'watched' => $watched->all(),
            'not_watched' => $notWatched->all(),
        ];
    }
}
