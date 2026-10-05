<?php

declare(strict_types=1);

namespace App\Actions\Student;

use App\Enums\ContentItemType;
use App\Models\CenterCourseActivation;
use App\Models\Course;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/students/dashboard — the CALLING student's own data only.
 *
 * Port of backend/StudentService::dashboard() plus the four repository queries
 * behind it (CourseRepository::listByStudentSubscription,
 * StudentRepository::lessonCountsByCourse / latestGradeByCourse,
 * LessonProgressRepository::completedCountsByCourse / totalWatchedSeconds).
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE STUDENT IS THE SESSION USER. Like TeacherStatistics, this action     │
 * │ takes a User object rather than a string id, so there is no parameter    │
 * │ into which a `?student_id=` could be threaded. Every query below filters │
 * │ on that one object's key.                                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ PERFORMANCE FIX (RESPONSE-SHAPE-PRESERVING): THE LESSON-COUNT AGGREGATE │
 * │ IS NOW CONSTRAINED **BEFORE** IT GROUPS.                                │
 * │                                                                          │
 * │ backend/StudentRepository::lessonCountsByCourse() takes NO student and   │
 * │ no course list:                                                          │
 * │     SELECT course_id, COUNT(*) FROM content_items                        │
 * │     WHERE type='video' AND is_hidden=0 GROUP BY course_id                │
 * │ It groups EVERY content item on the ENTIRE PLATFORM and hands back a map │
 * │ of every course that has ever existed, from which PHP then reads the two │
 * │ or three keys the student is actually enrolled in. Harmless today,       │
 * │ linear in total platform content forever.                                │
 * │                                                                          │
 * │ The fix is one whereIn on the already-known enrolled course ids, applied │
 * │ BEFORE the GROUP BY (see lessonTotals()). The JSON response is           │
 * │ byte-identical — the discarded keys were never read — and only the query │
 * │ plan changes: idx_content_items_course_type now drives the scan instead  │
 * │ of a full table group.                                                   │
 * │                                                                          │
 * │ The same constraint is applied to completedCounts() and latestGrades(),  │
 * │ which were already student-scoped but not course-scoped.                 │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ENROLMENT IS Subscription::scopeActive(), ONCE, AT THE TOP. Everything else
 * derives from the ids that query returns, so the "is subscribed" predicate
 * appears exactly one time in this whole feature — backend/ inlined it here as
 * a fourth copy.
 *
 * "تفعيل كورس سنتر" (decision 10.35) — a SECOND, additive source of course
 * ids sits right beside the subscription query: `center_course_activations`
 * for this student. A course reached only through an activation grant (no
 * Subscription row at all) must appear here exactly like a subscribed one —
 * that is the whole point of the grant ("يفتح الكورس وكأنه طالب مشترك") —
 * so `$courseIds` below is the UNION of both sources, and every per-course
 * lookup that used to assume a subscription row exists now falls back to the
 * activation row when it doesn't.
 *
 * THREE TABLES ARE READ WITH DB::table() ON PURPOSE: `content_items`,
 * `lesson_progress`, `exam_attempts`/`exams`. Their
 * models belong to Stages 5 and 6, and a half-built model that looks finished
 * is worse than an obviously absent one (the PlaceholderModel doctrine). These
 * are read-only aggregate reads over columns verified against the migrations,
 * exactly as backend/'s repositories did them.
 *
 * Response shape, dictated by its two consumers:
 *   MyCourses.tsx        reads `data.courses`, rendering each through CourseCard
 *   StudentStatistics.tsx reads `data.stats.{total_study_time_minutes,
 *                        exams_taken, average_score}`
 *                        and per-course {total_lessons, completed_lessons,
 *                        latest_grade}
 */
final class StudentDashboard
{
    /** @return array{courses: list<array<string, mixed>>, stats: array<string, mixed>} */
    public function __invoke(User $student): array
    {
        $studentId = (string) $student->getKey();

        // The ONE enrolment query. Keyed by course so the per-course
        // subscribed_at/expires_at can be attached without a second lookup.
        $subscriptions = Subscription::query()
            ->active()
            ->where('student_id', $studentId)
            ->get()
            ->keyBy(fn (Subscription $s): string => (string) $s->getAttribute('course_id'));

        // The activation-grant twin — see the class docblock. `latest()` per
        // course id: `updateOrCreate`-free duplicates cannot occur (no
        // insert path exists), but keyBy keeping the LAST row read is the
        // same "harmless if it ever happened" discipline the rest of this
        // action already uses.
        $activations = CenterCourseActivation::query()
            ->where('student_id', $studentId)
            ->get()
            ->keyBy(fn (CenterCourseActivation $a): string => (string) $a->getAttribute('course_id'));

        $courseIds = $subscriptions->keys()->merge($activations->keys())->unique()->values()->all();

        $courses = $this->coursesFor($courseIds);
        $lessonTotals = $this->lessonTotals($courseIds);
        $completed = $this->completedCounts($studentId, $courseIds);
        $latestGrades = $this->latestGrades($studentId, $courseIds);

        $presented = $courses
            // ORDER BY enrolment date DESC — subscribed_at if this course was
            // subscribed, else the activation's activated_at — so the list
            // still reads as "most recently gained access first" for both
            // kinds of access.
            ->sortByDesc(function (Course $c) use ($subscriptions, $activations): string {
                $courseId = (string) $c->getKey();

                return (string) (
                    $subscriptions->get($courseId)?->getAttribute('created_at')
                    ?? $activations->get($courseId)?->getAttribute('activated_at')
                );
            })
            ->values()
            ->map(function (Course $course) use ($subscriptions, $activations, $lessonTotals, $completed, $latestGrades): array {
                $courseId = (string) $course->getKey();
                $subscription = $subscriptions->get($courseId);
                $activation = $activations->get($courseId);

                return [
                    'id' => $course->getKey(),
                    'title' => $course->getAttribute('title'),
                    'description' => $course->getAttribute('description'),
                    'thumbnail_url' => $course->getAttribute('thumbnail_url'),
                    // CourseCard reads `image`; the raw column name is kept
                    // alongside it. Same dual-naming contract as PublicUser.
                    'image' => $course->getAttribute('thumbnail_url'),
                    // backend/ cast these to float at the presenter. Preserved
                    // verbatim so the JSON types do not change under the
                    // frontend — Stage 7 owns the money-precision question, and
                    // this stage must not pre-empt it in either direction.
                    'price' => (float) ($course->getAttribute('price') ?? 0),
                    'discount_price' => $course->getAttribute('discount_price') !== null
                        ? (float) $course->getAttribute('discount_price')
                        : null,
                    'teacher_id' => $course->getAttribute('teacher_id'),
                    'teacher_name' => $course->getAttribute('teacher_name'),
                    'teacher_avatar' => $course->getAttribute('teacher_avatar'),
                    'teacher' => [
                        'name' => $course->getAttribute('teacher_name'),
                        'image' => $course->getAttribute('teacher_avatar'),
                    ],
                    'grade_name' => $course->getAttribute('grade_name'),
                    'grade' => $course->getAttribute('grade_name'),
                    'subject_name' => $course->getAttribute('subject_name'),
                    'subject' => $course->getAttribute('subject_name'),
                    // A course reached ONLY through an activation (no
                    // Subscription row) has no `expires_at` — the grant is
                    // free and open-ended, exactly like center-membership
                    // access, and nothing revokes it on a timer.
                    'subscribed_at' => $subscription?->created_at?->format('Y-m-d H:i:s')
                        ?? $activation?->activated_at?->format('Y-m-d H:i:s'),
                    'expires_at' => $subscription?->expires_at?->format('Y-m-d H:i:s'),
                    'total_lessons' => $lessonTotals[$courseId] ?? 0,
                    'completed_lessons' => $completed[$courseId] ?? 0,
                    'latest_grade' => $latestGrades[$courseId] ?? 0,
                ];
            })
            ->all();

        return [
            'courses' => $presented,
            'stats' => $this->stats($studentId) + [
                'total_study_time_minutes' => (int) round($this->watchedSeconds($studentId) / 60),
                'enrolled_courses' => count($presented),
            ],
        ];
    }

    /**
     * The enrolled courses with their teacher / grade / subject display names.
     *
     * THE TEACHER JOIN GOES THROUGH `teachers`, not straight from
     * courses.teacher_id to users.id. The two produce the same rows today
     * because teachers.user_id IS the user id — but they are different CLAIMS,
     * and only the three-table path asserts "the owner is an instructor
     * PROFILE". See App\Models\Course's docblock.
     *
     * @param  list<string> $courseIds
     * @return Collection<int, Course>
     */
    private function coursesFor(array $courseIds): Collection
    {
        if ($courseIds === []) {
            return collect();
        }

        return Course::query()
            ->select([
                'courses.*',
                'teacher_users.full_name as teacher_name',
                'teacher_users.avatar_url as teacher_avatar',
                'grades.name as grade_name',
                'subjects.name as subject_name',
            ])
            ->leftJoin('teachers', 'teachers.user_id', '=', 'courses.teacher_id')
            ->leftJoin('users as teacher_users', 'teacher_users.id', '=', 'teachers.user_id')
            ->leftJoin('grades', 'grades.id', '=', 'courses.grade_id')
            ->leftJoin('subjects', 'subjects.id', '=', 'courses.subject_id')
            ->whereIn('courses.id', $courseIds)
            ->get();
    }

    /**
     * Total VISIBLE video lessons per course — the denominator for
     * StudentStatistics.tsx's completion percentage. Hidden items are excluded
     * so the denominator matches what the student can actually watch.
     *
     * THE whereIn IS THE PERFORMANCE FIX. See the class docblock.
     *
     * @param  list<string> $courseIds
     * @return array<string, int>
     */
    private function lessonTotals(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        return DB::table('content_items')
            ->whereIn('course_id', $courseIds)
            ->where('type', ContentItemType::Video->value)
            ->where('is_hidden', false)
            ->groupBy('course_id')
            ->select('course_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'course_id')
            ->map(static fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * Per-course COMPLETED lesson counts for one student.
     *
     * Joined through content_items so progress is counted whether the lesson
     * hangs off a section or off a group.
     *
     * @param  list<string> $courseIds
     * @return array<string, int>
     */
    private function completedCounts(string $studentId, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        return DB::table('lesson_progress')
            ->join('content_items', 'content_items.id', '=', 'lesson_progress.content_item_id')
            ->where('lesson_progress.student_id', $studentId)
            ->where('lesson_progress.completed', true)
            ->whereIn('content_items.course_id', $courseIds)
            ->groupBy('content_items.course_id')
            ->select('content_items.course_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'course_id')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * Most recent SUBMITTED exam score per course, walking
     * exam_attempts -> exams -> content_items to reach the course.
     *
     * Ascending order is load-bearing: later rows overwrite earlier ones in the
     * map, leaving the most recent score per course. Same trick backend/ used,
     * and cheaper than a correlated MAX(submitted_at) subquery.
     *
     * @param  list<string> $courseIds
     * @return array<string, float>
     */
    private function latestGrades(string $studentId, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $rows = DB::table('exam_attempts')
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->join('content_items', 'content_items.id', '=', 'exams.content_item_id')
            ->where('exam_attempts.student_id', $studentId)
            ->whereNotNull('exam_attempts.submitted_at')
            ->whereNotNull('exam_attempts.score')
            ->whereIn('content_items.course_id', $courseIds)
            ->orderBy('exam_attempts.submitted_at')
            ->get(['content_items.course_id', 'exam_attempts.score']);

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->course_id] = (float) $row->score;
        }

        return $out;
    }

    /**
     * Aggregate learning stats. Only SUBMITTED attempts count toward
     * exams_taken / average_score — an abandoned in-progress attempt
     * (submitted_at IS NULL) is ignored.
     *
     * Deliberately NOT course-scoped, unlike the three maps above: these are
     * whole-account totals, and backend/ computed them the same way. A student
     * whose subscription lapsed still took those exams.
     *
     * @return array{exams_taken: int, average_score: float}
     */
    private function stats(string $studentId): array
    {
        $exams = DB::table('exam_attempts')
            ->where('student_id', $studentId)
            ->whereNotNull('submitted_at');

        return [
            'exams_taken' => (clone $exams)->count(),
            'average_score' => round((float) (clone $exams)->whereNotNull('score')->avg('score'), 2),
        ];
    }

    /** Total seconds watched across every lesson — backs the study-time stat. */
    private function watchedSeconds(string $studentId): int
    {
        return (int) DB::table('lesson_progress')
            ->where('student_id', $studentId)
            ->sum('progress_seconds');
    }
}
