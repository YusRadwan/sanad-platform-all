<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\SubscriptionStatus;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * CoursePolicy through the real Gate.
 *
 * The centrepiece is
 * test_the_three_abilities_are_independently_distinguishable_for_a_subscribed_student:
 * it is the argument against collapsing manage/consumeContent/viewHidden into
 * one `view`, expressed as an executable assertion.
 */
final class CoursePolicyTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $owner;

    private Course $draft;

    private Course $published;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();

        $this->owner = $this->makeInstructor();
        $this->draft = $this->makeCourseFor($this->owner, ['is_published' => false]);
        $this->published = $this->makeCourseFor($this->owner, ['is_published' => true]);
    }

    // ── viewAny ───────────────────────────────────────────────────────────

    public function test_viewany_is_open_to_every_role_and_to_guests(): void
    {
        $this->assertTrue(Gate::forUser(null)->allows('viewAny', Course::class));
        $this->assertTrue(Gate::forUser($this->makeStudentUser())->allows('viewAny', Course::class));
        $this->assertTrue(Gate::forUser($this->owner)->allows('viewAny', Course::class));
        $this->assertTrue(Gate::forUser($this->makeAdmin())->allows('viewAny', Course::class));
    }

    // ── view ──────────────────────────────────────────────────────────────

    public function test_a_published_course_is_visible_to_a_guest(): void
    {
        $this->assertTrue(Gate::forUser(null)->allows('view', $this->published));
    }

    public function test_an_unpublished_course_is_not_visible_to_a_guest(): void
    {
        $this->assertFalse(Gate::forUser(null)->allows('view', $this->draft));
    }

    public function test_an_unpublished_course_is_visible_to_its_owner(): void
    {
        $this->assertTrue(Gate::forUser($this->owner)->allows('view', $this->draft));
    }

    public function test_an_unpublished_course_is_not_visible_to_another_instructor(): void
    {
        $this->assertFalse(Gate::forUser($this->makeInstructor())->allows('view', $this->draft));
    }

    /**
     * A SUBSCRIBED student still cannot see a DRAFT. Subscribing is not a
     * substitute for the course having been published.
     */
    public function test_an_unpublished_course_is_not_visible_to_a_subscribed_student(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->draft, SubscriptionStatus::Active, null);

        $this->assertFalse(Gate::forUser($student)->allows('view', $this->draft));
    }

    /**
     * `is_published` really is a boolean by the time the policy branches on it
     * — the cast, not MySQL's TINYINT int, is what the `if` sees.
     */
    public function test_is_published_is_a_real_boolean_after_a_round_trip(): void
    {
        $fresh = Course::query()->findOrFail($this->published->getKey());

        $this->assertIsBool($fresh->is_published);
        $this->assertTrue($fresh->is_published);
    }

    // ── manage ────────────────────────────────────────────────────────────

    public function test_only_the_owner_may_manage(): void
    {
        $this->assertTrue(Gate::forUser($this->owner)->allows('manage', $this->published));
        $this->assertFalse(Gate::forUser($this->makeInstructor())->allows('manage', $this->published));
        $this->assertFalse(Gate::forUser($this->makeStudentUser())->allows('manage', $this->published));
    }

    /** A guest is denied by the non-nullable `User` hint, before any body runs. */
    public function test_a_guest_may_not_manage(): void
    {
        $this->assertFalse(Gate::forUser(null)->allows('manage', $this->published));
    }

    // ── consumeContent / viewHidden ───────────────────────────────────────

    public function test_the_owner_passes_consume_and_hidden(): void
    {
        $this->assertTrue(Gate::forUser($this->owner)->allows('consumeContent', $this->published));
        $this->assertTrue(Gate::forUser($this->owner)->allows('viewHidden', $this->published));
    }

    public function test_an_unsubscribed_student_passes_neither(): void
    {
        $student = $this->makeStudentUser();

        $this->assertFalse(Gate::forUser($student)->allows('consumeContent', $this->published));
        $this->assertFalse(Gate::forUser($student)->allows('viewHidden', $this->published));
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ ONE ACTOR, ONE COURSE, THREE DIFFERENT ANSWERS.                      │
     * │                                                                      │
     * │   consumeContent = TRUE   they paid for the content                  │
     * │   viewHidden     = FALSE  drafts are the instructor's workbench      │
     * │   manage         = FALSE  it is not their course                     │
     * │                                                                      │
     * │ This is the whole reason the four abilities are not one `view`: any  │
     * │ merged ability must return a single value here and is therefore      │
     * │ wrong on two of the three questions. Collapse them and this test     │
     * │ fails immediately.                                                   │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_the_three_abilities_are_independently_distinguishable_for_a_subscribed_student(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->published, SubscriptionStatus::Active, null);

        $gate = Gate::forUser($student);

        $this->assertTrue($gate->allows('consumeContent', $this->published), 'A paying student may consume.');
        $this->assertFalse($gate->allows('viewHidden', $this->published), 'A paying student may NOT see hidden material.');
        $this->assertFalse($gate->allows('manage', $this->published), 'A paying student may NOT manage.');
    }

    /** An expired subscription loses consumeContent and never had the others. */
    public function test_an_expired_subscriber_loses_consume_content(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->published, SubscriptionStatus::Active, now()->subDay());

        $this->assertFalse(Gate::forUser($student)->allows('consumeContent', $this->published));
    }

    /** Cross-tenant: another instructor gets nothing at all. */
    public function test_another_instructor_gets_nothing(): void
    {
        $gate = Gate::forUser($this->makeInstructor());

        foreach (['manage', 'consumeContent', 'viewHidden'] as $ability) {
            $this->assertFalse(
                $gate->allows($ability, $this->published),
                "A foreign instructor must fail '{$ability}'."
            );
        }
    }

    // ── The 403 mapping, end to end ───────────────────────────────────────

    /**
     * A policy denial really does become a 403 envelope through Stage 0's
     * handler — the default this stage relies on, and the counterpart to
     * DenyModeTest's 404.
     */
    public function test_a_denied_ability_maps_to_a_403_envelope(): void
    {
        \Illuminate\Support\Facades\Route::middleware('api')->get(
            '/api/v1/__test/course-manage/{course}',
            fn (Course $course) => \App\Http\Responses\ApiResponse::success()
        )->middleware('can:manage,course');

        $this->actingAs($this->makeInstructor())
            ->getJson("/api/v1/__test/course-manage/{$this->published->getKey()}")
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Forbidden');
    }
}
