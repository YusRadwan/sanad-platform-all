<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\SubscriptionStatus;
use App\Models\Course;
use App\Models\User;
use App\Policies\Concerns\ResolvesCourseOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * The engine trait against REAL MySQL rows.
 *
 * Deliberately a Feature test and not a Unit one: hasActiveSubscription()
 * delegates to Subscription::scopeActive(), whose whole subject is how MySQL
 * evaluates `status = 'active' AND (expires_at IS NULL OR expires_at > NOW())`
 * — including the operator-precedence trap the closure exists to close. Faking
 * the query builder would test the mock.
 *
 * The pure type-matrix half of ownsCourse() lives in
 * tests/Unit/Authorization/OwnsCourseTypeTest.php, where no database can get in
 * the way of putting an int in an id column.
 */
final class ResolvesCourseOwnershipTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private object $engine;

    private User $owner;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();

        $this->engine = new class {
            use ResolvesCourseOwnership {
                ownsCourse as public callOwnsCourse;
                hasActiveSubscription as public callHasActiveSubscription;
                canConsume as public callCanConsume;
                canSeeHidden as public callCanSeeHidden;
            }
        };

        $this->owner = $this->makeInstructor();
        $this->course = $this->makeCourseFor($this->owner);
    }

    // ── ownsCourse ────────────────────────────────────────────────────────

    public function test_the_owning_instructor_owns_the_course(): void
    {
        $this->assertTrue($this->engine->callOwnsCourse($this->owner, $this->course));
    }

    public function test_a_different_instructor_does_not_own_the_course(): void
    {
        $this->assertFalse(
            $this->engine->callOwnsCourse($this->makeInstructor(), $this->course)
        );
    }

    /**
     * The value in courses.teacher_id round-trips through MySQL as the same
     * string the user carries — the premise ownsCourse() rests on, asserted
     * rather than assumed.
     */
    public function test_teacher_id_round_trips_as_the_owning_users_id(): void
    {
        $fresh = Course::query()->findOrFail($this->course->getKey());

        $this->assertSame((string) $this->owner->getKey(), (string) $fresh->teacher_id);
    }

    // ── hasActiveSubscription: the six-row matrix ─────────────────────────

    /** ACTIVE + no expiry (NULL) = a permanent enrollment. TRUE. */
    public function test_active_with_no_expiry_is_subscribed(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->course, SubscriptionStatus::Active, null);

        $this->assertTrue($this->engine->callHasActiveSubscription($student, $this->course));
    }

    /** ACTIVE + expiry in the future. TRUE. */
    public function test_active_with_a_future_expiry_is_subscribed(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->course, SubscriptionStatus::Active, now()->addDay());

        $this->assertTrue($this->engine->callHasActiveSubscription($student, $this->course));
    }

    /**
     * ACTIVE + expiry in the PAST. FALSE.
     *
     * The row still SAYS 'active' — whatever job flips it to 'expired' has not
     * run. Access control must not wait for that job, which is why scopeActive
     * re-checks the date instead of trusting the stored status alone.
     */
    public function test_active_with_a_past_expiry_is_not_subscribed(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->course, SubscriptionStatus::Active, now()->subSecond());

        $this->assertFalse($this->engine->callHasActiveSubscription($student, $this->course));
    }

    /** CANCELLED. FALSE. */
    public function test_a_cancelled_subscription_is_not_subscribed(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->course, SubscriptionStatus::Cancelled, null);

        $this->assertFalse($this->engine->callHasActiveSubscription($student, $this->course));
    }

    /** Status EXPIRED. FALSE. */
    public function test_an_expired_status_is_not_subscribed(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->course, SubscriptionStatus::Expired, null);

        $this->assertFalse($this->engine->callHasActiveSubscription($student, $this->course));
    }

    /** No row at all. FALSE — the default is closed. */
    public function test_no_subscription_row_is_not_subscribed(): void
    {
        $this->assertFalse(
            $this->engine->callHasActiveSubscription($this->makeStudentUser(), $this->course)
        );
    }

    /**
     * ┌── THE OPERATOR-PRECEDENCE REGRESSION. ──────────────────────────────┐
     * │ Written without the closure, the predicate compiles to              │
     * │   (status='active' AND expires_at IS NULL) OR expires_at > NOW()    │
     * │ and a CANCELLED row carrying a FUTURE expiry satisfies the trailing │
     * │ OR — granting course access to a student who cancelled. This test   │
     * │ is the one that fails if the parentheses in                          │
     * │ Subscription::scopeActive() are ever "tidied away".                  │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_cancelled_subscription_with_a_future_expiry_is_still_not_subscribed(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->course, SubscriptionStatus::Cancelled, now()->addYear());

        $this->assertFalse($this->engine->callHasActiveSubscription($student, $this->course));
    }

    /** A live subscription to a DIFFERENT course grants nothing here. */
    public function test_a_subscription_to_another_course_does_not_leak(): void
    {
        $student = $this->makeStudentUser();
        $other = $this->makeCourseFor($this->makeInstructor());
        $this->makeSubscription($student, $other, SubscriptionStatus::Active, null);

        $this->assertFalse($this->engine->callHasActiveSubscription($student, $this->course));
        $this->assertTrue($this->engine->callHasActiveSubscription($student, $other));
    }

    // ── canConsume ────────────────────────────────────────────────────────

    public function test_the_owner_can_consume_their_own_course(): void
    {
        $this->assertTrue($this->engine->callCanConsume($this->owner, $this->course));
    }

    public function test_an_active_subscriber_can_consume(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->course, SubscriptionStatus::Active, null);

        $this->assertTrue($this->engine->callCanConsume($student, $this->course));
    }

    public function test_an_expired_subscriber_cannot_consume(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->course, SubscriptionStatus::Active, now()->subDay());

        $this->assertFalse($this->engine->callCanConsume($student, $this->course));
    }

    public function test_a_cancelled_subscriber_cannot_consume(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->course, SubscriptionStatus::Cancelled, null);

        $this->assertFalse($this->engine->callCanConsume($student, $this->course));
    }

    public function test_another_teacher_cannot_consume(): void
    {
        $this->assertFalse($this->engine->callCanConsume($this->makeInstructor(), $this->course));
    }

    public function test_an_unsubscribed_student_cannot_consume(): void
    {
        $this->assertFalse($this->engine->callCanConsume($this->makeStudentUser(), $this->course));
    }

    /**
     * THE ROLE HALF OF canConsume(), isolated.
     *
     * A subscription row belonging to an INSTRUCTOR account cannot exist by the
     * FK (subscriptions.student_id -> students.user_id), so this test gives the
     * instructor a `students` row as well — a state a data migration or a
     * mis-scoped admin action could produce — and proves the role check still
     * refuses to promote them into a course they do not own. Belt and braces:
     * one bad row must not be enough.
     */
    public function test_a_subscription_row_alone_does_not_admit_a_non_student(): void
    {
        $intruder = $this->makeInstructor();

        $this->attachStudentProfile($intruder);

        $this->makeSubscription($intruder, $this->course, SubscriptionStatus::Active, null);

        $this->assertTrue(
            $this->engine->callHasActiveSubscription($intruder, $this->course),
            'The row genuinely exists and is active…'
        );
        $this->assertFalse(
            $this->engine->callCanConsume($intruder, $this->course),
            '…but a non-student must still be refused.'
        );
    }

    // ── canSeeHidden: THE CRITICAL ONE ────────────────────────────────────

    public function test_the_owner_can_see_hidden_content(): void
    {
        $this->assertTrue($this->engine->callCanSeeHidden($this->owner, $this->course));
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE SINGLE MOST IMPORTANT ASSERTION IN STAGE 3.                      │
     * │                                                                      │
     * │ This student is fully paid up: an ACTIVE subscription with no expiry.│
     * │ canConsume() is TRUE for them (asserted alongside, so the fixture    │
     * │ cannot silently rot into an inactive one and make this pass          │
     * │ vacuously). canSeeHidden() must still be FALSE.                      │
     * │                                                                      │
     * │ Hidden is the instructor's workbench — drafts, unfinished exams,     │
     * │ next term's sections. Widening canSeeHidden() to canConsume() would  │
     * │ hand all of it to every enrolled student, silently. If someone       │
     * │ "simplifies" that method, this test is what stops them.              │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_fully_active_subscriber_still_cannot_see_hidden_content(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->course, SubscriptionStatus::Active, null);

        $this->assertTrue(
            $this->engine->callCanConsume($student, $this->course),
            'Precondition: this student really is entitled to the course content.'
        );

        $this->assertFalse(
            $this->engine->callCanSeeHidden($student, $this->course),
            'A subscribed student must NEVER see hidden/draft material.'
        );
    }

    public function test_another_teacher_cannot_see_hidden_content(): void
    {
        $this->assertFalse($this->engine->callCanSeeHidden($this->makeInstructor(), $this->course));
    }
}
