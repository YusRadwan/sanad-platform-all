<?php

declare(strict_types=1);

namespace App\Actions\Teacher;

use App\Models\Course;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/teachers/statistics — the CALLING instructor's own dashboard.
 *
 * Port of backend/TeacherService::statistics() + the three
 * TeacherRepository queries behind it.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE TEACHER IS THE SESSION USER. THERE IS NO ID PARAMETER, AND NONE MAY │
 * │ BE ADDED.                                                                │
 * │                                                                          │
 * │ This action takes a User object that the controller reads from           │
 * │ $request->user(). It does not take a string id, so there is no signature │
 * │ into which a `?teacher_id=` query parameter could be threaded "for admin │
 * │ flexibility" — the type is the guard. Admins are deliberately not        │
 * │ special-cased: this is the instructor's OWN dashboard                    │
 * │ (InstructorStatistics.tsx); platform-wide numbers live behind            │
 * │ GET /admin/dashboard.                                                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ EVERY "IS SUBSCRIBED" TEST HERE GOES THROUGH                            │
 * │ Subscription::scopeActive(). ZERO EXCEPTIONS.                           │
 * │                                                                          │
 * │ backend/ wrote the predicate inline THREE times in this one feature —   │
 * │ once per query below — each an independent chance to drift and either    │
 * │ over- or under-count a teacher's students. That is three of the six      │
 * │ duplicates Stage 3's canonical scope exists to have ended.               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * DRAFTS ARE COUNTED. See Course::scopeOwnedBy()'s docblock: ownership, not
 * visibility, is the right question for an owner's own statistics.
 *
 * Response shape, dictated by InstructorStatistics.tsx:
 *   { stats: { summary: {total_students, total_courses},
 *              governorateStats: [{name, count}],
 *              courses: [{id, title, grade_name, enrollment_count}] } }
 */
final class TeacherStatistics
{
    /** @return array{stats: array<string, mixed>} */
    public function __invoke(User $instructor): array
    {
        return [
            'stats' => [
                'summary' => [
                    'total_courses' => $this->totalCourses($instructor),
                    'total_students' => $this->totalStudents($instructor),
                ],
                'governorateStats' => $this->governorateStats($instructor),
                'courses' => $this->courseStats($instructor),
            ],
        ];
    }

    private function totalCourses(User $instructor): int
    {
        return Course::query()->ownedBy($instructor)->count();
    }

    /**
     * DISTINCT students holding an active, unexpired subscription to ANY of
     * this teacher's courses — so a student enrolled in three of them counts
     * once.
     *
     * The DISTINCT is on `student_id`, not on the subscription row: the table's
     * UNIQUE(student_id, course_id) means one row per pair, and a student with
     * three enrollments is three rows.
     */
    private function totalStudents(User $instructor): int
    {
        return Subscription::query()
            ->active()
            ->whereHas('course', fn ($q) => $q->ownedBy($instructor))
            ->distinct()
            ->count('student_id');
    }

    /**
     * Pie-chart data: [{name, count}] per governorate.
     *
     * A join is used rather than whereHas because the governorate NAME has to
     * come back in the result set — an existence check cannot select from the
     * joined table. The `active()` scope's two conditions reference `status`
     * and `expires_at`, neither of which exists on `courses` or `students`, so
     * they stay unambiguous across this join; the join columns are qualified
     * anyway so a future column with either name cannot silently change the
     * meaning of this query.
     *
     * @return list<array{name: string|null, count: int}>
     */
    private function governorateStats(User $instructor): array
    {
        return Subscription::query()
            ->active()
            ->join('courses', 'courses.id', '=', 'subscriptions.course_id')
            ->join('students', 'students.user_id', '=', 'subscriptions.student_id')
            ->where('courses.teacher_id', $instructor->getKey())
            ->whereNull('courses.deleted_at')
            ->groupBy('students.governorate')
            ->orderByDesc('count')
            ->select([
                'students.governorate as name',
                DB::raw('COUNT(DISTINCT subscriptions.student_id) as count'),
            ])
            ->get()
            ->map(static fn ($row): array => [
                'name' => $row->name,
                'count' => (int) $row->count,
            ])
            ->all();
    }

    /**
     * Per-course enrollment table: [{id, title, grade_name, enrollment_count}].
     *
     * withCount + the canonical scope replaces backend/'s
     * `COUNT(DISTINCT CASE WHEN <inlined predicate> THEN s.student_id END)`.
     * The DISTINCT is dropped deliberately and safely: subscriptions carries
     * UNIQUE(student_id, course_id), so within ONE course a student cannot
     * appear twice and COUNT(*) equals COUNT(DISTINCT student_id) by
     * construction. Keeping the DISTINCT would have meant keeping the inlined
     * predicate too, since a CASE expression cannot call a scope.
     *
     * Courses with ZERO enrollments still appear — withCount is a correlated
     * subquery, not a join, so there is no row to lose.
     *
     * @return list<array{id: string, title: string, grade_name: string|null, enrollment_count: int}>
     */
    private function courseStats(User $instructor): array
    {
        return Course::query()
            ->ownedBy($instructor)
            ->select([
                'courses.id',
                'courses.title',
                'courses.created_at',
                'courses.deleted_at',
                'grades.name as grade_name',
            ])
            ->leftJoin('grades', 'grades.id', '=', 'courses.grade_id')
            ->withCount([
                'subscriptions as enrollment_count' => fn ($q) => $q->active(),
            ])
            ->orderByDesc('enrollment_count')
            ->orderByDesc('courses.created_at')
            ->get()
            ->map(static fn (Course $course): array => [
                'id' => $course->getKey(),
                'title' => $course->getAttribute('title'),
                'grade_name' => $course->getAttribute('grade_name'),
                'enrollment_count' => (int) $course->getAttribute('enrollment_count'),
            ])
            ->all();
    }
}
