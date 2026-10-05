<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE TEST HARNESS IS ITSELF A SECURITY CONTROL, SO IT IS TESTED.          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Stages 4 through 10 will call assertIsolated() dozens of times and will
 * trust its verdict. A harness that silently accepts an incomplete expectation
 * map, or that builds an actor wrongly, converts every one of those calls into
 * a green light that means nothing — the worst possible failure mode, because
 * it looks like coverage.
 *
 * So this file asserts the CONTRACT rather than any policy:
 *   * the fixture actors really are what their names claim;
 *   * a forgotten actor is a hard failure, never a default;
 *   * REJECTED_AT_AUTH_GATE really does mirror the owner's Gate answer.
 */
final class AssertsTenantIsolationContractTest extends TestCase
{
    use AssertsTenantIsolation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTenantActors();
    }

    public function test_it_builds_all_nine_actors_and_the_guest_is_genuinely_null(): void
    {
        $this->assertCount(9, self::ACTORS);

        foreach (self::ACTORS as $name) {
            if ($name === 'guest') {
                $this->assertNull($this->actor($name), 'the guest must be a real null, not a placeholder user');

                continue;
            }

            $this->assertNotNull($this->actor($name), "actor '{$name}' was not built");
        }
    }

    public function test_the_two_teachers_are_distinct_tenants_each_owning_their_own_course(): void
    {
        $this->assertNotSame($this->ownerTeacher->getKey(), $this->otherTeacher->getKey());

        $this->assertSame($this->ownerTeacher->getKey(), $this->ownerCourse->teacher_id);
        $this->assertSame($this->otherTeacher->getKey(), $this->otherCourse->teacher_id);

        // The distinction the brief insists on: otherTeacher is not an
        // abstract non-owner, they have a comparable resource of their own for
        // a leak to be visible against.
        $this->assertNotSame($this->ownerCourse->getKey(), $this->otherCourse->getKey());
    }

    public function test_the_three_subscription_actors_hold_the_rows_their_names_claim(): void
    {
        $rowFor = fn (string $actor): Subscription => Subscription::query()
            ->where('student_id', $this->actor($actor)->getKey())
            ->where('course_id', $this->ownerCourse->getKey())
            ->firstOrFail();

        $live = $rowFor('subscribedStudent');
        $this->assertSame(SubscriptionStatus::Active, $live->status);
        $this->assertNull($live->expires_at, 'a live subscription must have no expiry, not a future one');

        $expired = $rowFor('expiredSubscriber');
        $this->assertSame(
            SubscriptionStatus::Active,
            $expired->status,
            "the expired actor's status must stay 'active' — a row already marked 'expired' would "
            . 'prove nothing about scopeActive() refusing to trust a stale status'
        );
        $this->assertTrue($expired->expires_at->isPast());

        $cancelled = $rowFor('cancelledSubscriber');
        $this->assertSame(SubscriptionStatus::Cancelled, $cancelled->status);
        $this->assertTrue(
            $cancelled->expires_at->isFuture(),
            'the cancelled actor needs a FUTURE expiry — that is what catches the SQL-precedence '
            . 'bug Subscription::scopeActive() guards against'
        );

        // And the negative: nobody is subscribed to the rival's course.
        $this->assertSame(0, Subscription::query()->where('course_id', $this->otherCourse->getKey())->count());
    }

    public function test_the_unsubscribed_student_is_a_real_student_with_zero_rows(): void
    {
        $student = $this->actor('unsubscribedStudent');

        $this->assertSame(UserRole::Student, $student->role);
        $this->assertSame(0, Subscription::query()->where('student_id', $student->getKey())->count());
    }

    public function test_the_deactivated_owner_is_the_owner_row_with_only_is_active_flipped(): void
    {
        $deactivated = $this->actor('deactivatedOwner');

        $this->assertSame($this->ownerTeacher->getKey(), $deactivated->getKey());
        $this->assertSame($this->ownerTeacher->role, $deactivated->role);
        $this->assertFalse($deactivated->is_active);

        // The DATABASE row stays active: the other eight actors must be
        // unaffected, and the HTTP layer flips the column only for the
        // duration of one request.
        $this->assertTrue($this->ownerTeacher->fresh()->is_active);
    }

    // ── the guardrails ────────────────────────────────────────────────────

    public function test_an_omitted_actor_is_a_hard_failure_and_never_a_silent_default(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/missing an expectation for: cancelledSubscriber/');

        $this->assertIsolated('manage', $this->ownerCourse, [
            'admin' => true,
            'ownerTeacher' => true,
            'otherTeacher' => false,
            'subscribedStudent' => false,
            'unsubscribedStudent' => false,
            'expiredSubscriber' => false,
            // 'cancelledSubscriber' deliberately omitted
            'guest' => false,
            'deactivatedOwner' => self::REJECTED_AT_AUTH_GATE,
        ]);
    }

    public function test_an_actor_that_does_not_exist_is_rejected_rather_than_ignored(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/names actors that do not exist: ownerTeachr/');

        $this->assertIsolated('manage', $this->ownerCourse, [
            'admin' => true,
            'ownerTeachr' => true, // typo — must not be read as "ownerTeacher unstated"
            'ownerTeacher' => true,
            'otherTeacher' => false,
            'subscribedStudent' => false,
            'unsubscribedStudent' => false,
            'expiredSubscriber' => false,
            'cancelledSubscriber' => false,
            'guest' => false,
            'deactivatedOwner' => self::REJECTED_AT_AUTH_GATE,
        ]);
    }

    public function test_supplying_a_method_without_a_uri_is_refused_rather_than_skipping_the_http_layer(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/must be supplied together/');

        $this->assertIsolated('manage', $this->ownerCourse, [
            'admin' => true,
            'ownerTeacher' => true,
            'otherTeacher' => false,
            'subscribedStudent' => false,
            'unsubscribedStudent' => false,
            'expiredSubscriber' => false,
            'cancelledSubscriber' => false,
            'guest' => false,
            'deactivatedOwner' => self::REJECTED_AT_AUTH_GATE,
        ], 'PATCH');
    }

    /**
     * The logical layer must be usable ALONE — an ability with no route yet
     * (every skeleton policy in Stages 4-10 starts there) still gets its
     * nine-actor proof, and adding the route later only widens it.
     */
    public function test_the_logical_layer_runs_without_a_route(): void
    {
        $this->assertIsolated('viewHidden', $this->ownerCourse, [
            'admin' => true,
            'ownerTeacher' => true,
            'otherTeacher' => false,
            'subscribedStudent' => false,
            'unsubscribedStudent' => false,
            'expiredSubscriber' => false,
            'cancelledSubscriber' => false,
            'guest' => false,
            'deactivatedOwner' => self::REJECTED_AT_AUTH_GATE,
        ]);
    }
}
