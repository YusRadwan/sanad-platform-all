<?php

declare(strict_types=1);

namespace App\Actions\Student;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/students/watched-videos — the CALLING student's own watch
 * history. Backs the "فيديوهاتي" card on StudentStatistics.tsx and the
 * StudentWatchedVideos.tsx detail page it links to.
 *
 * IDENTITY FROM THE SESSION ONLY, same as StudentDashboard — no id is ever
 * read from the request.
 *
 * WHOLE-ACCOUNT, NOT SUBSCRIPTION-SCOPED. StudentDashboard::stats() already
 * establishes the precedent for `exams_taken`: "a student whose subscription
 * lapsed still took those exams." A student's watch history is the same
 * kind of fact — it happened, and it stays theirs even if the subscription
 * that let them watch it has since expired. Every row here comes from a real
 * `lesson_progress` record the student themselves created; nothing is
 * filtered back out by current subscription status.
 *
 * ONLY ROWS WITH SOME PROGRESS ARE RETURNED. `lesson_progress` only ever
 * gets a row once a student has actually started a video (see
 * SaveLessonProgress) — there is no "not started" row to exclude, so this is
 * genuinely "videos the student has touched," not a padded list of every
 * video that exists.
 */
final class StudentWatchedVideos
{
    /** @return list<array<string, mixed>> */
    public function __invoke(User $student): array
    {
        $studentId = (string) $student->getKey();

        return DB::table('lesson_progress')
            ->join('content_items', 'content_items.id', '=', 'lesson_progress.content_item_id')
            ->join('courses', 'courses.id', '=', 'content_items.course_id')
            ->where('lesson_progress.student_id', $studentId)
            ->orderByDesc('lesson_progress.last_watched_at')
            ->select([
                'content_items.id as content_item_id',
                'content_items.title as title',
                'courses.id as course_id',
                'courses.title as course_title',
                'lesson_progress.progress_seconds',
                'lesson_progress.completed',
                'lesson_progress.last_watched_at',
            ])
            ->get()
            ->map(static fn (object $row): array => [
                'content_item_id' => (string) $row->content_item_id,
                'title' => $row->title,
                'course_id' => (string) $row->course_id,
                'course_title' => $row->course_title,
                'progress_seconds' => (int) $row->progress_seconds,
                'completed' => (bool) $row->completed,
                'last_watched_at' => $row->last_watched_at,
            ])
            ->all();
    }
}
