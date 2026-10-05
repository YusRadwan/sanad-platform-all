<?php

declare(strict_types=1);

namespace App\Actions\Student;

use App\Http\Resources\StudentResource;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\User;

/**
 * GET /api/v1/enrollments/instructor-students.
 *
 * Every student holding an active, unexpired subscription to any course owned
 * by the CALLING instructor, with that instructor's courses aggregated onto
 * each row. Port of backend/StudentRepository::listByInstructor() +
 * StudentService::listByInstructor().
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE INSTRUCTOR IS THE SESSION USER, AND THE AGGREGATES ARE SCOPED TO    │
 * │ THEM TOO. BOTH HALVES MATTER, AND THE SECOND IS THE EASY ONE TO MISS.   │
 * │                                                                          │
 * │ The obvious bug is returning another teacher's STUDENTS. The subtler one │
 * │ is returning the RIGHT students with the WRONG numbers attached —        │
 * │ `courses_count` and `course_titles` computed across ALL of a student's   │
 * │ enrollments instead of only those with this instructor. A student taking │
 * │ two courses from teacher A and five from teacher B would then show       │
 * │ "7 courses" on A's roster, leaking B's catalogue size and course titles  │
 * │ through an endpoint that passed every "no foreign rows" test.            │
 * │                                                                          │
 * │ Hence the aggregate query below re-applies the teacher filter, and       │
 * │ CrossTeacherIsolationTest asserts the SAME student appears on BOTH       │
 * │ teachers' rosters with DIFFERENT counts — a stronger property than       │
 * │ "zero cross-tenant rows", which the buggy version would also satisfy.    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * TWO QUERIES, NOT ONE, AND NOT N+1:
 *   1. the students themselves, through Student::scopeEnrolledWith();
 *   2. one grouped aggregate for all of them at once.
 * backend/ did it in a single GROUP BY over a four-table join. Splitting it
 * lets the row-level read stay a plain Eloquent query (so StudentResource can
 * present real models with their `user` relation loaded) while the aggregate
 * stays one round trip.
 *
 * GROUP_CONCAT IS THE ONE selectRaw IN THIS FILE. Eloquent has no builder for
 * it, and there is no portable expression that produces a comma-joined,
 * de-duplicated, ordered title list. Everything else — the filters, the join,
 * the grouping — goes through the query builder, and the instructor id is a
 * BOUND parameter, never interpolated.
 */
final class ListStudentsByInstructor
{
    /** @return array{students: list<array<string, mixed>>} */
    public function __invoke(User $instructor): array
    {
        $students = Student::query()
            ->withLiveUser()
            ->enrolledWith($instructor)
            ->with('user')
            ->get()
            // ORDER BY u.full_name ASC in backend/. Sorted here rather than in
            // SQL because the name lives on the joined `users` row and adding
            // a join purely to order it would drag `users` columns into a
            // query that otherwise selects none.
            ->sortBy(fn (Student $s): string => (string) $s->getRelationValue('user')?->full_name)
            ->values();

        $aggregates = $this->aggregatesFor($instructor, $students->pluck('user_id')->all());

        return [
            'students' => $students->map(function (Student $student) use ($aggregates): array {
                $row = $aggregates[(string) $student->getKey()] ?? null;

                return (new StudentResource($student))->toArray(request()) + [
                    // SubscriptionsManagement.tsx keys its list on `id`, while
                    // the Student type it renders uses `student_id`. Both, same
                    // value — the pattern PublicUser documents for
                    // full_name/fullName.
                    'id' => $student->getKey(),
                    'courses_count' => (int) ($row->courses_count ?? 0),
                    'course_titles' => $row->course_titles ?? null,
                    'subscribed_at' => $row->last_subscribed_at ?? null,
                ];
            })->all(),
        ];
    }

    /**
     * courses_count / course_titles / last_subscribed_at for many students in
     * ONE query, keyed by student id — and every one of them constrained to
     * THIS instructor's courses.
     *
     * The `active()` scope is applied to the Subscription model's own builder
     * before the join, so the canonical predicate is used verbatim rather than
     * re-typed against an alias.
     *
     * @param  list<string> $studentIds
     * @return array<string, object>
     */
    private function aggregatesFor(User $instructor, array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        return Subscription::query()
            ->active()
            ->join('courses', 'courses.id', '=', 'subscriptions.course_id')
            ->where('courses.teacher_id', $instructor->getKey())
            ->whereNull('courses.deleted_at')
            ->whereIn('subscriptions.student_id', $studentIds)
            ->groupBy('subscriptions.student_id')
            ->select('subscriptions.student_id')
            ->selectRaw("GROUP_CONCAT(DISTINCT courses.title ORDER BY courses.title SEPARATOR ', ') as course_titles")
            ->selectRaw('COUNT(DISTINCT courses.id) as courses_count')
            ->selectRaw('MAX(subscriptions.created_at) as last_subscribed_at')
            ->get()
            ->keyBy('student_id')
            ->all();
    }
}
