<?php

declare(strict_types=1);

namespace Tests\Feature\Course;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ DECISION 3.6 ON THE WIRE. A DRAFT COURSE IS **404** — NOT 403 — FOR      ║
 * ║ EVERYONE EXCEPT ITS OWNER AND AN ADMIN.                                  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 *   actor          GET /courses/{id}   GET /courses/{id}/sections
 *   ─────────────  ─────────────────   ──────────────────────────
 *   owner              200                    200
 *   admin              200                    200
 *   other teacher      404                    404
 *   student            404                    404
 *   guest              404                    404
 *
 * …for a DRAFT. For a PUBLISHED course every one of those cells is 200,
 * including the guest — the storefront must render logged-out.
 *
 * ┌── THE THREE THINGS THAT CAN GO WRONG, EACH WITH ITS OWN TEST ────────────┐
 * │                                                                          │
 * │ 1. 403 INSTEAD OF 404. A 403 on a guessed uuid confirms the id names a   │
 * │    real unreleased course — a positive oracle for enumerating a          │
 * │    competitor's catalogue. Asserted explicitly, not just "not 200".      │
 * │                                                                          │
 * │ 2. THE LIST AND THE SINGLE READ DISAGREEING. `GET /courses` filters with │
 * │    a SCOPE (no per-row policy call, which would be an N+1), so the same  │
 * │    rule exists in two places. test_the_list_and_the_single_read_never_   │
 * │    disagree walks the actual list and re-reads every id individually.    │
 * │                                                                          │
 * │ 3. THE OWNER LOSING THEIR OWN DRAFTS. Decision 3.6 hides drafts from     │
 * │    OTHERS only. /courses/my-courses and                                  │
 * │    /admin/teachers/{id}/courses must both still contain them, or an      │
 * │    instructor's dashboard reads "0 courses" the moment they unpublish.   │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class CourseVisibilityTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $owner;

    private User $otherTeacher;

    private Course $draft;

    private Course $published;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->owner] = $this->makeTeacherWithProfile();
        [$this->otherTeacher] = $this->makeTeacherWithProfile();

        $this->draft = $this->makeCourseFor($this->owner, [
            'title' => 'مسودة غير منشورة',
            'is_published' => false,
        ]);

        $this->published = $this->makeCourseFor($this->owner, [
            'title' => 'كورس منشور',
            'is_published' => true,
        ]);
    }

    // ── the draft column ──────────────────────────────────────────────────

    public function test_the_owner_reads_their_own_draft(): void
    {
        $this->loginAs($this->owner);

        $this->getJson("/api/v1/courses/{$this->draft->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'مسودة غير منشورة');
    }

    public function test_an_admin_reads_anyones_draft(): void
    {
        $this->loginAsAdmin();

        $this->getJson("/api/v1/courses/{$this->draft->getKey()}")->assertStatus(200);
    }

    /**
     * ┌── 404, AND THE ASSERTION SAYS SO EXPLICITLY. ────────────────────────┐
     * │ `assertStatus(404)` rather than `assertForbidden()`-with-a-comment:  │
     * │ the whole security value is in WHICH refusal, and a test that only   │
     * │ asserted "not 200" would pass against the oracle-leaking version.    │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_another_teacher_gets_404_not_403_for_a_draft(): void
    {
        $this->loginAs($this->otherTeacher);

        $this->getJson("/api/v1/courses/{$this->draft->getKey()}")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Course not found');
    }

    public function test_a_student_gets_404_for_a_draft(): void
    {
        $this->loginAs($this->makeStudentUser());

        $this->getJson("/api/v1/courses/{$this->draft->getKey()}")->assertStatus(404);
    }

    public function test_a_guest_gets_404_for_a_draft(): void
    {
        $this->getJson("/api/v1/courses/{$this->draft->getKey()}")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Course not found');
    }

    /**
     * The refusal for a draft must be INDISTINGUISHABLE from the refusal for
     * an id that names nothing at all. Identical status AND identical message,
     * or the oracle survives in the body.
     */
    public function test_a_draft_and_a_nonexistent_id_are_indistinguishable(): void
    {
        $draftResponse = $this->getJson("/api/v1/courses/{$this->draft->getKey()}");
        $missingResponse = $this->getJson('/api/v1/courses/00000000-0000-0000-0000-000000000000');

        $this->assertSame($missingResponse->getStatusCode(), $draftResponse->getStatusCode());
        $this->assertSame(
            $missingResponse->json('message'),
            $draftResponse->json('message'),
            'A draft must be indistinguishable from a wrong guess.'
        );
    }

    // ── the published column ──────────────────────────────────────────────

    public function test_a_guest_reads_a_published_course(): void
    {
        $this->getJson("/api/v1/courses/{$this->published->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'كورس منشور');
    }

    public function test_another_teacher_reads_a_published_course(): void
    {
        $this->loginAs($this->otherTeacher);

        $this->getJson("/api/v1/courses/{$this->published->getKey()}")->assertStatus(200);
    }

    // ── the sections cascade ──────────────────────────────────────────────

    public function test_the_syllabus_of_a_draft_is_404_for_a_guest(): void
    {
        $this->makeSection($this->draft, ['title' => 'قسم سرّي']);

        $this->getJson("/api/v1/courses/{$this->draft->getKey()}/sections")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Course not found');
    }

    public function test_the_syllabus_of_a_draft_is_readable_by_its_owner(): void
    {
        $this->makeSection($this->draft, ['title' => 'قسم سرّي']);
        $this->loginAs($this->owner);

        $this->getJson("/api/v1/courses/{$this->draft->getKey()}/sections")
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'قسم سرّي');
    }

    public function test_the_syllabus_of_a_published_course_is_public(): void
    {
        $this->makeSection($this->published, ['title' => 'الوحدة الأولى']);

        $this->getJson("/api/v1/courses/{$this->published->getKey()}/sections")
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'الوحدة الأولى');
    }

    // ── list vs single consistency ────────────────────────────────────────

    public function test_the_public_list_omits_drafts(): void
    {
        $ids = collect($this->getJson('/api/v1/courses')->json('data.items'))->pluck('id');

        $this->assertContains($this->published->getKey(), $ids->all());
        $this->assertNotContains($this->draft->getKey(), $ids->all());
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE CONSISTENCY TEST. THE LIST AND THE SINGLE READ MAY NEVER         ║
     * ║ DISAGREE — IN EITHER DIRECTION.                                       ║
     * ║                                                                      ║
     * ║ `GET /courses` filters with Course::scopePublished() and never        ║
     * ║ evaluates `view` per row (that would be an N+1 policy call on the     ║
     * ║ busiest read in the app). So the same rule lives in two places, and   ║
     * ║ nothing but this test stops them drifting.                            ║
     * ║                                                                      ║
     * ║ BOTH directions are checked, because each catches a different bug:    ║
     * ║   shown-but-404  → the catalogue advertises a page that errors        ║
     * ║   hidden-but-200 → the scope is stricter than the policy, and the     ║
     * ║                    draft is still readable by anyone with the id      ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_list_and_the_single_read_never_disagree(): void
    {
        // A second owner with one of each, so the fixture is not a single
        // teacher's catalogue answering by accident.
        [$secondTeacher] = $this->makeTeacherWithProfile();
        $extraDraft = $this->makeCourseFor($secondTeacher, ['is_published' => false]);
        $extraLive = $this->makeCourseFor($secondTeacher, ['is_published' => true]);

        $listed = collect($this->getJson('/api/v1/courses')->json('data.items'))
            ->pluck('id')
            ->all();

        $everyCourse = [
            $this->draft->getKey(),
            $this->published->getKey(),
            $extraDraft->getKey(),
            $extraLive->getKey(),
        ];

        foreach ($everyCourse as $id) {
            $status = $this->getJson("/api/v1/courses/{$id}")->getStatusCode();

            if (in_array($id, $listed, true)) {
                $this->assertSame(
                    200,
                    $status,
                    "Course {$id} is in the public list but not individually readable."
                );

                continue;
            }

            $this->assertSame(
                404,
                $status,
                "Course {$id} is hidden from the public list but individually readable."
            );
        }
    }

    // ── the owner must never lose their own drafts ────────────────────────

    public function test_my_courses_includes_the_instructors_own_drafts(): void
    {
        $this->loginAs($this->owner);

        $ids = collect($this->getJson('/api/v1/courses/my-courses')->json('data.courses'))
            ->pluck('id')
            ->all();

        $this->assertContains($this->draft->getKey(), $ids);
        $this->assertContains($this->published->getKey(), $ids);
    }

    public function test_the_admin_teacher_courses_route_includes_drafts(): void
    {
        $this->loginAsAdmin();

        $ids = collect(
            $this->getJson("/api/v1/admin/teachers/{$this->owner->getKey()}/courses")
                ->assertStatus(200)
                ->json('data.courses')
        )->pluck('id')->all();

        $this->assertContains($this->draft->getKey(), $ids);
        $this->assertContains($this->published->getKey(), $ids);
    }

    // ── soft deletes are invisible to everyone, owner included ────────────

    /**
     * Distinct from the draft rule: a TRASHED course is 404 even for its
     * owner, because SoftDeletes' global scope removes it before any policy
     * runs. CoursePolicy::view()'s docblock states this and it is worth
     * pinning — a future withTrashed() would silently reopen it.
     */
    public function test_a_soft_deleted_course_is_404_even_for_its_owner(): void
    {
        $this->published->delete();
        $this->loginAs($this->owner);

        $this->getJson("/api/v1/courses/{$this->published->getKey()}")->assertStatus(404);
        $this->getJson("/api/v1/courses/{$this->published->getKey()}/sections")->assertStatus(404);
    }
}
