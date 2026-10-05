<?php

declare(strict_types=1);

namespace Tests\Feature\Course;

use App\Enums\ContentItemType;
use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * Course content groups — freeze-table rows 74-77.
 *
 * All four routes are `auth`,`role:admin,instructor`; there is no public group
 * surface, so unlike the course and section suites there is no DEGRADE column
 * to test — only the list scope, the write gate and the FK behaviour.
 */
final class CourseGroupCrudTest extends TestCase
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

        $this->course = $this->makeCourseFor($this->owner);
    }

    // ── create ────────────────────────────────────────────────────────────

    public function test_the_owner_creates_a_group(): void
    {
        $this->loginAs($this->owner);

        $this->postJson('/api/v1/groups', [
            'course_id' => $this->course->getKey(),
            'title' => 'مجموعة المراجعة',
        ])
            ->assertStatus(201)
            ->assertJsonPath('message', 'تم إنشاء المجموعة بنجاح')
            // NESTED under `group` — backend/GroupController wraps it.
            ->assertJsonPath('data.group.title', 'مجموعة المراجعة')
            ->assertJsonPath('data.group.course_id', $this->course->getKey())
            ->assertJsonPath('data.group.is_hidden', false)
            ->assertJsonPath('data.group.order_index', 0);
    }

    /** groupApiService.ts posts `courseId`; both spellings must work. */
    public function test_the_camel_case_course_id_alias_is_accepted(): void
    {
        $this->loginAs($this->owner);

        $this->postJson('/api/v1/groups', [
            'courseId' => $this->course->getKey(),
            'title' => 'بالمفتاح المائل',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.group.course_id', $this->course->getKey());
    }

    public function test_a_group_may_be_created_hidden(): void
    {
        $this->loginAs($this->owner);

        $this->postJson('/api/v1/groups', [
            'course_id' => $this->course->getKey(),
            'title' => 'مخفية',
            'is_hidden' => true,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.group.is_hidden', true);
    }

    public function test_order_index_increments_per_course(): void
    {
        $this->loginAs($this->owner);

        foreach ([0, 1, 2] as $expected) {
            $this->postJson('/api/v1/groups', [
                'course_id' => $this->course->getKey(),
                'title' => 'مجموعة ' . $expected,
            ])->assertJsonPath('data.group.order_index', $expected);
        }
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ ONE COMBINED ERROR UNDER ONE KEY — NOT TWO `required` MESSAGES.      ║
     * ║ backend/GroupService::create() throws                                 ║
     * ║   ValidationException(['course_id' => ['course_id والعنوان مطلوبان']])║
     * ║ whichever of the two is missing.                                      ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_missing_title_produces_the_single_combined_message(): void
    {
        $this->loginAs($this->owner);

        $response = $this->postJson('/api/v1/groups', ['course_id' => $this->course->getKey()])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.course_id.0', 'course_id والعنوان مطلوبان');

        $this->assertSame(['course_id'], array_keys($response->json('data.errors')));
    }

    public function test_a_missing_course_id_produces_the_same_message(): void
    {
        $this->loginAs($this->owner);

        $this->postJson('/api/v1/groups', ['title' => 'بلا كورس'])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.course_id.0', 'course_id والعنوان مطلوبان');
    }

    /**
     * VALIDATION RUNS FIRST — backend/ validates, THEN resolves the course,
     * THEN checks ownership. A malformed body from a foreign instructor is a
     * 422, not a 403. (The section routes are the other way round; the order is
     * read off each legacy service, not chosen.)
     */
    public function test_a_malformed_body_from_a_foreign_instructor_is_422_not_403(): void
    {
        $this->loginAs($this->intruder);

        $this->postJson('/api/v1/groups', ['course_id' => $this->course->getKey()])
            ->assertStatus(422);
    }

    public function test_an_unknown_course_id_is_404(): void
    {
        $this->loginAs($this->owner);

        $this->postJson('/api/v1/groups', [
            'course_id' => '00000000-0000-0000-0000-000000000000',
            'title' => 'كورس غير موجود',
        ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Course not found');
    }

    // ── cross-tenant isolation ────────────────────────────────────────────

    public function test_another_teacher_cannot_create_a_group_on_this_course(): void
    {
        $this->loginAs($this->intruder);

        $this->postJson('/api/v1/groups', [
            'course_id' => $this->course->getKey(),
            'title' => 'تسلل',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not own this course');

        $this->assertSame(0, CourseGroup::query()->where('course_id', $this->course->getKey())->count());
    }

    public function test_another_teacher_cannot_update_a_group(): void
    {
        $group = $this->makeCourseGroup($this->course, ['title' => 'الأصلية']);
        $this->loginAs($this->intruder);

        $this->patchJson("/api/v1/groups/{$group->getKey()}", ['title' => 'مسروقة'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not own this course');

        $this->assertSame('الأصلية', $group->refresh()->getAttribute('title'));
    }

    public function test_another_teacher_cannot_delete_a_group(): void
    {
        $group = $this->makeCourseGroup($this->course);
        $this->loginAs($this->intruder);

        $this->deleteJson("/api/v1/groups/{$group->getKey()}")->assertStatus(403);

        $this->assertDatabaseHas('course_groups', ['id' => $group->getKey()]);
    }

    // ── the list scope ────────────────────────────────────────────────────

    public function test_an_instructor_sees_only_their_own_groups(): void
    {
        $mine = $this->makeCourseGroup($this->course, ['title' => 'لي']);
        $theirCourse = $this->makeCourseFor($this->intruder);
        $theirs = $this->makeCourseGroup($theirCourse, ['title' => 'لغيري']);

        $this->loginAs($this->owner);

        $ids = collect($this->getJson('/api/v1/groups')->assertStatus(200)->json('data.groups'))
            ->pluck('id')->all();

        $this->assertSame([$mine->getKey()], $ids);
        $this->assertNotContains($theirs->getKey(), $ids);
    }

    public function test_an_admin_sees_every_teachers_groups(): void
    {
        $mine = $this->makeCourseGroup($this->course);
        $theirCourse = $this->makeCourseFor($this->intruder);
        $theirs = $this->makeCourseGroup($theirCourse);

        $this->loginAsAdmin();

        $ids = collect($this->getJson('/api/v1/groups')->json('data.groups'))->pluck('id')->all();

        $this->assertContains($mine->getKey(), $ids);
        $this->assertContains($theirs->getKey(), $ids);
    }

    /**
     * scopeOwnedBy() runs its predicate against the Course MODEL, so
     * SoftDeletes supplies `deleted_at IS NULL` inside the subquery — the
     * clause backend/GroupRepository had to hand-write into its JOIN.
     */
    public function test_groups_of_a_soft_deleted_course_drop_out_of_the_instructor_listing(): void
    {
        $group = $this->makeCourseGroup($this->course);
        $this->course->delete();

        $this->loginAs($this->owner);

        $ids = collect($this->getJson('/api/v1/groups')->json('data.groups'))->pluck('id')->all();

        $this->assertNotContains($group->getKey(), $ids);
    }

    public function test_a_student_cannot_reach_the_group_routes(): void
    {
        $this->loginAs($this->makeStudentUser());

        $this->getJson('/api/v1/groups')->assertStatus(403);
        $this->postJson('/api/v1/groups', [
            'course_id' => $this->course->getKey(),
            'title' => 'ممنوع',
        ])->assertStatus(403);
    }

    public function test_a_guest_gets_401(): void
    {
        $this->getJson('/api/v1/groups')->assertStatus(401);
    }

    // ── visibility schedule (open decision 10.21) ───────────────────────────

    public function test_a_group_may_be_created_with_a_schedule(): void
    {
        $this->loginAs($this->owner);

        $this->postJson('/api/v1/groups', [
            'course_id' => $this->course->getKey(),
            'title' => 'مجدولة',
            'start_at' => '2026-06-01 09:00:00',
            'end_at' => '2026-06-30 23:59:59',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.group.start_at', '2026-06-01 09:00:00')
            ->assertJsonPath('data.group.end_at', '2026-06-30 23:59:59');
    }

    /** NULL is the default — "no gate", not "not chosen yet". */
    public function test_a_group_created_without_a_schedule_has_null_dates(): void
    {
        $this->loginAs($this->owner);

        $this->postJson('/api/v1/groups', [
            'course_id' => $this->course->getKey(),
            'title' => 'بلا جدولة',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.group.start_at', null)
            ->assertJsonPath('data.group.end_at', null);
    }

    public function test_an_end_date_before_the_start_date_is_422(): void
    {
        $this->loginAs($this->owner);

        $this->postJson('/api/v1/groups', [
            'course_id' => $this->course->getKey(),
            'title' => 'تواريخ مقلوبة',
            'start_at' => '2026-06-30 00:00:00',
            'end_at' => '2026-06-01 00:00:00',
        ])->assertStatus(422);
    }

    public function test_the_owner_schedules_an_existing_group(): void
    {
        $group = $this->makeCourseGroup($this->course);
        $this->loginAs($this->owner);

        $this->patchJson("/api/v1/groups/{$group->getKey()}", [
            'start_at' => '2026-07-01 08:00:00',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.group.start_at', '2026-07-01 08:00:00');
    }

    /**
     * PRESENCE-based, same rule as `is_hidden`: an explicit `null` really
     * clears the gate rather than being read as "field omitted, leave alone".
     */
    public function test_sending_an_explicit_null_clears_a_previously_set_end_date(): void
    {
        $group = $this->makeCourseGroup($this->course, ['end_at' => now()->addWeek()]);
        $this->loginAs($this->owner);

        $this->patchJson("/api/v1/groups/{$group->getKey()}", ['end_at' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.group.end_at', null);

        $this->assertNull($group->refresh()->getAttribute('end_at'));
    }

    /** Renaming alone must not touch a previously-set schedule — same rule as is_hidden. */
    public function test_renaming_alone_does_not_clear_the_schedule(): void
    {
        $group = $this->makeCourseGroup($this->course, ['start_at' => '2026-06-01 00:00:00']);
        $this->loginAs($this->owner);

        $this->patchJson("/api/v1/groups/{$group->getKey()}", ['title' => 'اسم جديد'])
            ->assertStatus(200)
            ->assertJsonPath('data.group.start_at', '2026-06-01 00:00:00');
    }

    // ── update ────────────────────────────────────────────────────────────

    public function test_the_owner_renames_a_group(): void
    {
        $group = $this->makeCourseGroup($this->course, ['title' => 'قديمة']);
        $this->loginAs($this->owner);

        $this->patchJson("/api/v1/groups/{$group->getKey()}", ['title' => 'جديدة'])
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم تحديث المجموعة بنجاح')
            ->assertJsonPath('data.group.title', 'جديدة');
    }

    /**
     * `is_hidden` is PRESENCE-based: `false` is a real un-hide instruction,
     * and an absent key must not silently un-hide on an unrelated rename.
     */
    public function test_is_hidden_false_actually_unhides(): void
    {
        $group = $this->makeCourseGroup($this->course, ['is_hidden' => true]);
        $this->loginAs($this->owner);

        $this->patchJson("/api/v1/groups/{$group->getKey()}", ['is_hidden' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.group.is_hidden', false);

        $this->assertSame(0, (int) $group->refresh()->getAttributes()['is_hidden']);
    }

    public function test_renaming_alone_does_not_unhide(): void
    {
        $group = $this->makeCourseGroup($this->course, ['is_hidden' => true]);
        $this->loginAs($this->owner);

        $this->patchJson("/api/v1/groups/{$group->getKey()}", ['title' => 'اسم جديد'])
            ->assertStatus(200)
            ->assertJsonPath('data.group.is_hidden', true);
    }

    public function test_an_unknown_group_id_is_404_with_the_contract_message(): void
    {
        $this->loginAs($this->owner);

        $this->patchJson('/api/v1/groups/00000000-0000-0000-0000-000000000000', ['title' => 'لا شيء'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Group not found');
    }

    // ── delete ────────────────────────────────────────────────────────────

    public function test_the_owner_deletes_a_group_hard(): void
    {
        $group = $this->makeCourseGroup($this->course);
        $this->loginAs($this->owner);

        $this->deleteJson("/api/v1/groups/{$group->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم حذف المجموعة بنجاح');

        $this->assertDatabaseMissing('course_groups', ['id' => $group->getKey()]);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE FK CONFIRMATION Stage 0 SET UP: content_items.group_id CASCADES. ║
     * ║                                                                      ║
     * ║ Session B owns App\Models\ContentItem, so the row is inserted through ║
     * ║ the query builder here — this test is about the SCHEMA, not about any ║
     * ║ application code, and it deliberately does not depend on a model that ║
     * ║ does not exist yet.                                                   ║
     * ║                                                                      ║
     * ║ Why it matters: the predecessor FK in backend/ was ON DELETE SET      ║
     * ║ NULL, which — combined with                                           ║
     * ║   CHECK ((section_id IS NULL) <> (group_id IS NULL))                  ║
     * ║ — made group deletion IMPOSSIBLE (MySQL nulls group_id on a row whose ║
     * ║ section_id is already NULL, violates the CHECK, aborts the DELETE).   ║
     * ║ migration 0015 fixed it and Stage 0 ported the fixed version. If that ║
     * ║ ever regresses, the delete above starts failing with a constraint     ║
     * ║ violation instead of a 200, and this test says why.                   ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_group_foreign_key_cascades_as_stage_0_configured_it(): void
    {
        $group = $this->makeCourseGroup($this->course);

        $itemId = (string) Str::uuid7();
        DB::table('content_items')->insert([
            'id' => $itemId,
            'section_id' => null,
            'group_id' => $group->getKey(),
            'course_id' => $this->course->getKey(),
            'title' => 'عنصر داخل مجموعة',
            'type' => ContentItemType::Note->value,
            'order_index' => 0,
            'is_hidden' => 0,
            'created_at' => now(),
        ]);

        $this->loginAs($this->owner);

        $this->deleteJson("/api/v1/groups/{$group->getKey()}")->assertStatus(200);

        $this->assertDatabaseMissing('course_groups', ['id' => $group->getKey()]);
        $this->assertDatabaseMissing('content_items', ['id' => $itemId]);
    }

    // ── the soft-deleted-course edge case ─────────────────────────────────

    public function test_a_group_under_a_soft_deleted_course_is_404_not_500(): void
    {
        $group = $this->makeCourseGroup($this->course);
        $this->course->delete();

        $this->loginAs($this->owner);

        $this->patchJson("/api/v1/groups/{$group->getKey()}", ['title' => 'يتيمة'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Course not found');

        $this->deleteJson("/api/v1/groups/{$group->getKey()}")->assertStatus(404);

        $this->assertDatabaseHas('course_groups', ['id' => $group->getKey()]);
    }

    /** The policy fails closed on its own, independently of the controller. */
    public function test_the_group_policy_denies_an_orphaned_group_without_crashing(): void
    {
        $group = $this->makeCourseGroup($this->course);
        $this->course->delete();

        $group = CourseGroup::query()->whereKey($group->getKey())->firstOrFail();

        $this->assertFalse(Gate::forUser($this->owner)->allows('manage', $group));
    }
}
