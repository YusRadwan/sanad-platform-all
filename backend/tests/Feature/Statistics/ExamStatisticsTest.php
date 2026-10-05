<?php

declare(strict_types=1);

namespace Tests\Feature\Statistics;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * "إحصائيات الامتحانات" (decision 10.36) — GET /exams/{examId}/statistics.
 * Instructor-only, OWNER-only: covers the arithmetic (average/highest/
 * success-rate/attempted-vs-not) and the cross-teacher isolation.
 */
final class ExamStatisticsTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private function submitAttempt(Exam $exam, User $student, float $score, int $attemptNumber = 1): ExamAttempt
    {
        // `startedAt` is offset by the attempt number rather than left as a
        // bare `now()`: two attempts inserted in the same test can otherwise
        // land on the SAME second (this schema has no microsecond tie-break
        // — see ExamAttempt::latestFor()'s own docblock), making "which one
        // is latest" nondeterministic exactly like it is in production.
        $startedAt = now()->addSeconds($attemptNumber);

        $attempt = new ExamAttempt();
        $attempt->forceFill([
            'id' => (string) Str::uuid7(),
            'exam_id' => $exam->getKey(),
            'student_id' => $student->getKey(),
            'attempt_number' => $attemptNumber,
            'answers' => [],
            'score' => $score,
            'started_at' => $startedAt,
            'submitted_at' => $startedAt,
        ]);
        $attempt->save();

        return $attempt;
    }

    public function test_the_owning_instructor_sees_a_correct_report(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        $course = $this->makeCourseFor($teacher);
        $section = $this->makeSection($course);
        [, $exam] = $this->makeExam($section, ['total_score' => 20, 'pass_score' => 10]);

        $passing = $this->makeStudentUser();
        $failing = $this->makeStudentUser();
        $absent = $this->makeStudentUser();

        $this->makeSubscription($passing, $course);
        $this->makeSubscription($failing, $course);
        $this->makeSubscription($absent, $course);

        $this->submitAttempt($exam, $passing, 18);
        $this->submitAttempt($exam, $failing, 4);

        $this->loginAs($teacher);
        $data = $this->getJson("/api/v1/exams/{$exam->getKey()}/statistics")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(3, $data['stats']['total_students']);
        $this->assertSame(2, $data['stats']['attempted_count']);
        $this->assertSame(1, $data['stats']['not_attempted_count']);
        $this->assertEqualsWithDelta(55.0, $data['stats']['average_score_percent'], 0.1); // (18+4)/2 = 11, /20 = 55%
        $this->assertEqualsWithDelta(18.0, $data['stats']['highest_score'], 0.01);
        $this->assertEqualsWithDelta(50.0, $data['stats']['success_rate_percent'], 0.1); // 1 of 2 passed

        $this->assertCount(2, $data['attempted']);
        $this->assertCount(1, $data['not_attempted']);
        $this->assertSame((string) $absent->getKey(), $data['not_attempted'][0]['student_id']);

        $passingRow = collect($data['attempted'])->firstWhere('student_id', (string) $passing->getKey());
        $this->assertSame('passed', $passingRow['status']);
        $failingRow = collect($data['attempted'])->firstWhere('student_id', (string) $failing->getKey());
        $this->assertSame('failed', $failingRow['status']);
    }

    public function test_a_second_attempt_replaces_the_first_in_the_attempted_row_but_counts_toward_attempts(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        $course = $this->makeCourseFor($teacher);
        $section = $this->makeSection($course);
        [, $exam] = $this->makeExam($section, ['total_score' => 20, 'pass_score' => 10]);

        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $course);

        $this->submitAttempt($exam, $student, 5, 1);
        $this->submitAttempt($exam, $student, 15, 2);

        $this->loginAs($teacher);
        $data = $this->getJson("/api/v1/exams/{$exam->getKey()}/statistics")
            ->assertStatus(200)
            ->json('data');

        $row = collect($data['attempted'])->firstWhere('student_id', (string) $student->getKey());
        $this->assertSame(2, $row['attempts_count']);
        $this->assertEqualsWithDelta(15.0, $row['score'], 0.01);
    }

    public function test_a_foreign_teacher_cannot_view_another_teachers_exam_statistics(): void
    {
        $this->disableRateLimiting();
        $owner = $this->makeInstructor();
        $stranger = $this->makeInstructor();
        $course = $this->makeCourseFor($owner);
        $section = $this->makeSection($course);
        [, $exam] = $this->makeExam($section, ['total_score' => 20]);

        $this->loginAs($stranger);
        $this->getJson("/api/v1/exams/{$exam->getKey()}/statistics")->assertStatus(403);
    }

    public function test_a_subscribed_student_is_refused_not_admitted(): void
    {
        $this->disableRateLimiting();
        $teacher = $this->makeInstructor();
        $course = $this->makeCourseFor($teacher);
        $section = $this->makeSection($course);
        [, $exam] = $this->makeExam($section, ['total_score' => 20]);

        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $course);

        $this->loginAs($student);
        $this->getJson("/api/v1/exams/{$exam->getKey()}/statistics")->assertStatus(403);
    }
}
