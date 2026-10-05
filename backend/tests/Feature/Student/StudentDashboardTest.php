<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Enums\SubscriptionStatus;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/students/dashboard — freeze-table row 30.
 */
final class StudentDashboardTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $student;

    private User $instructor;

    private Course $enrolled;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();

        [$this->student] = $this->makeStudentWithCode();
        [$this->instructor] = $this->makeTeacherWithProfile([], ['full_name' => 'المعلّم']);

        $this->enrolled = $this->makeCourseFor($this->instructor, ['title' => 'كورس مشترك']);
        $this->makeSubscription($this->student, $this->enrolled);
    }

    private function dashboard(): array
    {
        $this->loginAs($this->student);

        return $this->getJson('/api/v1/students/dashboard')->assertStatus(200)->json('data');
    }

    /**
     * A video lesson on a course.
     *
     * The `sections` row is not decoration: content_items carries
     * `chk_content_items_single_parent`, a CHECK constraint requiring exactly
     * one of section_id / group_id to be set. An item with neither is rejected
     * by the database — the schema refusing to hold an orphaned lesson.
     */
    private function addLesson(Course $course, bool $hidden = false): string
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
            'title' => 'درس',
            'type' => 'video',
            'is_hidden' => $hidden,
            'created_at' => now(),
        ]);

        return $id;
    }

    // ══ ENROLMENT IS Subscription::scopeActive() ════════════════════════

    public function test_it_lists_only_actively_enrolled_courses(): void
    {
        $expiredCourse = $this->makeCourseFor($this->instructor, ['title' => 'منتهي']);
        $this->makeSubscription($this->student, $expiredCourse, SubscriptionStatus::Active, now()->subDay());

        $cancelledCourse = $this->makeCourseFor($this->instructor, ['title' => 'ملغي']);
        $this->makeSubscription($this->student, $cancelledCourse, SubscriptionStatus::Cancelled);

        // Not enrolled at all.
        $this->makeCourseFor($this->instructor, ['title' => 'غير مشترك']);

        $data = $this->dashboard();

        $this->assertCount(1, $data['courses']);
        $this->assertSame('كورس مشترك', $data['courses'][0]['title']);
        $this->assertSame(1, $data['stats']['enrolled_courses']);
    }

    public function test_a_lifetime_enrollment_is_included(): void
    {
        $course = $this->makeCourseFor($this->instructor, ['title' => 'مدى الحياة']);
        $this->makeSubscription($this->student, $course, SubscriptionStatus::Active, null);

        $titles = collect($this->dashboard()['courses'])->pluck('title');

        $this->assertContains('مدى الحياة', $titles);
    }

    public function test_another_students_enrollments_never_appear(): void
    {
        [$other] = $this->makeStudentWithCode();
        $theirs = $this->makeCourseFor($this->instructor, ['title' => 'كورس الآخر']);
        $this->makeSubscription($other, $theirs);

        $titles = collect($this->dashboard()['courses'])->pluck('title');

        $this->assertNotContains('كورس الآخر', $titles);
    }

    // ══ IDENTITY IS THE SESSION'S ═══════════════════════════════════════

    public function test_a_supplied_student_id_parameter_has_no_effect(): void
    {
        [$other] = $this->makeStudentWithCode();
        foreach (range(1, 3) as $i) {
            $this->makeSubscription($other, $this->makeCourseFor($this->instructor));
        }

        $this->loginAs($this->student);

        $mine = $this->getJson('/api/v1/students/dashboard')->assertStatus(200)->json('data');
        $spoofed = $this->getJson('/api/v1/students/dashboard?student_id=' . $other->getKey())
            ->assertStatus(200)->json('data');

        $this->assertSame($mine, $spoofed);
        $this->assertSame(1, $spoofed['stats']['enrolled_courses']);
    }

    public function test_only_a_student_may_call_it(): void
    {
        $this->loginAs($this->instructor);

        $this->getJson('/api/v1/students/dashboard')->assertStatus(403);
    }

    public function test_a_guest_gets_401(): void
    {
        $this->getJson('/api/v1/students/dashboard')->assertStatus(401);
    }

    // ══ THE PERFORMANCE FIX ═════════════════════════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE LESSON-COUNT AGGREGATE IS CONSTRAINED BEFORE IT GROUPS.          ║
     * ║                                                                      ║
     * ║ backend/'s lessonCountsByCourse() had no student and no course       ║
     * ║ filter: it grouped EVERY content item on the platform and returned a ║
     * ║ map of every course that has ever existed, from which PHP then read  ║
     * ║ the two or three keys the student is enrolled in.                     ║
     * ║                                                                      ║
     * ║ This asserts the SQL carries the whereIn — the response cannot tell  ║
     * ║ the two apart, which is exactly why the fix was invisible and        ║
     * ║ therefore survived.                                                   ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_lesson_count_query_is_scoped_to_the_enrolled_courses(): void
    {
        $this->addLesson($this->enrolled);

        // A course the student is NOT enrolled in, with lessons of its own.
        $foreign = $this->makeCourseFor($this->instructor);
        $this->addLesson($foreign);
        $this->addLesson($foreign);

        $this->loginAs($this->student);

        DB::enableQueryLog();
        $this->getJson('/api/v1/students/dashboard')->assertStatus(200);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $aggregate = collect($queries)->first(fn (array $q): bool => str_contains($q['query'], 'group by')
            && str_contains($q['query'], 'content_items')
            && !str_contains($q['query'], 'lesson_progress'));

        $this->assertNotNull($aggregate, 'The lesson-count aggregate should have run.');
        $this->assertStringContainsString(
            'in (',
            $aggregate['query'],
            'The aggregate must be constrained to the enrolled course ids BEFORE grouping.'
        );
        $this->assertContains($this->enrolled->getKey(), $aggregate['bindings']);
        $this->assertNotContains($foreign->getKey(), $aggregate['bindings']);
    }

    /** The fix is response-shape-preserving: only the query plan changed. */
    public function test_the_lesson_counts_are_still_correct(): void
    {
        $this->addLesson($this->enrolled);
        $this->addLesson($this->enrolled);
        // Hidden items are excluded so the denominator matches what the
        // student can actually watch.
        $this->addLesson($this->enrolled, hidden: true);

        $course = $this->dashboard()['courses'][0];

        $this->assertSame(2, $course['total_lessons']);
        $this->assertSame(0, $course['completed_lessons']);
    }

    public function test_completed_lessons_are_counted_per_course(): void
    {
        $one = $this->addLesson($this->enrolled);
        $this->addLesson($this->enrolled);

        DB::table('lesson_progress')->insert([
            'id' => (string) Str::uuid7(),
            'student_id' => $this->student->getKey(),
            'content_item_id' => $one,
            'progress_seconds' => 600,
            'completed' => true,
            'last_watched_at' => now(),
        ]);

        $data = $this->dashboard();

        $this->assertSame(2, $data['courses'][0]['total_lessons']);
        $this->assertSame(1, $data['courses'][0]['completed_lessons']);
        // 600 seconds -> 10 minutes.
        $this->assertSame(10, $data['stats']['total_study_time_minutes']);
    }

    // ══ SHAPE ═══════════════════════════════════════════════════════════

    /**
     * The exact keys StudentStatistics.tsx and MyCourses.tsx read. A missing
     * key here renders an empty widget rather than an error, so the shape is
     * pinned explicitly.
     */
    public function test_the_response_carries_the_shape_its_two_consumers_read(): void
    {
        $data = $this->dashboard();

        foreach (['total_study_time_minutes', 'exams_taken', 'average_score', 'enrolled_courses'] as $key) {
            $this->assertArrayHasKey($key, $data['stats'], "stats.{$key} is read by StudentStatistics.tsx");
        }

        foreach (['id', 'title', 'image', 'teacher', 'grade', 'subject', 'total_lessons', 'completed_lessons', 'latest_grade', 'subscribed_at'] as $key) {
            $this->assertArrayHasKey($key, $data['courses'][0], "courses[].{$key} is read by MyCourses.tsx");
        }

        $this->assertSame('المعلّم', $data['courses'][0]['teacher_name']);
        $this->assertSame('المعلّم', $data['courses'][0]['teacher']['name']);
    }

    public function test_a_student_with_no_enrollments_gets_an_empty_but_well_formed_payload(): void
    {
        [$fresh] = $this->makeStudentWithCode();
        $this->loginAs($fresh);

        $data = $this->getJson('/api/v1/students/dashboard')->assertStatus(200)->json('data');

        $this->assertSame([], $data['courses']);
        $this->assertSame(0, $data['stats']['enrolled_courses']);
        $this->assertSame(0, $data['stats']['exams_taken']);
        $this->assertSame(0, $data['stats']['total_study_time_minutes']);
    }
}
