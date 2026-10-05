<?php

declare(strict_types=1);

namespace Tests\Feature\Course;

use App\Enums\ContentItemType;
use App\Models\Course;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/courses/{id}/structure — freeze-table row 10, Stage 5 / Session C.
 *
 * The course TABLE OF CONTENTS: the hottest public read in the application,
 * rendered on every course page load including for logged-out visitors.
 *
 * Four properties are pinned here and each one is a way the endpoint could go
 * wrong without anybody noticing:
 *
 *   1. NO `detail` key, for ANY actor — not even the owner, not even an admin.
 *   2. Groups are their own `groups` array, gated by CourseGroup::
 *      isCurrentlyVisible() (open decision 10.21 — REVERSED from the prior
 *      "group-owned items never appear" rule; see that method's docblock).
 *   3. The whole tree costs a CONSTANT number of queries.
 *   4. Sections carry a SYNTHETIC `isHidden: false` (no such column exists) —
 *      a group's `isHidden`, by contrast, is a REAL column.
 *
 * Plus decision 3.6's cascade and the hidden-item filter, which this endpoint
 * shares with the section listing.
 */
final class CourseStructureTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private Course $course;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->owner] = $this->makeTeacherWithProfile();
        $this->admin = $this->makeAdmin();
        $this->course = $this->makeCourseFor($this->owner, ['is_published' => true]);
        $this->section = $this->makeSection($this->course, ['title' => 'الوحدة الأولى']);
    }

    // ══ 1. THE SHAPE ══════════════════════════════════════════════════════

    public function test_a_guest_receives_the_sections_lessons_envelope(): void
    {
        $lesson = $this->makeContentItem($this->section, [
            'type' => ContentItemType::Video,
            'title' => 'الدرس الأول',
            'description' => 'وصف',
            'start_at' => '2026-05-01 09:00:00',
            'end_at' => '2026-05-02 09:00:00',
        ]);

        $data = $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
            ->assertOk()
            ->json('data');

        // 'groups' since open decision 10.21 — course_groups content is real
        // now, so the outline carries a second, independent array alongside
        // the section tree.
        $this->assertSame(['sections', 'groups'], array_keys($data));
        $this->assertCount(1, $data['sections']);

        $section = $data['sections'][0];

        $this->assertSame(
            ['id', 'title', 'order_index', 'isHidden', 'lessons'],
            array_keys($section)
        );
        $this->assertSame($this->section->getKey(), $section['id']);
        $this->assertSame('الوحدة الأولى', $section['title']);

        $this->assertSame([
            'id', 'title', 'type', 'order_index', 'is_hidden', 'isHidden',
            'description', 'start_at', 'end_at',
        ], array_keys($section['lessons'][0]));

        $this->assertSame($lesson->getKey(), $section['lessons'][0]['id']);
        $this->assertSame('video', $section['lessons'][0]['type']);
        $this->assertSame('2026-05-01 09:00:00', $section['lessons'][0]['start_at']);
    }

    /**
     * ┌── SECTION `isHidden` IS FABRICATED, AND STAYS FABRICATED. ───────────┐
     * │ `sections` has no is_hidden column (migration 000013) and this       │
     * │ session did not add one. backend/ emits the constant `false` because │
     * │ CourseDetailsPage.tsx and CourseStructureManagement.tsx both read    │
     * │ `section.isHidden`. Dropping it renders `undefined`; adding a real   │
     * │ column would be a schema change with a hiding RULE behind it that    │
     * │ nothing asks for.                                                    │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_every_section_carries_the_synthetic_is_hidden_false(): void
    {
        $this->makeSection($this->course);
        $this->makeSection($this->course);

        $sections = $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
            ->assertOk()
            ->json('data.sections');

        $this->assertCount(3, $sections);

        foreach ($sections as $section) {
            $this->assertArrayHasKey('isHidden', $section);
            $this->assertFalse($section['isHidden']);
        }

        // And it really is synthetic — the column does not exist.
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('sections', 'is_hidden'),
            'A real is_hidden column appeared on `sections`; this test and the Resource both '
            . 'assume the value is fabricated for frontend shape-compatibility.'
        );
    }

    // ══ 2. NO `detail`, FOR ANYBODY ═══════════════════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE OWNER AND THE ADMIN GET NO `detail` EITHER. THAT IS THE POINT OF ║
     * ║ CourseStructureResource BEING A THIRD CLASS.                         ║
     * ║                                                                      ║
     * ║ /structure is a table of contents. Making it entitlement-aware would ║
     * ║ add a SECOND, differently-shaped place where the public/private      ║
     * ║ split has to be got right — on the hottest public read there is —    ║
     * ║ to serve data every consumer already fetches from the per-item       ║
     * ║ routes. One shape for everyone means there is no condition here to   ║
     * ║ evaluate wrongly.                                                    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_no_actor_ever_receives_a_detail_key_or_a_paid_value(): void
    {
        $this->makeContentItemWithDetail(
            $this->section,
            ContentItemType::Video,
            ['video_url' => 'https://leak.invalid/STRUCTURE-LEAK', 'duration' => 42],
        );
        $this->makeContentItemWithDetail(
            $this->section,
            ContentItemType::Note,
            ['content_text' => 'STRUCTURE-NOTE-LEAK'],
        );

        foreach ([null, $this->makeStudentUser(), $this->owner, $this->admin] as $actor) {
            $this->flushSession();
            $this->startNextRequest();

            if ($actor !== null) {
                $this->loginAs($actor);
                $this->startNextRequest();
            }

            $body = $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
                ->assertOk()
                ->getContent() ?: '';

            foreach (['"detail"', '"video_url"', '"content_text"', '"duration"',
                'https://leak.invalid/STRUCTURE-LEAK', 'STRUCTURE-NOTE-LEAK'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $body,
                    '/structure emitted ' . $forbidden . ' — it is a table of contents and '
                    . 'carries no detail payload for ANY actor.'
                );
            }
        }
    }

    // ══ 3. GROUPS — open decision 10.21 ═══════════════════════════════════

    /**
     * REVERSES the prior "group-owned items never appear" rule. Groups are
     * their own `groups` array (never merged into `sections` — see the
     * controller's docblock for why), and a VISIBLE group's items now appear
     * there for a guest, same as a section's do.
     */
    public function test_a_visible_groups_items_appear_in_the_groups_array(): void
    {
        $sectionItem = $this->makeContentItem($this->section, ['title' => 'تحت قسم']);

        $group = $this->makeCourseGroup($this->course, ['title' => 'مجموعة المراجعة']);
        $groupItem = $this->makeGroupContentItem($group, ['title' => 'تحت مجموعة']);

        $data = $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
            ->assertOk()
            ->json('data');

        $this->assertStringContainsString($sectionItem->getKey(), json_encode($data['sections']));

        $this->assertCount(1, $data['groups']);
        $this->assertSame($group->getKey(), $data['groups'][0]['id']);
        $this->assertSame('مجموعة المراجعة', $data['groups'][0]['title']);
        $this->assertSame(
            ['id', 'title', 'order_index', 'isHidden', 'lessons'],
            array_keys($data['groups'][0])
        );
        $this->assertSame($groupItem->getKey(), $data['groups'][0]['lessons'][0]['id']);
    }

    /** A HIDDEN group is filtered for a guest — the manual switch still works. */
    public function test_a_hidden_group_is_filtered_for_a_guest(): void
    {
        $this->makeCourseGroup($this->course, ['title' => 'مخفية', 'is_hidden' => true]);

        $groups = $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
            ->assertOk()
            ->json('data.groups');

        $this->assertSame([], $groups);
    }

    /** A group scheduled to start in the FUTURE is filtered for a guest. */
    public function test_a_not_yet_started_group_is_filtered_for_a_guest(): void
    {
        $this->makeCourseGroup($this->course, [
            'title' => 'لم يبدأ بعد',
            'start_at' => now()->addDay(),
        ]);

        $groups = $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
            ->assertOk()
            ->json('data.groups');

        $this->assertSame([], $groups);
    }

    /** A group whose end time already passed is filtered for a guest. */
    public function test_an_ended_group_is_filtered_for_a_guest(): void
    {
        $this->makeCourseGroup($this->course, [
            'title' => 'انتهت',
            'start_at' => now()->subWeek(),
            'end_at' => now()->subDay(),
        ]);

        $groups = $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
            ->assertOk()
            ->json('data.groups');

        $this->assertSame([], $groups);
    }

    /** NULL start_at/end_at means "no gate" — immediately visible, forever. */
    public function test_a_group_with_no_schedule_is_visible_immediately(): void
    {
        $this->makeCourseGroup($this->course, ['title' => 'بلا جدولة']);

        $groups = $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
            ->assertOk()
            ->json('data.groups');

        $this->assertCount(1, $groups);
    }

    /** A group WITHIN its start/end window is visible to a guest. */
    public function test_a_group_inside_its_window_is_visible_to_a_guest(): void
    {
        $this->makeCourseGroup($this->course, [
            'title' => 'جارية الآن',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        $groups = $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
            ->assertOk()
            ->json('data.groups');

        $this->assertCount(1, $groups);
    }

    /**
     * The owner/admin bypass EVERY group gate — hidden, not-yet-started, and
     * ended groups are all previewable, same privilege maySeeHidden() already
     * grants for individual hidden lessons.
     */
    public function test_the_owner_and_admin_see_every_group_regardless_of_schedule(): void
    {
        $this->makeCourseGroup($this->course, ['title' => 'مخفية', 'is_hidden' => true]);
        $this->makeCourseGroup($this->course, ['title' => 'مستقبلية', 'start_at' => now()->addDay()]);
        $this->makeCourseGroup($this->course, [
            'title' => 'منتهية',
            'start_at' => now()->subWeek(),
            'end_at' => now()->subDay(),
        ]);

        foreach ([$this->owner, $this->admin] as $actor) {
            $this->flushSession();
            $this->startNextRequest();
            $this->loginAs($actor);
            $this->startNextRequest();

            $groups = $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
                ->assertOk()
                ->json('data.groups');

            $this->assertCount(3, $groups);
        }
    }

    /** A SUBSCRIBED STUDENT is not privileged — same rule as hidden lessons. */
    public function test_a_subscribed_student_still_has_hidden_groups_filtered(): void
    {
        $this->makeCourseGroup($this->course, ['title' => 'مخفية', 'is_hidden' => true]);

        $subscriber = $this->makeStudentUser();
        $this->makeSubscription($subscriber, $this->course);
        $this->loginAs($subscriber);

        $groups = $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
            ->assertOk()
            ->json('data.groups');

        $this->assertSame([], $groups);
    }

    /** A hidden ITEM inside a visible group is filtered the same as a section's. */
    public function test_a_hidden_item_inside_a_visible_group_is_filtered_for_a_guest(): void
    {
        $group = $this->makeCourseGroup($this->course);
        $visible = $this->makeGroupContentItem($group, ['is_hidden' => false]);
        $hidden = $this->makeGroupContentItem($group, ['is_hidden' => true]);

        $groups = $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
            ->assertOk()
            ->json('data.groups');

        $ids = array_column($groups[0]['lessons'], 'id');
        $this->assertContains($visible->getKey(), $ids);
        $this->assertNotContains($hidden->getKey(), $ids);
    }

    // ══ 4. ONE QUERY FOR THE TREE ═════════════════════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE QUERY COUNT MUST NOT SCALE WITH THE NUMBER OF SECTIONS.          ║
     * ║                                                                      ║
     * ║ backend/ went out of its way to avoid the per-section loop and said  ║
     * ║ why: "this endpoint is public and hit on every course page view".    ║
     * ║ The Eloquent regression that looks identical at a glance is          ║
     * ║   foreach ($course->sections as $s) { $s->contentItems }             ║
     * ║ which is 2 + N. The nested eager load is a constant 3.               ║
     * ║                                                                      ║
     * ║ Measured against SIX sections, so a per-section query would show up  ║
     * ║ as a six-fold difference rather than a one-off.                      ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_whole_tree_costs_a_constant_number_of_queries(): void
    {
        $oneSection = $this->countQueriesFor($this->course);

        for ($i = 0; $i < 5; $i++) {
            $extra = $this->makeSection($this->course);
            $this->makeContentItem($extra);
            $this->makeContentItem($extra);
        }

        $sixSections = $this->countQueriesFor($this->course);

        $this->assertSame(
            $oneSection,
            $sixSections,
            "The structure query count moved from {$oneSection} to {$sixSections} when five "
            . 'sections were added. It must be constant — see the class docblock.'
        );

        // …and the constant is small. A guard against "constant but 40".
        $this->assertLessThanOrEqual(6, $sixSections);
    }

    // ══ 5. HIDDEN + DECISION 3.6 ══════════════════════════════════════════

    /** Hidden lessons are FILTERED for a non-owner — including a subscriber. */
    public function test_a_hidden_lesson_is_filtered_for_everyone_but_the_owner_and_an_admin(): void
    {
        $visible = $this->makeContentItem($this->section, ['is_hidden' => false]);
        $hidden = $this->makeContentItem($this->section, ['is_hidden' => true]);

        $subscriber = $this->makeStudentUser();
        $this->makeSubscription($subscriber, $this->course);

        foreach ([null, $this->makeStudentUser(), $subscriber] as $actor) {
            $this->flushSession();
            $this->startNextRequest();

            if ($actor !== null) {
                $this->loginAs($actor);
                $this->startNextRequest();
            }

            $body = $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
                ->assertOk()
                ->getContent() ?: '';

            $this->assertStringContainsString($visible->getKey(), $body);
            $this->assertStringNotContainsString(
                $hidden->getKey(),
                $body,
                'A hidden lesson reached a non-owner. A SUBSCRIBED student passes '
                . 'consumeContent but MUST fail viewHidden.'
            );
        }

        foreach ([$this->owner, $this->admin] as $actor) {
            $this->flushSession();
            $this->startNextRequest();
            $this->loginAs($actor);
            $this->startNextRequest();

            $body = $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
                ->assertOk()
                ->getContent() ?: '';

            $this->assertStringContainsString($hidden->getKey(), $body);
        }
    }

    /** Decision 3.6's cascade — a draft course's outline is 404. */
    public function test_a_draft_courses_structure_is_404_for_a_non_owner_and_200_for_the_owner(): void
    {
        $draft = $this->makeCourseFor($this->owner, ['is_published' => false]);
        $this->makeSection($draft);

        $this->getJson("/api/v1/courses/{$draft->getKey()}/structure")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Course not found');

        $this->loginAs($this->owner);
        $this->startNextRequest();
        $this->getJson("/api/v1/courses/{$draft->getKey()}/structure")->assertOk();
    }

    /** An unknown id and a draft id are INDISTINGUISHABLE — no oracle. */
    public function test_a_draft_is_indistinguishable_from_an_unknown_id(): void
    {
        [$other] = $this->makeTeacherWithProfile();
        $draft = $this->makeCourseFor($other, ['is_published' => false]);

        $draftResponse = $this->getJson("/api/v1/courses/{$draft->getKey()}/structure");

        $this->startNextRequest();
        $unknownResponse = $this->getJson(
            '/api/v1/courses/' . \Illuminate\Support\Str::uuid7() . '/structure'
        );

        $this->assertSame($unknownResponse->status(), $draftResponse->status());
        $this->assertSame($unknownResponse->json('message'), $draftResponse->json('message'));
    }

    /** A course with no sections answers an empty list, not an error. */
    public function test_a_course_without_sections_answers_an_empty_list(): void
    {
        $bare = $this->makeCourseFor($this->owner, ['is_published' => true]);

        $this->getJson("/api/v1/courses/{$bare->getKey()}/structure")
            ->assertOk()
            ->assertJsonPath('data.sections', []);
    }

    /** Sections come back in `order_index ASC, id ASC` — Course::sections(). */
    public function test_sections_are_ordered_by_order_index(): void
    {
        $last = $this->makeSection($this->course, ['order_index' => 50]);
        $first = $this->makeSection($this->course, ['order_index' => -1]);

        $ids = array_column(
            $this->getJson("/api/v1/courses/{$this->course->getKey()}/structure")
                ->assertOk()
                ->json('data.sections'),
            'id'
        );

        $this->assertSame($first->getKey(), $ids[0]);
        $this->assertSame($last->getKey(), end($ids));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function countQueriesFor(Course $course): int
    {
        $this->flushSession();
        $this->startNextRequest();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson("/api/v1/courses/{$course->getKey()}/structure")->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
