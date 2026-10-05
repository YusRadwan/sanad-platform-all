<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Enums\SubscriptionStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/teachers/statistics — freeze-table row 33.
 *
 * The two properties worth testing here are (a) the identity is the session's
 * and cannot be redirected, and (b) the aggregates count the teacher's OWN
 * drafts, because ownership and visibility are different questions.
 */
final class TeacherStatisticsTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();

        [$this->instructor] = $this->makeTeacherWithProfile();
    }

    // ══ IDENTITY IS THE SESSION'S, FULL STOP ════════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ A SUPPLIED `?teacher_id=` MUST HAVE **ZERO** EFFECT.                 ║
     * ║                                                                      ║
     * ║ Not "is rejected with a 422" — has no effect at all. The controller  ║
     * ║ reads $request->user() and never touches query input, so there is no ║
     * ║ code path that could observe the parameter. This test proves that by ║
     * ║ building a SECOND teacher with a completely different course count   ║
     * ║ and asking for their id: the answer must still describe the caller.  ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_supplied_teacher_id_parameter_has_no_effect(): void
    {
        [$other] = $this->makeTeacherWithProfile();
        $this->makeCourseFor($other);
        $this->makeCourseFor($other);
        $this->makeCourseFor($other);

        $this->makeCourseFor($this->instructor);

        $this->loginAs($this->instructor);

        $mine = $this->getJson('/api/v1/teachers/statistics')->assertStatus(200)->json('data');

        $spoofed = $this->getJson('/api/v1/teachers/statistics?teacher_id=' . $other->getKey())
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(
            $mine,
            $spoofed,
            'The response must be byte-identical — the parameter is not read, not merely rejected.'
        );
        $this->assertSame(1, $spoofed['stats']['summary']['total_courses']);
    }

    public function test_only_an_instructor_may_call_it(): void
    {
        $this->loginAs($this->makeAdmin());
        $this->getJson('/api/v1/teachers/statistics')->assertStatus(403);

        $this->getJson('/api/v1/teachers/statistics')->assertStatus(403);
    }

    public function test_a_guest_gets_401(): void
    {
        $this->getJson('/api/v1/teachers/statistics')->assertStatus(401);
    }

    // ══ DRAFTS COUNT TOWARD THE OWNER'S OWN NUMBERS ═════════════════════

    /**
     * CoursePolicy::view()'s draft-hiding rule hides drafts from OTHERS, never
     * from the owner. An aggregate filtered through anything
     * visibility-shaped would make an instructor's dashboard read "0 courses"
     * the moment they unpublished one.
     */
    public function test_unpublished_courses_are_counted_in_the_owners_statistics(): void
    {
        $this->makeCourseFor($this->instructor, ['is_published' => false]);
        $this->makeCourseFor($this->instructor, ['is_published' => true]);

        $this->loginAs($this->instructor);

        $this->getJson('/api/v1/teachers/statistics')
            ->assertStatus(200)
            ->assertJsonPath('data.stats.summary.total_courses', 2);
    }

    /** A soft-deleted course is NOT counted — SoftDeletes' global scope. */
    public function test_a_deleted_course_is_not_counted(): void
    {
        $course = $this->makeCourseFor($this->instructor);
        $this->makeCourseFor($this->instructor);
        $course->delete();

        $this->loginAs($this->instructor);

        $this->getJson('/api/v1/teachers/statistics')
            ->assertStatus(200)
            ->assertJsonPath('data.stats.summary.total_courses', 1);
    }

    // ══ "IS SUBSCRIBED" IS Subscription::scopeActive(), EVERYWHERE ══════

    /**
     * One student in three of the teacher's courses counts ONCE; expired and
     * cancelled enrollments count zero. Both properties come from the single
     * canonical scope — backend/ re-typed the predicate three times in this
     * one feature.
     */
    public function test_student_counts_are_distinct_and_honour_the_active_scope(): void
    {
        $a = $this->makeCourseFor($this->instructor);
        $b = $this->makeCourseFor($this->instructor);
        $c = $this->makeCourseFor($this->instructor);

        $busy = $this->makeStudentUser();
        $this->makeSubscription($busy, $a);
        $this->makeSubscription($busy, $b);
        $this->makeSubscription($busy, $c);

        // Should not count: expired, then cancelled.
        $expired = $this->makeStudentUser();
        $this->makeSubscription($expired, $a, SubscriptionStatus::Active, now()->subDay());

        $cancelled = $this->makeStudentUser();
        $this->makeSubscription($cancelled, $a, SubscriptionStatus::Cancelled);

        // NULL expires_at means "never expires", NOT "expired".
        $lifetime = $this->makeStudentUser();
        $this->makeSubscription($lifetime, $b, SubscriptionStatus::Active, null);

        $this->loginAs($this->instructor);

        $this->getJson('/api/v1/teachers/statistics')
            ->assertStatus(200)
            ->assertJsonPath('data.stats.summary.total_students', 2);
    }

    /** Another teacher's enrollments never leak into these numbers. */
    public function test_another_teachers_students_are_not_counted(): void
    {
        [$other] = $this->makeTeacherWithProfile();
        $theirCourse = $this->makeCourseFor($other);
        $this->makeSubscription($this->makeStudentUser(), $theirCourse);

        $mine = $this->makeCourseFor($this->instructor);
        $this->makeSubscription($this->makeStudentUser(), $mine);

        $this->loginAs($this->instructor);

        $this->getJson('/api/v1/teachers/statistics')
            ->assertStatus(200)
            ->assertJsonPath('data.stats.summary.total_students', 1)
            ->assertJsonPath('data.stats.summary.total_courses', 1);
    }

    public function test_a_course_with_no_enrollments_still_appears_in_the_course_table(): void
    {
        $this->makeCourseFor($this->instructor, ['title' => 'كورس فارغ']);

        $this->loginAs($this->instructor);

        $this->getJson('/api/v1/teachers/statistics')
            ->assertStatus(200)
            ->assertJsonPath('data.stats.courses.0.title', 'كورس فارغ')
            ->assertJsonPath('data.stats.courses.0.enrollment_count', 0);
    }

    public function test_governorate_stats_group_the_teachers_own_students(): void
    {
        $course = $this->makeCourseFor($this->instructor);

        foreach (['القاهرة', 'القاهرة', 'الجيزة'] as $governorate) {
            $user = $this->makeUser(['role' => \App\Enums\UserRole::Student]);
            \App\Models\Student::query()->create([
                'user_id' => $user->getKey(),
                'student_code' => 'STU' . random_int(100000, 999999),
                'phone' => '01012345678',
                'parent_phone' => '01087654321',
                'governorate' => $governorate,
                'gender' => 'male',
                'education_type' => 'general',
                'grade_level' => 'الصف الأول الثانوي',
            ]);
            $this->makeSubscription($user, $course);
        }

        $this->loginAs($this->instructor);

        $stats = $this->getJson('/api/v1/teachers/statistics')
            ->assertStatus(200)
            ->json('data.stats.governorateStats');

        $this->assertSame(
            [['name' => 'القاهرة', 'count' => 2], ['name' => 'الجيزة', 'count' => 1]],
            $stats
        );
    }
}
