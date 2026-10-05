<?php

declare(strict_types=1);

namespace Tests\Feature\Course;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ SUSPENDING A TEACHER MUST TAKE THEIR CATALOGUE OFF SALE TOO.            ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * `users.is_active = 0` already removed the instructor from the public
 * directory (Teacher::scopePubliclyVisible). It did NOT remove their published
 * courses: the catalogue kept listing them, `GET /courses/{id}` kept answering
 * 200, and every card still rendered the suspended teacher's name and avatar —
 * because Course::scopePublished() and CoursePolicy::view() both spoke only of
 * `courses.is_published`.
 *
 *   actor                     listed?  GET /courses/{id}  /structure  /exams/{id}
 *   ────────────────────────  ───────  ─────────────────  ──────────  ───────────
 *   guest                       no           404             404          404
 *   unrelated student           no           404             404          404
 *   unrelated teacher           no           404             404          404
 *   admin                       —            200             200          200
 *   the suspended owner         —          allowed         allowed      allowed
 *
 * ┌── THE LAST TWO ROWS ARE THE POINT OF HALF THIS FILE. ────────────────────┐
 * │ The fix belongs in the PUBLIC branch of the two policies and nowhere     │
 * │ else. If it leaked into the ownership branch — or worse, above           │
 * │ Gate::before — an admin would lose the management screens they need in   │
 * │ order to review or un-suspend the account, which is a far worse          │
 * │ regression than the leak being closed. Both are asserted, not assumed.   │
 * │                                                                          │
 * │ The owner's own row is asserted through the GATE rather than over HTTP    │
 * │ on purpose: EnsureUserIsActive ends a suspended account's session at the  │
 * │ middleware with 401 (SuspensionTakesEffectTest owns that), so no HTTP     │
 * │ request can reach the policy to prove the branch survived. The Gate call  │
 * │ is the only way to observe it, and observing it is what stops a future    │
 * │ edit from "simplifying" the owner out of their own material.             │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NEGATIVE CONTROL THROUGHOUT: every denial below is paired with the SAME
 * fixture read back while the account is active. Without that pair a change
 * that hid every teacher's content would pass this file completely.
 */
final class SuspendedTeacherContentVisibilityTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $owner;

    private Course $course;

    private string $examId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->owner] = $this->makeTeacherWithProfile();

        $this->course = $this->makeCourseFor($this->owner, [
            'title' => 'كورس مدرّس موقوف',
            'is_published' => true,
        ]);

        $section = $this->makeSection($this->course, ['title' => 'الوحدة الأولى']);

        [, $exam] = $this->makeExam($section);
        $this->examId = (string) $exam->getKey();
    }

    /** The one write this file is about. Nothing else changes. */
    private function suspendTheOwner(): void
    {
        $this->owner->forceFill(['is_active' => false])->save();
        $this->app['auth']->forgetGuards();
    }

    // ══ 1. THE PUBLIC LISTING ═══════════════════════════════════════════════

    public function test_the_public_catalogue_drops_a_suspended_teachers_course(): void
    {
        $this->suspendTheOwner();

        $ids = collect($this->getJson('/api/v1/courses')->json('data.items'))->pluck('id')->all();

        $this->assertNotContains($this->course->getKey(), $ids);
    }

    /** NEGATIVE CONTROL: the same course, same query, an active account. */
    public function test_the_public_catalogue_keeps_an_active_teachers_course(): void
    {
        $ids = collect($this->getJson('/api/v1/courses')->json('data.items'))->pluck('id')->all();

        $this->assertContains($this->course->getKey(), $ids);
    }

    /**
     * A DELETED account is the other revocation and must behave identically —
     * the whereHas/EXISTS pair leans on User's SoftDeletes global scope for it
     * rather than a hand-written `deleted_at IS NULL` that can be forgotten.
     */
    public function test_a_deleted_teachers_course_leaves_the_catalogue_too(): void
    {
        $this->owner->delete();
        $this->app['auth']->forgetGuards();

        $ids = collect($this->getJson('/api/v1/courses')->json('data.items'))->pluck('id')->all();

        $this->assertNotContains($this->course->getKey(), $ids);
        $this->getJson("/api/v1/courses/{$this->course->getKey()}")->assertStatus(404);
    }

    // ══ 2. THE SINGLE READ, THE OUTLINE AND THE EXAM ════════════════════════

    /**
     * 404 AND THE CONTRACT MESSAGE — the same refusal an unpublished course
     * gets (CourseVisibilityTest pins that shape). A 403 here would be a
     * positive oracle confirming the id names a real course.
     */
    public function test_a_guest_gets_404_on_every_public_read(): void
    {
        $this->suspendTheOwner();

        $this->getJson("/api/v1/courses/{$this->course->getKey()}")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Course not found');

        $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Course not found');

        $this->getJson("/api/v1/courses/{$this->course->getKey()}/sections")
            ->assertStatus(404);
    }

    /**
     * NOT JUST THE GUEST. A logged-in but unrelated caller is the actor a
     * `$user === null` shaped fix would silently keep serving.
     */
    public function test_an_unrelated_student_and_teacher_also_get_404(): void
    {
        $this->suspendTheOwner();

        [$otherTeacher] = $this->makeTeacherWithProfile();

        foreach ([$this->makeStudentUser(), $otherTeacher] as $actor) {
            $this->app['auth']->forgetGuards();
            $this->loginAs($actor);
            $this->startNextRequest();

            $this->getJson("/api/v1/courses/{$this->course->getKey()}")->assertStatus(404);
            $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")->assertStatus(404);
            $this->getJson("/api/v1/exams/{$this->examId}")->assertStatus(404);
        }
    }

    /**
     * A SUBSCRIBED student loses the storefront page too. Their enrolment buys
     * content access, not an exemption from the course being off sale — and
     * this is the actor most likely to be let through by a fix that reached for
     * canConsume() instead of the public branch.
     */
    public function test_even_a_subscribed_student_gets_404(): void
    {
        $subscriber = $this->makeStudentUser();
        $this->makeSubscription($subscriber, $this->course);

        $this->suspendTheOwner();
        $this->loginAs($subscriber);
        $this->startNextRequest();

        $this->getJson("/api/v1/courses/{$this->course->getKey()}")->assertStatus(404);
    }

    /** GET /exams/{id} carries its OWN copy of the public predicate. */
    public function test_the_exam_surface_cascades(): void
    {
        $this->getJson("/api/v1/exams/{$this->examId}")->assertStatus(200);
        $this->getJson("/api/v1/courses/{$this->course->getKey()}/exams")->assertStatus(200);

        $this->suspendTheOwner();

        $this->getJson("/api/v1/exams/{$this->examId}")->assertStatus(404);
        $this->getJson("/api/v1/courses/{$this->course->getKey()}/exams")->assertStatus(404);
    }

    /**
     * A content item is reached by its own id, and ContentItemController::show()
     * cascades through CourseAccess::assertVisible() — so it needs no rule of
     * its own. This test is what proves that claim rather than trusting it.
     */
    public function test_the_content_item_surface_cascades(): void
    {
        $item = $this->makeContentItem(
            $this->course->sections()->firstOrFail(),
            ['title' => 'درس عام']
        );

        $this->getJson("/api/v1/content-items/{$item->getKey()}")->assertStatus(200);

        $this->suspendTheOwner();

        $this->getJson("/api/v1/content-items/{$item->getKey()}")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Course not found');
    }

    /** NEGATIVE CONTROL for the whole block above. */
    public function test_an_active_teachers_course_reads_normally_everywhere(): void
    {
        $this->getJson("/api/v1/courses/{$this->course->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'كورس مدرّس موقوف');

        $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
            ->assertStatus(200)
            ->assertJsonPath('data.sections.0.title', 'الوحدة الأولى');

        $this->getJson("/api/v1/exams/{$this->examId}")->assertStatus(200);
    }

    /**
     * The list and the single read must still agree AFTER the suspension —
     * the scope and the policy now carry a second clause each, which is a
     * second chance for them to drift.
     */
    public function test_the_list_and_the_single_read_still_agree_after_a_suspension(): void
    {
        [$liveTeacher] = $this->makeTeacherWithProfile();
        $liveCourse = $this->makeCourseFor($liveTeacher, ['is_published' => true]);

        $this->suspendTheOwner();

        $listed = collect($this->getJson('/api/v1/courses')->json('data.items'))->pluck('id')->all();

        foreach ([$this->course->getKey(), $liveCourse->getKey()] as $id) {
            $status = $this->getJson("/api/v1/courses/{$id}")->getStatusCode();

            $this->assertSame(
                in_array($id, $listed, true) ? 200 : 404,
                $status,
                "Course {$id}: the public list and the single read disagree."
            );
        }
    }

    // ══ 3. WHAT MUST **NOT** HAVE CHANGED ═══════════════════════════════════

    /** Gate::before's admin bypass still answers first, on all three surfaces. */
    public function test_an_admin_still_reads_a_suspended_teachers_course(): void
    {
        $this->suspendTheOwner();
        $this->loginAsAdmin();
        $this->startNextRequest();

        $this->getJson("/api/v1/courses/{$this->course->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'كورس مدرّس موقوف');

        $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")->assertStatus(200);
        $this->getJson("/api/v1/exams/{$this->examId}")->assertStatus(200);
    }

    /** …and the admin's course-management listing for that teacher still works. */
    public function test_the_admin_teacher_courses_route_still_lists_it(): void
    {
        $this->suspendTheOwner();
        $this->loginAsAdmin();
        $this->startNextRequest();

        $ids = collect(
            $this->getJson("/api/v1/admin/teachers/{$this->owner->getKey()}/courses")
                ->assertStatus(200)
                ->json('data.courses')
        )->pluck('id')->all();

        $this->assertContains($this->course->getKey(), $ids);
    }

    /**
     * THE OWNERSHIP BRANCH SURVIVES. Asked through the Gate because
     * EnsureUserIsActive would 401 the suspended account's HTTP session long
     * before any policy ran — see this class's docblock.
     */
    public function test_the_suspended_owner_still_passes_the_ownership_branch(): void
    {
        $this->suspendTheOwner();

        $owner = $this->owner->fresh();
        $course = $this->course->fresh();

        $this->assertTrue(Gate::forUser($owner)->allows('view', $course));
        $this->assertTrue(Gate::forUser($owner)->allows('manage', $course));
        $this->assertTrue(Gate::forUser($owner)->allows('viewHidden', $course));
        $this->assertTrue(Gate::forUser($owner)->allows('consumeContent', $course));
    }

    /** The scope that feeds the instructor's own dashboard is untouched. */
    public function test_owned_by_still_returns_the_suspended_teachers_courses(): void
    {
        $this->suspendTheOwner();

        $this->assertSame(
            [$this->course->getKey()],
            Course::query()->ownedBy($this->owner->fresh())->pluck('id')->all()
        );
    }
}
