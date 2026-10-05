<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Enums\SubscriptionStatus;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/enrollments/instructor-students — freeze-table row 34.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE CENTREPIECE IS test_a_shared_student_appears_on_both_rosters_with_   ║
 * ║ different_course_counts.                                                 ║
 * ║                                                                          ║
 * ║ "No foreign rows" is the OBVIOUS isolation property, and it is not       ║
 * ║ enough. A roster can return exactly the right STUDENTS while attaching   ║
 * ║ the wrong NUMBERS — `courses_count` and `course_titles` computed across  ║
 * ║ all of a student's enrollments rather than only those with the calling   ║
 * ║ instructor. That leaks a competitor's catalogue size and course titles   ║
 * ║ through an endpoint that passes every "zero cross-tenant rows" test.     ║
 * ║                                                                          ║
 * ║ A shared student with a DIFFERENT count per teacher is the assertion     ║
 * ║ that catches it: it can only pass if the aggregates are scoped too.      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class CrossTeacherIsolationTest extends TestCase
{
    use AssertsTenantIsolation;
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $teacherA;

    private User $teacherB;

    private User $sharedStudent;

    /** @var array<string, Course> */
    private array $courses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();

        [$this->teacherA] = $this->makeTeacherWithProfile([], ['full_name' => 'المعلّم أ']);
        [$this->teacherB] = $this->makeTeacherWithProfile([], ['full_name' => 'المعلّم ب']);

        // Teacher A owns TWO courses, teacher B owns FIVE.
        foreach (['a1', 'a2'] as $key) {
            $this->courses[$key] = $this->makeCourseFor($this->teacherA, ['title' => "كورس {$key}"]);
        }
        foreach (['b1', 'b2', 'b3', 'b4', 'b5'] as $key) {
            $this->courses[$key] = $this->makeCourseFor($this->teacherB, ['title' => "كورس {$key}"]);
        }

        // ONE student enrolled in BOTH of A's courses and ALL FIVE of B's.
        $this->sharedStudent = $this->makeStudentWithCode()[0];

        foreach ($this->courses as $course) {
            $this->makeSubscription($this->sharedStudent, $course);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function rosterFor(User $instructor): array
    {
        $this->loginAs($instructor);

        $roster = $this->getJson('/api/v1/enrollments/instructor-students')
            ->assertStatus(200)
            ->json('data.students');

        $this->post('/api/v1/auth/logout');

        return $roster;
    }

    // ══ THE CENTREPIECE ═════════════════════════════════════════════════

    public function test_a_shared_student_appears_on_both_rosters_with_different_course_counts(): void
    {
        $rosterA = $this->rosterFor($this->teacherA);
        $rosterB = $this->rosterFor($this->teacherB);

        // The SAME student is on both rosters — this is not a "hide them from
        // one side" test.
        $this->assertCount(1, $rosterA);
        $this->assertCount(1, $rosterB);
        $this->assertSame($this->sharedStudent->getKey(), $rosterA[0]['student_id']);
        $this->assertSame($this->sharedStudent->getKey(), $rosterB[0]['student_id']);

        // …but each teacher sees only their OWN courses in the aggregate.
        $this->assertSame(
            2,
            $rosterA[0]['courses_count'],
            "Teacher A must see 2, not 7: the aggregate is scoped to the caller's own courses."
        );
        $this->assertSame(
            5,
            $rosterB[0]['courses_count'],
            'Teacher B must see 5, not 7.'
        );
    }

    /**
     * The same property for `course_titles`, which is the more damaging leak
     * of the two: it names a competitor's products rather than merely counting
     * them.
     */
    public function test_course_titles_never_name_another_teachers_courses(): void
    {
        $titlesA = $this->rosterFor($this->teacherA)[0]['course_titles'];
        $titlesB = $this->rosterFor($this->teacherB)[0]['course_titles'];

        $this->assertSame('كورس a1, كورس a2', $titlesA);

        foreach (['b1', 'b2', 'b3', 'b4', 'b5'] as $foreign) {
            $this->assertStringNotContainsString(
                "كورس {$foreign}",
                (string) $titlesA,
                "Teacher A's roster must not name teacher B's course {$foreign}."
            );
        }

        foreach (['a1', 'a2'] as $foreign) {
            $this->assertStringNotContainsString("كورس {$foreign}", (string) $titlesB);
        }
    }

    // ══ THE ORDINARY ISOLATION PROPERTY ═════════════════════════════════

    public function test_a_student_enrolled_only_with_the_other_teacher_never_appears(): void
    {
        $exclusive = $this->makeStudentWithCode()[0];
        $this->makeSubscription($exclusive, $this->courses['b1']);

        $ids = collect($this->rosterFor($this->teacherA))->pluck('student_id');

        $this->assertNotContains($exclusive->getKey(), $ids);
        $this->assertCount(1, $ids);
    }

    public function test_a_teacher_with_no_courses_sees_an_empty_roster(): void
    {
        [$lonely] = $this->makeTeacherWithProfile();

        $this->assertSame([], $this->rosterFor($lonely));
    }

    // ══ "IS SUBSCRIBED" IS THE CANONICAL SCOPE ══════════════════════════

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE SPLIT-whereHas BUG, PINNED.                                      │
     * │                                                                      │
     * │ The freeze table's sketch for row 34 expressed the filter as TWO     │
     * │ separate whereHas calls — "has a subscription to this teacher" AND   │
     * │ "has an active subscription". Satisfied independently, those admit   │
     * │ a student whose enrollment with THIS teacher has EXPIRED but who is  │
     * │ active elsewhere. Student::scopeEnrolledWith() uses one nested call  │
     * │ instead, and this is the case that tells them apart.                 │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_an_expired_enrollment_here_is_not_rescued_by_an_active_one_elsewhere(): void
    {
        $student = $this->makeStudentWithCode()[0];

        // Expired with teacher A…
        $this->makeSubscription($student, $this->courses['a1'], SubscriptionStatus::Active, now()->subDay());
        // …but perfectly active with teacher B.
        $this->makeSubscription($student, $this->courses['b1']);

        $ids = collect($this->rosterFor($this->teacherA))->pluck('student_id');

        $this->assertNotContains(
            $student->getKey(),
            $ids,
            'An expired enrollment with THIS teacher must not be rescued by an active one with another.'
        );
    }

    public function test_a_cancelled_enrollment_is_excluded(): void
    {
        $student = $this->makeStudentWithCode()[0];
        $this->makeSubscription($student, $this->courses['a1'], SubscriptionStatus::Cancelled);

        $ids = collect($this->rosterFor($this->teacherA))->pluck('student_id');

        $this->assertNotContains($student->getKey(), $ids);
    }

    /** NULL expires_at means "never expires", not "expired". */
    public function test_a_lifetime_enrollment_is_included(): void
    {
        $student = $this->makeStudentWithCode()[0];
        $this->makeSubscription($student, $this->courses['a1'], SubscriptionStatus::Active, null);

        $ids = collect($this->rosterFor($this->teacherA))->pluck('student_id');

        $this->assertContains($student->getKey(), $ids);
    }

    public function test_a_soft_deleted_student_drops_off_the_roster(): void
    {
        $this->sharedStudent->delete();

        $this->assertSame([], $this->rosterFor($this->teacherA));
    }

    // ══ IDENTITY IS THE SESSION'S ═══════════════════════════════════════

    public function test_a_supplied_teacher_id_parameter_has_no_effect(): void
    {
        $this->loginAs($this->teacherA);

        $mine = $this->getJson('/api/v1/enrollments/instructor-students')
            ->assertStatus(200)->json('data');

        $spoofed = $this->getJson(
            '/api/v1/enrollments/instructor-students?teacher_id=' . $this->teacherB->getKey()
        )->assertStatus(200)->json('data');

        $this->assertSame($mine, $spoofed);
        $this->assertSame(2, $spoofed['students'][0]['courses_count']);
    }

    public function test_only_an_instructor_may_call_it(): void
    {
        $this->loginAs($this->makeAdmin());

        $this->getJson('/api/v1/enrollments/instructor-students')->assertStatus(403);
    }

    public function test_a_guest_gets_401(): void
    {
        $this->getJson('/api/v1/enrollments/instructor-students')->assertStatus(401);
    }
}
