<?php

declare(strict_types=1);

namespace App\Actions\Exam;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Student;
use App\Models\Subscription;

/**
 * "إحصائيات الامتحانات" — one exam's full attempt report for its owning
 * instructor. Read-only; touches no table but `exam_attempts`/`students`/
 * `subscriptions`, none of it written.
 *
 * ┌── "الوقت المستغرق" (time taken) IS DELIBERATELY ABSENT FROM THIS REPORT.┐
 * │ `exam_attempts.started_at` and `.submitted_at` are written with the      │
 * │ SAME `now()` by App\Actions\Exam\SubmitExamAttempt — see ExamAttempt's   │
 * │ own class docblock ("this schema has no separate 'exam opened' event").  │
 * │ A duration therefore cannot be computed from this data at all; showing   │
 * │ one would mean fabricating it, which is worse than omitting the column.  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * "لم يمتحنوا" (didn't attempt) is every student with a currently ACTIVE
 * `Subscription` to the exam's course who has no `exam_attempts` row at all
 * — the same canonical "enrolled" definition every other report in this
 * codebase uses (Subscription::scopeActive()). A student who attempted and
 * later let their subscription lapse still appears in "الممتحنون" (their
 * attempt is a historical fact) but is not double-counted as "didn't
 * attempt" — `total_students` is the UNION of the two populations, not a
 * sum, for exactly that reason.
 *
 * "الحالة" (status) is `null` when the exam has no `pass_score` set — a
 * tri-state, matching ExamController::submit()'s own `passed` field: an
 * exam with no pass mark has not been passed OR failed.
 */
final class BuildExamStatisticsReport
{
    /** @return array<string, mixed> */
    public function __invoke(Exam $exam): array
    {
        $courseId = (string) $exam->course?->getKey();
        $totalScore = (float) ($exam->getAttribute('total_score') ?? 0);
        $rawPassScore = $exam->getAttribute('pass_score');
        $passScore = $rawPassScore !== null ? (float) $rawPassScore : null;

        $enrolledIds = Subscription::query()
            ->where('course_id', $courseId)
            ->active()
            ->pluck('student_id')
            ->map(fn ($id): string => (string) $id)
            ->unique();

        $attemptsByStudent = ExamAttempt::query()
            ->where('exam_id', $exam->getKey())
            ->orderBy('started_at')
            ->get()
            ->groupBy(fn (ExamAttempt $a): string => (string) $a->getAttribute('student_id'));

        $attemptedIds = $attemptsByStudent->keys();

        $students = Student::query()
            ->with('user')
            ->whereIn('user_id', $enrolledIds->merge($attemptedIds)->unique()->values())
            ->get()
            ->keyBy(fn (Student $s): string => (string) $s->getKey());

        $attempted = $attemptedIds->map(function (string $studentId) use ($attemptsByStudent, $students, $totalScore, $passScore): array {
            /** @var \Illuminate\Support\Collection<int, ExamAttempt> $rows */
            $rows = $attemptsByStudent->get($studentId);
            // Ordered by started_at ASC above — the last element is the
            // latest sitting, matching ExamAttempt::latestFor()'s intent.
            $latest = $rows->last();
            $student = $students->get($studentId);

            $score = (float) $latest->getAttribute('score');
            $passed = $passScore !== null ? $score >= $passScore : null;

            return [
                'student_id' => $studentId,
                'name' => $student?->user?->full_name,
                'phone' => $student?->phone,
                'parent_phone' => $student?->parent_phone,
                'grade_level' => $student?->grade_level,
                'score' => $score,
                'total_score' => $totalScore,
                'attempts_count' => $rows->count(),
                'submitted_at' => $latest->getAttribute('submitted_at')?->format('Y-m-d H:i:s'),
                'status' => $passed === null ? null : ($passed ? 'passed' : 'failed'),
            ];
        })->values();

        $notAttended = $enrolledIds->diff($attemptedIds)->map(function (string $studentId) use ($students): array {
            $student = $students->get($studentId);

            return [
                'student_id' => $studentId,
                'name' => $student?->user?->full_name,
                'phone' => $student?->phone,
                'parent_phone' => $student?->parent_phone,
                'grade_level' => $student?->grade_level,
            ];
        })->values();

        $attemptedCount = $attemptedIds->count();
        $notAttemptedCount = $enrolledIds->diff($attemptedIds)->count();
        $totalStudents = $enrolledIds->merge($attemptedIds)->unique()->count();

        $latestScores = $attempted->pluck('score');
        $averagePercent = $totalScore > 0 && $latestScores->isNotEmpty()
            ? round(((float) $latestScores->avg() / $totalScore) * 100, 1)
            : 0.0;

        $highestScore = $attemptsByStudent
            ->flatten()
            ->map(fn (ExamAttempt $a): float => (float) $a->getAttribute('score'))
            ->max() ?? 0.0;

        $passedCount = $attempted->filter(fn (array $r): bool => $r['status'] === 'passed')->count();
        $successRate = $attemptedCount > 0 && $passScore !== null
            ? round(($passedCount / $attemptedCount) * 100, 1)
            : null;

        return [
            'exam' => [
                'id' => (string) $exam->getKey(),
                'title' => $exam->contentItem?->title,
                'total_score' => $totalScore,
                'pass_score' => $passScore,
            ],
            'stats' => [
                'average_score_percent' => $averagePercent,
                'not_attempted_count' => $notAttemptedCount,
                'attempted_count' => $attemptedCount,
                'total_students' => $totalStudents,
                'highest_score' => $highestScore,
                'success_rate_percent' => $successRate,
            ],
            'attempted' => $attempted->all(),
            'not_attempted' => $notAttended->all(),
        ];
    }
}
