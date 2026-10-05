<?php

declare(strict_types=1);

namespace Tests\Feature\Course;

use App\Models\Course;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * Sections — freeze-table rows 15-19.
 *
 * The decision-3.6 cascade on the public read lives in CourseVisibilityTest;
 * what is here is the write surface, the reorder tolerance and the
 * soft-deleted-course edge case.
 */
final class SectionCrudTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $owner;

    private User $intruder;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->owner] = $this->makeTeacherWithProfile();
        [$this->intruder] = $this->makeTeacherWithProfile();

        $this->course = $this->makeCourseFor($this->owner, ['is_published' => true]);
    }

    // ── create ────────────────────────────────────────────────────────────

    public function test_the_owner_creates_a_section(): void
    {
        $this->loginAs($this->owner);

        $this->postJson("/api/v1/courses/{$this->course->getKey()}/sections", [
            'title' => 'الوحدة الأولى',
        ])
            ->assertStatus(201)
            ->assertJsonPath('message', 'تم إنشاء القسم بنجاح')
            ->assertJsonPath('data.title', 'الوحدة الأولى')
            ->assertJsonPath('data.course_id', $this->course->getKey())
            // COALESCE(MAX(order_index), -1) + 1 — the FIRST section is 0.
            ->assertJsonPath('data.order_index', 0);
    }

    public function test_order_index_increments_from_the_current_maximum(): void
    {
        $this->loginAs($this->owner);
        $url = "/api/v1/courses/{$this->course->getKey()}/sections";

        $this->postJson($url, ['title' => 'أولى'])->assertJsonPath('data.order_index', 0);
        $this->postJson($url, ['title' => 'ثانية'])->assertJsonPath('data.order_index', 1);
        $this->postJson($url, ['title' => 'ثالثة'])->assertJsonPath('data.order_index', 2);
    }

    /**
     * A section counts per COURSE, not globally — otherwise a busy platform's
     * first section on a new course would start at some arbitrary number.
     */
    public function test_order_index_is_scoped_per_course(): void
    {
        $this->makeSection($this->course, ['order_index' => 7]);
        $second = $this->makeCourseFor($this->owner);

        $this->loginAs($this->owner);

        $this->postJson("/api/v1/courses/{$second->getKey()}/sections", ['title' => 'أولى'])
            ->assertJsonPath('data.order_index', 0);
    }

    /** backend/ answered a 500 here ("Undefined array key"); it is a 422 now. */
    public function test_a_section_without_a_title_is_422_not_500(): void
    {
        $this->loginAs($this->owner);

        $this->postJson("/api/v1/courses/{$this->course->getKey()}/sections", [])
            ->assertStatus(422);
    }

    // ── cross-tenant isolation ────────────────────────────────────────────

    public function test_another_teacher_cannot_add_a_section(): void
    {
        $this->loginAs($this->intruder);

        $this->postJson("/api/v1/courses/{$this->course->getKey()}/sections", ['title' => 'تسلل'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not own this course');

        $this->assertSame(0, Section::query()->where('course_id', $this->course->getKey())->count());
    }

    public function test_another_teacher_cannot_rename_a_section(): void
    {
        $section = $this->makeSection($this->course, ['title' => 'الأصلي']);
        $this->loginAs($this->intruder);

        $this->putJson("/api/v1/sections/{$section->getKey()}", ['title' => 'مسروق'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not own this course');

        $this->assertSame('الأصلي', $section->refresh()->getAttribute('title'));
    }

    public function test_another_teacher_cannot_delete_a_section(): void
    {
        $section = $this->makeSection($this->course);
        $this->loginAs($this->intruder);

        $this->deleteJson("/api/v1/sections/{$section->getKey()}")->assertStatus(403);

        $this->assertDatabaseHas('sections', ['id' => $section->getKey()]);
    }

    public function test_another_teacher_cannot_reorder(): void
    {
        $a = $this->makeSection($this->course, ['order_index' => 0]);
        $b = $this->makeSection($this->course, ['order_index' => 1]);
        $this->loginAs($this->intruder);

        $this->patchJson("/api/v1/courses/{$this->course->getKey()}/sections/reorder", [
            'order' => [$b->getKey(), $a->getKey()],
        ])->assertStatus(403);

        $this->assertSame(0, (int) $a->refresh()->getAttribute('order_index'));
        $this->assertSame(1, (int) $b->refresh()->getAttribute('order_index'));
    }

    public function test_an_admin_may_manage_any_teachers_sections(): void
    {
        $section = $this->makeSection($this->course, ['title' => 'الأصلي']);
        $this->loginAsAdmin();

        $this->putJson("/api/v1/sections/{$section->getKey()}", ['title' => 'تعديل إداري'])
            ->assertStatus(200);

        $this->assertSame('تعديل إداري', $section->refresh()->getAttribute('title'));
    }

    // ── update ────────────────────────────────────────────────────────────

    public function test_the_owner_renames_a_section(): void
    {
        $section = $this->makeSection($this->course, ['title' => 'قديم']);
        $this->loginAs($this->owner);

        $this->putJson("/api/v1/sections/{$section->getKey()}", ['title' => 'جديد'])
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم تحديث القسم بنجاح')
            ->assertJsonPath('data.title', 'جديد');
    }

    public function test_an_absent_title_leaves_the_section_alone(): void
    {
        $section = $this->makeSection($this->course, ['title' => 'ثابت']);
        $this->loginAs($this->owner);

        $this->putJson("/api/v1/sections/{$section->getKey()}", [])->assertStatus(200);

        $this->assertSame('ثابت', $section->refresh()->getAttribute('title'));
    }

    public function test_an_unknown_section_id_is_404_with_the_contract_message(): void
    {
        $this->loginAs($this->owner);

        $this->putJson('/api/v1/sections/00000000-0000-0000-0000-000000000000', ['title' => 'لا شيء'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Section not found');
    }

    // ── reorder ───────────────────────────────────────────────────────────

    public function test_the_owner_reorders_sections(): void
    {
        $a = $this->makeSection($this->course, ['order_index' => 0]);
        $b = $this->makeSection($this->course, ['order_index' => 1]);
        $c = $this->makeSection($this->course, ['order_index' => 2]);

        $this->loginAs($this->owner);

        $this->patchJson("/api/v1/courses/{$this->course->getKey()}/sections/reorder", [
            'order' => [$c->getKey(), $a->getKey(), $b->getKey()],
        ])
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم إعادة ترتيب الأقسام بنجاح');

        $this->assertSame(0, (int) $c->refresh()->getAttribute('order_index'));
        $this->assertSame(1, (int) $a->refresh()->getAttribute('order_index'));
        $this->assertSame(2, (int) $b->refresh()->getAttribute('order_index'));
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ A SECTION ID FROM ANOTHER COURSE IS SILENTLY IGNORED — AND THE       ║
     * ║ REQUEST STILL ANSWERS 200.                                            ║
     * ║                                                                      ║
     * ║ Two separate promises in one test, because they fail separately:      ║
     * ║                                                                      ║
     * ║  * ZERO EFFECT. The UPDATE is scoped `WHERE id = ? AND course_id = ?`,║
     * ║    so a foreign id matches no row. With `id` alone, an instructor who ║
     * ║    legitimately owns THIS course could renumber another teacher's     ║
     * ║    syllabus — the ownership check would have passed, because the      ║
     * ║    course they named really is theirs.                                ║
     * ║                                                                      ║
     * ║  * STILL 200. The tolerance is the ported contract: drag-and-drop can ║
     * ║    post a stale list, and a 422 would break a working flow. It also   ║
     * ║    avoids leaking whether an arbitrary uuid names a real section.     ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_foreign_section_id_is_silently_ignored_and_the_request_still_succeeds(): void
    {
        $mine = $this->makeSection($this->course, ['order_index' => 0]);

        $theirCourse = $this->makeCourseFor($this->intruder);
        $theirs = $this->makeSection($theirCourse, ['order_index' => 5]);

        $this->loginAs($this->owner);

        $this->patchJson("/api/v1/courses/{$this->course->getKey()}/sections/reorder", [
            // The foreign id sits at position 0, so a naive implementation
            // would write order_index = 0 onto another teacher's section.
            'order' => [$theirs->getKey(), $mine->getKey()],
        ])->assertStatus(200);

        $this->assertSame(
            5,
            (int) $theirs->refresh()->getAttribute('order_index'),
            'A section from another course must not be touched.'
        );
        $this->assertSame(1, (int) $mine->refresh()->getAttribute('order_index'));
    }

    /** An absent `order` key is an empty reorder, not an error. */
    public function test_an_absent_order_key_is_a_successful_no_op(): void
    {
        $section = $this->makeSection($this->course, ['order_index' => 3]);
        $this->loginAs($this->owner);

        $this->patchJson("/api/v1/courses/{$this->course->getKey()}/sections/reorder", [])
            ->assertStatus(200);

        $this->assertSame(3, (int) $section->refresh()->getAttribute('order_index'));
    }

    /** A uuid that names nothing at all is equally tolerated. */
    public function test_a_nonexistent_id_in_the_order_is_tolerated(): void
    {
        $section = $this->makeSection($this->course, ['order_index' => 0]);
        $this->loginAs($this->owner);

        $this->patchJson("/api/v1/courses/{$this->course->getKey()}/sections/reorder", [
            'order' => ['00000000-0000-0000-0000-000000000000', $section->getKey()],
        ])->assertStatus(200);

        $this->assertSame(1, (int) $section->refresh()->getAttribute('order_index'));
    }

    // ── delete ────────────────────────────────────────────────────────────

    public function test_the_owner_deletes_a_section_hard(): void
    {
        $section = $this->makeSection($this->course);
        $this->loginAs($this->owner);

        $this->deleteJson("/api/v1/sections/{$section->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم حذف القسم بنجاح');

        // HARD delete — `sections` has no deleted_at column.
        $this->assertDatabaseMissing('sections', ['id' => $section->getKey()]);
    }

    // ── the soft-deleted-course edge case ─────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ A SECTION WHOSE COURSE WAS SOFT-DELETED IS **404**, NOT A 500.       ║
     * ║                                                                      ║
     * ║ Deleting a course leaves its sections in place on purpose, and        ║
     * ║ Course's SoftDeletes global scope then makes `$section->course` null. ║
     * ║ Anything that dereferences that null is a TypeError — a 500 with a    ║
     * ║ stack trace — where backend/SectionService::assertOwnsCourse()        ║
     * ║ answered a clean 404 'Course not found'.                              ║
     * ║                                                                      ║
     * ║ Asserted on BOTH mutating verbs, because each resolves the parent     ║
     * ║ through its own Form Request.                                         ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_section_under_a_soft_deleted_course_is_404_not_500(): void
    {
        $section = $this->makeSection($this->course);
        $this->course->delete();

        $this->loginAs($this->owner);

        $this->putJson("/api/v1/sections/{$section->getKey()}", ['title' => 'يتيم'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Course not found');

        $this->deleteJson("/api/v1/sections/{$section->getKey()}")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Course not found');

        $this->assertDatabaseHas('sections', ['id' => $section->getKey()]);
    }

    /** …and an admin gets the same 404: there is no course left to manage. */
    public function test_even_an_admin_gets_404_for_an_orphaned_section(): void
    {
        $section = $this->makeSection($this->course);
        $this->course->delete();

        $this->loginAsAdmin();

        $this->putJson("/api/v1/sections/{$section->getKey()}", ['title' => 'يتيم'])
            ->assertStatus(404);
    }

    /**
     * The POLICY must fail closed on its own, independently of the controller
     * layer that reaches the same conclusion first. A future call site that
     * forgets CourseAccess::parentCourseOf() must still be denied rather than
     * crashing on a null.
     */
    public function test_the_section_policy_denies_an_orphaned_section_without_crashing(): void
    {
        $section = $this->makeSection($this->course);
        $this->course->delete();

        $section = Section::query()->whereKey($section->getKey())->firstOrFail();

        $this->assertFalse(
            \Illuminate\Support\Facades\Gate::forUser($this->owner)->allows('manage', $section)
        );
    }

    // ── the public read ───────────────────────────────────────────────────

    public function test_sections_are_listed_in_order_index_order(): void
    {
        $this->makeSection($this->course, ['title' => 'ثالثة', 'order_index' => 2]);
        $this->makeSection($this->course, ['title' => 'أولى', 'order_index' => 0]);
        $this->makeSection($this->course, ['title' => 'ثانية', 'order_index' => 1]);

        $titles = collect(
            $this->getJson("/api/v1/courses/{$this->course->getKey()}/sections")->json('data')
        )->pluck('title')->all();

        $this->assertSame(['أولى', 'ثانية', 'ثالثة'], $titles);
    }

    public function test_the_listing_is_scoped_to_the_named_course(): void
    {
        $this->makeSection($this->course, ['title' => 'لي']);
        $otherCourse = $this->makeCourseFor($this->intruder, ['is_published' => true]);
        $this->makeSection($otherCourse, ['title' => 'لغيري']);

        $titles = collect(
            $this->getJson("/api/v1/courses/{$this->course->getKey()}/sections")->json('data')
        )->pluck('title')->all();

        $this->assertSame(['لي'], $titles);
    }

    public function test_an_unknown_course_id_is_404_on_the_section_listing(): void
    {
        $this->getJson('/api/v1/courses/00000000-0000-0000-0000-000000000000/sections')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Course not found');
    }

    public function test_a_student_cannot_reach_the_section_write_routes(): void
    {
        $this->loginAs($this->makeStudentUser());

        $this->postJson("/api/v1/courses/{$this->course->getKey()}/sections", ['title' => 'ممنوع'])
            ->assertStatus(403);
    }
}
