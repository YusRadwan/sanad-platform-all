<?php

declare(strict_types=1);

namespace App\Actions\Student;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/students/exam-attempts — the CALLING student's own full exam
 * record. Backs the "امتحاناتي" card on StudentStatistics.tsx and the
 * StudentCompletedExams.tsx detail page it links to.
 *
 * IDENTITY FROM THE SESSION ONLY, same as StudentDashboard — no id is ever
 * read from the request.
 *
 * WHOLE-ACCOUNT, NOT SUBSCRIPTION-SCOPED — same precedent as
 * StudentDashboard::stats()'s `exams_taken`/`average_score`: a student whose
 * subscription has since lapsed still sat that exam, and the record stays
 * theirs.
 *
 * ONLY SUBMITTED ATTEMPTS ARE RETURNED. An attempt with `submitted_at IS
 * NULL` is one the student opened but never finished (or abandoned) — it has
 * no `score` and is not a completed exam, so it does not belong in "سجل
 * الامتحانات". This mirrors the exact predicate StudentDashboard::stats()
 * already uses for `exams_taken`.
 */
final class StudentExamAttempts
{
    /** @return list<array<string, mixed>> */
    public function __invoke(User $student): array
    {
        $studentId = (string) $student->getKey();

        return DB::table('exam_attempts')
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->join('content_items', 'content_items.id', '=', 'exams.content_item_id')
            ->join('courses', 'courses.id', '=', 'content_items.course_id')
            ->where('exam_attempts.student_id', $studentId)
            ->whereNotNull('exam_attempts.submitted_at')
            ->orderByDesc('exam_attempts.submitted_at')
            ->select([
                'exam_attempts.id as attempt_id',
                'exam_attempts.exam_id',
                'content_items.title as exam_title',
                'courses.id as course_id',
                'courses.title as course_title',
                'exam_attempts.attempt_number',
                'exam_attempts.score',
                'exams.total_score',
                'exams.pass_score',
                'exam_attempts.started_at',
                'exam_attempts.submitted_at',
            ])
            ->get()
            ->map(static fn (object $row): array => [
                'attempt_id' => (string) $row->attempt_id,
                'exam_id' => (string) $row->exam_id,
                'exam_title' => $row->exam_title,
                'course_id' => (string) $row->course_id,
                'course_title' => $row->course_title,
                'attempt_number' => (int) $row->attempt_number,
                'score' => $row->score !== null ? (float) $row->score : null,
                'total_score' => $row->total_score !== null ? (float) $row->total_score : null,
                'pass_score' => $row->pass_score !== null ? (float) $row->pass_score : null,
                'started_at' => $row->started_at,
                'submitted_at' => $row->submitted_at,
            ])
            ->all();
    }
}
