<?php

declare(strict_types=1);

namespace Tests\Feature\ContentItem;

use App\Enums\ContentItemType;
use App\Models\ContentItem;
use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\Exam;
use App\Models\Link;
use App\Models\Note;
use App\Models\Section;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * Content items — freeze-table rows 23-26 and 78-79, the write surface.
 *
 * Field semantics live in ContentItemFieldSemanticsTest, detail-row rules in
 * ContentItemDetailSemanticsTest, URL safety in ContentItemUrlSafetyTest,
 * tenant isolation in ContentItemIsolationTest and the auth gate in
 * ContentItemAuthGateTest. What is here is creation of all six types, the XOR
 * invariant, the reorder tolerance and the cascade.
 */
final class ContentItemCrudTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $owner;

    private Course $course;

    private Section $section;

    private CourseGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->owner] = $this->makeTeacherWithProfile();
        $this->course = $this->makeCourseFor($this->owner, ['is_published' => true]);
        $this->section = $this->makeSection($this->course);
        $this->group = $this->makeCourseGroup($this->course);
    }

    private function sectionUrl(): string
    {
        return "/api/v1/sections/{$this->section->getKey()}/content-items";
    }

    private function groupUrl(): string
    {
        return "/api/v1/groups/{$this->group->getKey()}/content-items";
    }

    // ── ALL SIX TYPES ─────────────────────────────────────────────────────

    public function test_a_video_creates_a_videos_row(): void
    {
        $this->loginAs($this->owner);

        $response = $this->postJson($this->sectionUrl(), [
            'title' => 'الدرس الأول',
            'type' => 'video',
            'video_url' => 'https://cdn.example/lesson1.m3u8',
            'duration' => 600,
            'is_preview' => true,
            'provider' => 'Bunny',
            'thumbnail_url' => 'https://cdn.example/poster.png',
        ])
            ->assertStatus(201)
            ->assertJsonPath('message', 'تم إنشاء العنصر بنجاح')
            ->assertJsonPath('data.type', 'video')
            ->assertJsonPath('data.detail.video_url', 'https://cdn.example/lesson1.m3u8')
            ->assertJsonPath('data.detail.duration', 600)
            ->assertJsonPath('data.detail.is_preview', true)
            ->assertJsonPath('data.detail.provider', 'Bunny');

        $id = $response->json('data.id');

        $this->assertDatabaseHas('videos', [
            'content_item_id' => $id,
            'video_url' => 'https://cdn.example/lesson1.m3u8',
        ]);
    }

    public function test_a_link_creates_a_links_row(): void
    {
        $this->loginAs($this->owner);

        $response = $this->postJson($this->sectionUrl(), [
            'title' => 'مرجع خارجي',
            'type' => 'link',
            'url' => 'https://example.com/reference',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.detail.url', 'https://example.com/reference');

        $this->assertDatabaseHas('links', ['content_item_id' => $response->json('data.id')]);
    }

    public function test_a_note_with_text_creates_a_notes_row(): void
    {
        $this->loginAs($this->owner);

        $response = $this->postJson($this->sectionUrl(), [
            'title' => 'ملخّص الوحدة',
            'type' => 'note',
            'content_text' => 'النصّ الكامل للمذكّرة',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.detail.content_text', 'النصّ الكامل للمذكّرة');

        $this->assertDatabaseHas('notes', ['content_item_id' => $response->json('data.id')]);
    }

    /**
     * The exam CREATE path applies the repository's own defaults, which are
     * NOT all the column defaults — duration_minutes 30, randomize 0, lock 0,
     * show_answers 1. See PersistExamDetail.
     */
    public function test_an_exam_creates_an_exams_row_with_the_legacy_defaults(): void
    {
        $this->loginAs($this->owner);

        $this->postJson($this->sectionUrl(), [
            'title' => 'امتحان الوحدة',
            'type' => 'exam',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.detail.duration_minutes', 30)
            ->assertJsonPath('data.detail.randomize_questions', false)
            ->assertJsonPath('data.detail.lock_content', false)
            ->assertJsonPath('data.detail.show_answers', true)
            ->assertJsonPath('data.detail.allowed_attempts', null);

        $this->assertSame(1, Exam::query()->count());
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ APPROVED DECISION #1: `type = 'file'` IS A LEGAL VALUE WITH NO       ║
     * ║ IMPLEMENTATION. A ROW IN content_items AND NOTHING ANYWHERE ELSE.    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_file_item_creates_the_parent_row_and_no_detail_row_at_all(): void
    {
        $this->loginAs($this->owner);

        $response = $this->postJson($this->sectionUrl(), [
            'title' => 'ملف',
            'type' => 'file',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'file')
            ->assertJsonPath('data.detail', null);

        $this->assertDatabaseHas('content_items', ['id' => $response->json('data.id')]);

        foreach (['videos', 'links', 'notes', 'exams'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "`{$table}` must stay empty for a file item.");
        }
    }

    public function test_an_unknown_type_is_422_with_the_legacy_arabic_message(): void
    {
        $this->loginAs($this->owner);

        $this->postJson($this->sectionUrl(), ['title' => 'شيء', 'type' => 'podcast'])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.type.0', 'نوع المحتوى غير صحيح');
    }

    /** create() reads `$data['type'] ?? null`, so an absent type is the same 422. */
    public function test_a_missing_type_on_the_section_route_is_the_same_422(): void
    {
        $this->loginAs($this->owner);

        $this->postJson($this->sectionUrl(), ['title' => 'شيء'])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.type.0', 'نوع المحتوى غير صحيح');
    }

    // ── THE XOR INVARIANT ─────────────────────────────────────────────────

    public function test_creating_under_a_section_leaves_group_id_null(): void
    {
        $item = $this->makeContentItem($this->section);

        $this->assertSame($this->section->getKey(), $item->getAttribute('section_id'));
        $this->assertNull($item->getAttribute('group_id'));
        $this->assertSame($this->course->getKey(), $item->getAttribute('course_id'));
    }

    public function test_creating_under_a_group_leaves_section_id_null(): void
    {
        $item = $this->makeGroupContentItem($this->group);

        $this->assertSame($this->group->getKey(), $item->getAttribute('group_id'));
        $this->assertNull($item->getAttribute('section_id'));
        $this->assertSame($this->course->getKey(), $item->getAttribute('course_id'));
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE INVARIANT IS ENFORCED BY CONSTRUCTION, NOT BY A CHECK.          ║
     * ║                                                                      ║
     * ║ Neither parent column is mass-assignable, so there is no payload —   ║
     * ║ however hostile, however deeply nested — that can set both. The only ║
     * ║ two doors each set exactly one. This test asserts the ABSENCE of the ║
     * ║ door rather than the failure of a request, because "a request that   ║
     * ║ tries it gets an error" is a weaker claim than "there is nothing to  ║
     * ║ try".                                                                ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_neither_parent_column_is_mass_assignable(): void
    {
        $item = new ContentItem();

        $item->fill([
            'title' => 'محاولة',
            'type' => ContentItemType::Note,
            'section_id' => $this->section->getKey(),
            'group_id' => $this->group->getKey(),
            'course_id' => $this->course->getKey(),
        ]);

        $this->assertNull($item->getAttribute('section_id'));
        $this->assertNull($item->getAttribute('group_id'));
        $this->assertNull($item->getAttribute('course_id'));
    }

    /** And the database is the second line of defence if the first is bypassed. */
    public function test_the_database_rejects_a_row_with_two_parents(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('content_items')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'section_id' => $this->section->getKey(),
            'group_id' => $this->group->getKey(),
            'course_id' => $this->course->getKey(),
            'title' => 'أبوان',
            'type' => 'note',
            'order_index' => 0,
            'is_hidden' => 0,
            'created_at' => now(),
        ]);
    }

    public function test_the_database_rejects_a_row_with_no_parent(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('content_items')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'section_id' => null,
            'group_id' => null,
            'course_id' => $this->course->getKey(),
            'title' => 'يتيم',
            'type' => 'note',
            'order_index' => 0,
            'is_hidden' => 0,
            'created_at' => now(),
        ]);
    }

    // ── UNIQUE(content_item_id) ───────────────────────────────────────────

    /**
     * uq_exams_content_item makes the 1:1 structural. A second exam row for one
     * content item would make the configuration a student is graded against
     * ambiguous, and would silently shadow the first in every batch loader's
     * keyed map.
     */
    public function test_a_second_exam_row_for_one_content_item_is_rejected(): void
    {
        $item = $this->makeContentItem($this->section, ['type' => ContentItemType::Exam]);

        $first = new Exam();
        $first->setAttribute('content_item_id', $item->getKey());
        $first->save();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $second = new Exam();
        $second->setAttribute('content_item_id', $item->getKey());
        $second->save();
    }

    // ── order_index ───────────────────────────────────────────────────────

    public function test_order_index_increments_per_section_from_zero(): void
    {
        $this->loginAs($this->owner);

        foreach ([0, 1, 2] as $expected) {
            $this->postJson($this->sectionUrl(), ['title' => 'عنصر', 'type' => 'note'])
                ->assertJsonPath('data.order_index', $expected);
        }
    }

    /**
     * Group-scoped items ALL sit at 0 — createForGroup() passes no order_index
     * and the repository's MAX()+1 branch is reachable only through a
     * section_id. Ported, not forgotten; see ContentItem::createUnderGroup().
     */
    public function test_group_scoped_items_all_sit_at_order_index_zero(): void
    {
        $this->loginAs($this->owner);

        foreach (range(1, 3) as $ignored) {
            $this->postJson($this->groupUrl(), ['title' => 'عنصر', 'type' => 'note'])
                ->assertJsonPath('data.order_index', 0);
        }
    }

    // ── REORDER ───────────────────────────────────────────────────────────

    public function test_reorder_renumbers_by_array_position(): void
    {
        $a = $this->makeContentItem($this->section);
        $b = $this->makeContentItem($this->section);
        $c = $this->makeContentItem($this->section);

        $this->loginAs($this->owner);

        $this->patchJson($this->sectionUrl() . '/reorder', [
            'order' => [$c->getKey(), $a->getKey(), $b->getKey()],
        ])
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم إعادة ترتيب العناصر بنجاح');

        $this->assertSame(0, (int) $c->refresh()->getAttribute('order_index'));
        $this->assertSame(1, (int) $a->refresh()->getAttribute('order_index'));
        $this->assertSame(2, (int) $b->refresh()->getAttribute('order_index'));
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ AN ID FROM A DIFFERENT SECTION HAS **ZERO EFFECT** AND THE REQUEST   ║
     * ║ STILL ANSWERS 200.                                                   ║
     * ║                                                                      ║
     * ║ The `WHERE id = ? AND section_id = ?` predicate matches nothing, and  ║
     * ║ that tolerance is the ported contract — a stale drag-and-drop list    ║
     * ║ must not 422. The foreign item here belongs to ANOTHER TEACHER'S      ║
     * ║ course, so this doubles as the proof that the scoped UPDATE, not the  ║
     * ║ ownership check, is what stops a cross-tenant renumber.               ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_foreign_item_id_is_silently_ignored_and_the_request_still_succeeds(): void
    {
        [$stranger] = $this->makeTeacherWithProfile();
        $otherSection = $this->makeSection($this->makeCourseFor($stranger));
        $foreign = $this->makeContentItem($otherSection, ['order_index' => 7]);

        $mine = $this->makeContentItem($this->section, ['order_index' => 5]);

        $this->loginAs($this->owner);

        $this->patchJson($this->sectionUrl() . '/reorder', [
            'order' => [$foreign->getKey(), $mine->getKey()],
        ])->assertStatus(200);

        $this->assertSame(7, (int) $foreign->refresh()->getAttribute('order_index'), 'The foreign item must be untouched.');
        $this->assertSame(1, (int) $mine->refresh()->getAttribute('order_index'));
    }

    public function test_an_absent_order_key_is_an_empty_reorder_not_an_error(): void
    {
        $this->loginAs($this->owner);

        $this->patchJson($this->sectionUrl() . '/reorder', [])->assertStatus(200);
    }

    // ── DELETE AND CASCADES ───────────────────────────────────────────────

    public function test_deleting_an_item_takes_its_detail_row_with_it(): void
    {
        $item = $this->makeContentItemWithDetail(
            $this->section,
            ContentItemType::Video,
            ['video_url' => 'https://cdn.example/v.m3u8'],
        );

        $this->loginAs($this->owner);

        $this->deleteJson("/api/v1/content-items/{$item->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم حذف العنصر بنجاح');

        $this->assertDatabaseMissing('content_items', ['id' => $item->getKey()]);
        $this->assertDatabaseMissing('videos', ['content_item_id' => $item->getKey()]);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE HISTORICAL-BUGFIX-PRESERVING TEST. DELETING A GROUP THAT HOLDS   ║
     * ║ CONTENT ITEMS MUST SUCCEED.                                          ║
     * ║                                                                      ║
     * ║ fk_content_items_group was ON DELETE **SET NULL** in backend/'s       ║
     * ║ migration 0005 and had to be dropped and recreated as CASCADE in      ║
     * ║ 0015, because `content_items` also carries                            ║
     * ║   CHECK ((section_id IS NULL) <> (group_id IS NULL))                  ║
     * ║ and SET NULL therefore made group deletion IMPOSSIBLE: MySQL nulls    ║
     * ║ group_id on a row whose section_id is already NULL, violates the      ║
     * ║ CHECK, and aborts the whole DELETE.                                   ║
     * ║                                                                      ║
     * ║ CourseGroupCrudTest already pins the FK's DECLARATION. This pins the  ║
     * ║ BEHAVIOUR end to end, with real items and their real detail rows —    ║
     * ║ which is the thing an operator would actually notice breaking.        ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_deleting_a_group_cascades_its_content_items_and_their_details(): void
    {
        $item = ContentItem::createUnderGroup($this->group, [
            'title' => 'فيديو المجموعة',
            'type' => ContentItemType::Video,
        ]);
        \App\Actions\ContentItem\ContentDetailPersisters::apply($item, [
            'video_url' => 'https://cdn.example/group.m3u8',
        ]);

        $note = ContentItem::createUnderGroup($this->group, [
            'title' => 'مذكّرة المجموعة',
            'type' => ContentItemType::Note,
        ]);
        \App\Actions\ContentItem\ContentDetailPersisters::apply($note, ['content_text' => 'نصّ']);

        // A section-scoped item under the SAME course must survive — the
        // cascade is on the group edge, not on the course.
        $survivor = $this->makeContentItem($this->section);

        $this->assertSame(2, ContentItem::query()->where('group_id', $this->group->getKey())->count());

        $this->loginAs($this->owner);

        // No constraint error: the whole point of the CASCADE fix.
        $this->deleteJson("/api/v1/groups/{$this->group->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم حذف المجموعة بنجاح');

        $this->assertDatabaseMissing('course_groups', ['id' => $this->group->getKey()]);
        $this->assertDatabaseMissing('content_items', ['id' => $item->getKey()]);
        $this->assertDatabaseMissing('content_items', ['id' => $note->getKey()]);
        $this->assertDatabaseMissing('videos', ['content_item_id' => $item->getKey()]);
        $this->assertDatabaseMissing('notes', ['content_item_id' => $note->getKey()]);

        $this->assertDatabaseHas('content_items', ['id' => $survivor->getKey()]);
    }

    /** The section edge cascades the same way (fk_content_items_section). */
    public function test_deleting_a_section_cascades_its_content_items(): void
    {
        $item = $this->makeContentItem($this->section);

        $this->loginAs($this->owner);

        $this->deleteJson("/api/v1/sections/{$this->section->getKey()}")->assertStatus(200);

        $this->assertDatabaseMissing('content_items', ['id' => $item->getKey()]);
    }

    // ── THE GROUP ROUTE'S TYPE-CONDITIONAL MESSAGE ────────────────────────

    public function test_the_group_route_announces_an_exam_by_name(): void
    {
        $this->loginAs($this->owner);

        $this->postJson($this->groupUrl(), ['title' => 'امتحان', 'type' => 'exam'])
            ->assertJsonPath('message', 'تم إنشاء الامتحان بنجاح');
    }

    /**
     * …and announces EVERYTHING ELSE as a note, including a video. A real
     * existing quirk of backend/ContentItemController::storeByGroup()'s
     * `$messages[$type] ?? 'تم إنشاء المذكرة بنجاح'` lookup, not an accident
     * to fix — the toast is rendered verbatim by videoApiService.ts.
     */
    public function test_the_group_route_announces_each_type_with_its_own_message(): void
    {
        $this->loginAs($this->owner);

        $this->postJson($this->groupUrl(), [
            'title' => 'فيديو',
            'type' => 'video',
            'video_url' => 'https://cdn.example/v.m3u8',
        ])->assertJsonPath('message', 'تم إضافة الفيديو بنجاح');

        $this->postJson($this->groupUrl(), ['title' => 'رابط', 'type' => 'link', 'url' => 'https://e.com'])
            ->assertJsonPath('message', 'تم إضافة اللينك بنجاح');
    }

    /** `$data['type'] ?? 'note'` — the group route defaults where the section route rejects. */
    public function test_the_group_route_defaults_a_missing_type_to_note(): void
    {
        $this->loginAs($this->owner);

        $this->postJson($this->groupUrl(), ['title' => 'بلا نوع', 'content_text' => 'نصّ'])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'note')
            ->assertJsonPath('data.detail.content_text', 'نصّ');
    }

    /** backend/ read `$data['title']` with no `??` — a 500. It is a 422 now (decision 5.2). */
    public function test_a_group_item_without_a_title_is_422_not_500(): void
    {
        $this->loginAs($this->owner);

        $this->postJson($this->groupUrl(), ['type' => 'note'])->assertStatus(422);
    }

    // ── THE GROUP LISTING ─────────────────────────────────────────────────

    public function test_the_group_listing_returns_the_entitled_payload(): void
    {
        $item = ContentItem::createUnderGroup($this->group, [
            'title' => 'فيديو',
            'type' => ContentItemType::Video,
        ]);
        \App\Actions\ContentItem\ContentDetailPersisters::apply($item, [
            'video_url' => 'https://cdn.example/only.m3u8',
        ]);

        $this->loginAs($this->owner);

        $this->getJson($this->groupUrl())
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.detail.video_url', 'https://cdn.example/only.m3u8')
            ->assertJsonPath('data.0.group_id', $this->group->getKey())
            ->assertJsonPath('data.0.section_id', null);
    }

    /** The listing is group-scoped: a sibling section's items must not appear. */
    public function test_the_group_listing_excludes_section_scoped_items_of_the_same_course(): void
    {
        $this->makeContentItem($this->section);
        $this->makeGroupContentItem($this->group);

        $this->loginAs($this->owner);

        $this->getJson($this->groupUrl())->assertStatus(200)->assertJsonCount(1, 'data');
    }

    // ── SOFT-DELETED COURSE ───────────────────────────────────────────────

    /**
     * A content item whose course was soft-deleted is 404 'Course not found',
     * NOT a 403 and NOT a 500. `content_items` has no `deleted_at`, so the row
     * survives while `$item->course` resolves to null — the exact state
     * SectionPolicy's twin test pins one level up.
     */
    public function test_an_item_under_a_soft_deleted_course_is_404_not_500(): void
    {
        $item = $this->makeContentItem($this->section);

        $this->course->delete();

        $this->loginAs($this->owner);

        $this->putJson("/api/v1/content-items/{$item->getKey()}", ['title' => 'جديد'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Course not found');

        $this->deleteJson("/api/v1/content-items/{$item->getKey()}")->assertStatus(404);
    }

    // ── 404s ──────────────────────────────────────────────────────────────

    public function test_an_unknown_item_id_is_404_with_the_legacy_message(): void
    {
        $this->loginAs($this->owner);

        $this->putJson('/api/v1/content-items/0195c1a2-3b4c-7d8e-9f00-000000000000', ['title' => 'x'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Content item not found');
    }

    public function test_an_unknown_section_id_is_404_section_not_found(): void
    {
        $this->loginAs($this->owner);

        $this->postJson('/api/v1/sections/0195c1a2-3b4c-7d8e-9f00-000000000000/content-items', [
            'title' => 'x', 'type' => 'note',
        ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Section not found');
    }

    public function test_an_unknown_group_id_is_404_group_not_found(): void
    {
        $this->loginAs($this->owner);

        $this->getJson('/api/v1/groups/0195c1a2-3b4c-7d8e-9f00-000000000000/content-items')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Group not found');
    }

    // ── THE FOUR RELATIONS RESOLVE ────────────────────────────────────────

    public function test_every_type_resolves_the_relation_its_dispatcher_names(): void
    {
        $expectations = [
            [ContentItemType::Video, ['video_url' => 'https://e.com/v'], Video::class],
            [ContentItemType::Link, ['url' => 'https://e.com/l'], Link::class],
            [ContentItemType::Note, ['content_text' => 'ن'], Note::class],
            [ContentItemType::Exam, [], Exam::class],
        ];

        foreach ($expectations as [$type, $detail, $class]) {
            $item = $this->makeContentItemWithDetail($this->section, $type, $detail);

            $this->assertInstanceOf($class, $item->detail(), "{$type->value} must resolve a {$class}.");
            $this->assertSame($item->getKey(), $item->detail()?->getAttribute('content_item_id'));
        }

        $file = $this->makeContentItem($this->section, ['type' => ContentItemType::File]);
        $this->assertNull($file->detailRelationName());
        $this->assertNull($file->detail());
    }
}
