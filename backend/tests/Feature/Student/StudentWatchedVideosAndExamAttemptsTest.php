<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/students/watched-videos — freeze-table row 117.
 * GET /api/v1/students/exam-attempts — freeze-table row 118.
 */
final class StudentWatchedVideosAndExamAttemptsTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $student;

    private User $instructor;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();

        [$this->student] = $this->makeStudentWithCode();
        [$this->instructor] = $this->makeTeacherWithProfile([], ['full_name' => 'المعلّم']);
        $this->course = $this->makeCourseFor($this->instructor, ['title' => 'كورس مشترك']);
        $this->makeSubscription($this->student, $this->course);
    }

    private function addLesson(Course $course, string $title = 'درس'): string
    {
        $sectionId = (string) Str::uuid7();

        DB::table('sections')->insert([
            'id' => $sectionId,
            'course_id' => $course->getKey(),
            'title' => 'الوحدة',
            'order_index' => 0,
        ]);

        $id = (string) Str::uuid7();

        DB::table('content_items')->insert([
            'id' => $id,
            'course_id' => $course->getKey(),
            'section_id' => $sectionId,
            'title' => $title,
            'type' => 'video',
            'is_hidden' => false,
            'created_at' => now(),
        ]);

        return $id;
    }

    private function watchLesson(string $contentItemId, int $seconds, bool $completed, ?\DateTimeInterface $watchedAt = null): void
    {
        DB::table('lesson_progress')->insert([
            'id' => (string) Str::uuid7(),
            'student_id' => $this->student->getKey(),
            'content_item_id' => $contentItemId,
            'progress_seconds' => $seconds,
            'completed' => $completed,
            'last_watched_at' => $watchedAt ?? now(),
        ]);
    }

    /** @return array{0: string, 1: string} [exam_id, exam title] */
    private function addExam(Course $course, string $title = 'امتحان'): array
    {
        $sectionId = (string) Str::uuid7();

        DB::table('sections')->insert([
            'id' => $sectionId,
            'course_id' => $course->getKey(),
            'title' => 'الوحدة',
            'order_index' => 0,
        ]);

        $contentItemId = (string) Str::uuid7();

        DB::table('content_items')->insert([
            'id' => $contentItemId,
            'course_id' => $course->getKey(),
            'section_id' => $sectionId,
            'title' => $title,
            'type' => 'exam',
            'is_hidden' => false,
            'created_at' => now(),
        ]);

        $examId = (string) Str::uuid7();

        DB::table('exams')->insert([
            'id' => $examId,
            'content_item_id' => $contentItemId,
            'duration_minutes' => 30,
            'total_score' => 100,
        ]);

        return [$examId, $title];
    }

    private function attemptExam(string $examId, ?float $score, bool $submitted, int $attemptNumber = 1): void
    {
        DB::table('exam_attempts')->insert([
            'id' => (string) Str::uuid7(),
            'exam_id' => $examId,
            'student_id' => $this->student->getKey(),
            'attempt_number' => $attemptNumber,
            'score' => $score,
            'started_at' => now()->subMinutes(20),
            'submitted_at' => $submitted ? now() : null,
        ]);
    }

    // ══ /students/watched-videos ══════════════════════════════════════

    public function test_it_returns_only_lessons_the_student_has_progress_on(): void
    {
        $watched = $this->addLesson($this->course, 'محاضرة 1');
        $this->addLesson($this->course, 'محاضرة 2 — لم تُشاهَد بعد');
        $this->watchLesson($watched, 300, false);

        $this->loginAs($this->student);
        $data = $this->getJson('/api/v1/students/watched-videos')->assertStatus(200)->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('محاضرة 1', $data[0]['title']);
        $this->assertSame($this->course->getKey(), $data[0]['course_id']);
        $this->assertSame('كورس مشترك', $data[0]['course_title']);
        $this->assertSame(300, $data[0]['progress_seconds']);
        $this->assertFalse($data[0]['completed']);
    }

    public function test_it_survives_a_lapsed_subscription(): void
    {
        $lesson = $this->addLesson($this->course);
        $this->watchLesson($lesson, 120, true);

        DB::table('subscriptions')
            ->where('student_id', $this->student->getKey())
            ->update(['status' => 'cancelled']);

        $this->loginAs($this->student);
        $data = $this->getJson('/api/v1/students/watched-videos')->assertStatus(200)->json('data');

        $this->assertCount(1, $data, 'Watch history is a whole-account fact, not gated by current subscription.');
    }

    public function test_another_students_watch_history_never_appears(): void
    {
        $lesson = $this->addLesson($this->course);
        $this->watchLesson($lesson, 100, false);

        [$other] = $this->makeStudentWithCode();
        $this->makeSubscription($other, $this->course);

        $this->loginAs($other);
        $data = $this->getJson('/api/v1/students/watched-videos')->assertStatus(200)->json('data');

        $this->assertSame([], $data);
    }

    public function test_a_supplied_student_id_parameter_has_no_effect_on_watched_videos(): void
    {
        $lesson = $this->addLesson($this->course);
        $this->watchLesson($lesson, 100, false);

        [$other] = $this->makeStudentWithCode();
        $this->loginAs($other);
        $spoofed = $this->getJson('/api/v1/students/watched-videos?student_id=' . $this->student->getKey())
            ->assertStatus(200)->json('data');

        $this->assertSame([], $spoofed);
    }

    public function test_only_a_student_may_call_watched_videos(): void
    {
        $this->loginAs($this->instructor);

        $this->getJson('/api/v1/students/watched-videos')->assertStatus(403);
    }

    public function test_a_guest_gets_401_on_watched_videos(): void
    {
        $this->getJson('/api/v1/students/watched-videos')->assertStatus(401);
    }

    // ══ /students/exam-attempts ═══════════════════════════════════════

    public function test_it_returns_only_submitted_attempts(): void
    {
        [$examId, $title] = $this->addExam($this->course, 'امتحان الوحدة الأولى');
        $this->attemptExam($examId, 85.5, true);
        $this->attemptExam($examId, null, false, attemptNumber: 2); // abandoned, no score

        $this->loginAs($this->student);
        $data = $this->getJson('/api/v1/students/exam-attempts')->assertStatus(200)->json('data');

        $this->assertCount(1, $data, 'An unsubmitted attempt is not a completed exam.');
        $this->assertSame($title, $data[0]['exam_title']);
        $this->assertSame($this->course->getKey(), $data[0]['course_id']);
        $this->assertSame(85.5, $data[0]['score']);
        // JSON has no int/float distinction: a whole-number float round-trips
        // as an int through json_decode, same as elsewhere in this suite.
        $this->assertEquals(100.0, $data[0]['total_score']);
    }

    public function test_it_survives_a_lapsed_subscription_too(): void
    {
        [$examId] = $this->addExam($this->course);
        $this->attemptExam($examId, 70.0, true);

        DB::table('subscriptions')
            ->where('student_id', $this->student->getKey())
            ->update(['status' => 'cancelled']);

        $this->loginAs($this->student);
        $data = $this->getJson('/api/v1/students/exam-attempts')->assertStatus(200)->json('data');

        $this->assertCount(1, $data, 'Sitting the exam is a whole-account fact, same precedent as exams_taken.');
    }

    public function test_another_students_attempts_never_appear(): void
    {
        [$examId] = $this->addExam($this->course);
        $this->attemptExam($examId, 90.0, true);

        [$other] = $this->makeStudentWithCode();
        $this->makeSubscription($other, $this->course);

        $this->loginAs($other);
        $data = $this->getJson('/api/v1/students/exam-attempts')->assertStatus(200)->json('data');

        $this->assertSame([], $data);
    }

    public function test_only_a_student_may_call_exam_attempts(): void
    {
        $this->loginAs($this->instructor);

        $this->getJson('/api/v1/students/exam-attempts')->assertStatus(403);
    }

    public function test_a_guest_gets_401_on_exam_attempts(): void
    {
        $this->getJson('/api/v1/students/exam-attempts')->assertStatus(401);
    }
}
