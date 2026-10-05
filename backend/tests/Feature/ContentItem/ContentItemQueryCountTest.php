<?php

declare(strict_types=1);

namespace Tests\Feature\ContentItem;

use App\Enums\ContentItemType;
use App\Models\ContentItem;
use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE TYPE-GROUPED EAGER LOAD, MEASURED IN QUERIES.                       ║
 * ║                                                                          ║
 * ║ backend/ContentItemService::presentMany() buckets rows by `type` and     ║
 * ║ issues at most ONE query per type ACTUALLY PRESENT. The idiomatic-       ║
 * ║ looking Eloquent translation                                             ║
 * ║   ->with(['video','link','note','exam'])                                 ║
 * ║ is a REGRESSION: `with()` fires one SELECT per named relation            ║
 * ║ unconditionally, so a list of ten videos would run four extra queries    ║
 * ║ whose IN() lists can only come back empty.                               ║
 * ║                                                                          ║
 * ║ These tests count real queries. A boolean assertion that "eager loading  ║
 * ║ is on" would pass under either implementation and prove nothing.         ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class ContentItemQueryCountTest extends TestCase
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

    /**
     * Count only the queries hitting the four DETAIL tables.
     *
     * Deliberately NOT the total query count of a request: that also carries
     * session lookups, the auth guard, the group and course resolution, and
     * the policy — all of which are constant with respect to the thing under
     * test, and any of which could change for unrelated reasons and turn this
     * into a brittle test that gets "fixed" by raising a number.
     *
     * @return array<string, int>  table => query count
     */
    private function detailQueriesDuring(callable $body): array
    {
        $counts = ['videos' => 0, 'links' => 0, 'notes' => 0, 'exams' => 0];

        DB::listen(static function ($query) use (&$counts): void {
            foreach (array_keys($counts) as $table) {
                if (str_contains($query->sql, "`{$table}`")) {
                    $counts[$table]++;
                }
            }
        });

        $body();

        return $counts;
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ TEN VIDEOS -> **ONE** `videos` QUERY AND **ZERO** OF THE OTHER FOUR. ║
     * ║                                                                      ║
     * ║ The two halves are equally load-bearing. "1" rules out the N+1        ║
     * ║ (which would be 10); "0, 0, 0, 0" rules out the blanket with(), which ║
     * ║ would be 1 each. Only the type-grouped implementation produces both.  ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_ten_same_type_items_cost_one_detail_query_and_no_others(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $item = ContentItem::createUnderGroup($this->group, [
                'title' => "درس {$i}",
                'type' => ContentItemType::Video,
            ]);
            \App\Actions\ContentItem\ContentDetailPersisters::apply($item, [
                'video_url' => "https://cdn.example/{$i}.m3u8",
            ]);
        }

        $this->loginAs($this->owner);

        $counts = $this->detailQueriesDuring(function (): void {
            $this->getJson("/api/v1/groups/{$this->group->getKey()}/content-items")
                ->assertStatus(200)
                ->assertJsonCount(10, 'data')
                ->assertJsonPath('data.0.detail.video_url', 'https://cdn.example/0.m3u8');
        });

        $this->assertSame(1, $counts['videos'], 'Ten videos must cost exactly ONE `videos` query, not ten.');

        foreach (['links', 'notes', 'exams'] as $unused) {
            $this->assertSame(
                0,
                $counts[$unused],
                "`{$unused}` must not be queried at all — no item in the list has that type. "
                . 'A non-zero count here means the loader is naming all four relations '
                . 'instead of grouping by the types actually present.'
            );
        }
    }

    /** …and the cost does not grow with the list. Twenty videos, still one query. */
    public function test_the_detail_query_count_does_not_scale_with_the_list_size(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $item = ContentItem::createUnderGroup($this->group, [
                'title' => "درس {$i}",
                'type' => ContentItemType::Video,
            ]);
            \App\Actions\ContentItem\ContentDetailPersisters::apply($item, [
                'video_url' => "https://cdn.example/{$i}.m3u8",
            ]);
        }

        $this->loginAs($this->owner);

        $counts = $this->detailQueriesDuring(function (): void {
            $this->getJson("/api/v1/groups/{$this->group->getKey()}/content-items")
                ->assertStatus(200)
                ->assertJsonCount(20, 'data');
        });

        $this->assertSame(1, $counts['videos']);
    }

    /**
     * TWO types present -> exactly TWO detail queries, and still zero for the
     * three that are absent. The count tracks the types in the list, not the
     * number of types the schema has.
     */
    public function test_two_types_present_cost_exactly_two_detail_queries(): void
    {
        foreach (range(1, 5) as $i) {
            $video = ContentItem::createUnderGroup($this->group, ['title' => "ف{$i}", 'type' => ContentItemType::Video]);
            \App\Actions\ContentItem\ContentDetailPersisters::apply($video, ['video_url' => "https://e.com/{$i}"]);

            $note = ContentItem::createUnderGroup($this->group, ['title' => "م{$i}", 'type' => ContentItemType::Note]);
            \App\Actions\ContentItem\ContentDetailPersisters::apply($note, ['content_text' => "نصّ {$i}"]);
        }

        $this->loginAs($this->owner);

        $counts = $this->detailQueriesDuring(function (): void {
            $this->getJson("/api/v1/groups/{$this->group->getKey()}/content-items")
                ->assertStatus(200)
                ->assertJsonCount(10, 'data');
        });

        $this->assertSame(1, $counts['videos']);
        $this->assertSame(1, $counts['notes']);
        $this->assertSame(0, $counts['links']);
        $this->assertSame(0, $counts['exams']);
    }

    /**
     * A list of `file` items queries NOTHING. relationNameFor() answers null
     * for that type, so the bucket is skipped entirely rather than producing
     * an empty IN() against some default table.
     */
    public function test_a_list_of_file_items_queries_no_detail_table_at_all(): void
    {
        foreach (range(1, 5) as $i) {
            ContentItem::createUnderGroup($this->group, ['title' => "ملف {$i}", 'type' => ContentItemType::File]);
        }

        $this->loginAs($this->owner);

        $counts = $this->detailQueriesDuring(function (): void {
            $this->getJson("/api/v1/groups/{$this->group->getKey()}/content-items")
                ->assertStatus(200)
                ->assertJsonCount(5, 'data')
                ->assertJsonPath('data.0.detail', null);
        });

        $this->assertSame(array_fill_keys(['videos', 'links', 'notes', 'exams'], 0), $counts);
    }

    /** An empty list touches nothing either — groupBy over nothing loops zero times. */
    public function test_an_empty_list_queries_no_detail_table(): void
    {
        $this->loginAs($this->owner);

        $counts = $this->detailQueriesDuring(function (): void {
            $this->getJson("/api/v1/groups/{$this->group->getKey()}/content-items")
                ->assertStatus(200)
                ->assertJsonCount(0, 'data');
        });

        $this->assertSame(array_fill_keys(['videos', 'links', 'notes', 'exams'], 0), $counts);
    }

    /**
     * The helper is exercised directly too, so the guarantee is a property of
     * ContentItem::loadDetailsFor() and not of one controller that happens to
     * call it correctly. All four types present -> four queries, never more.
     */
    public function test_the_loader_issues_one_query_per_type_present_and_no_more(): void
    {
        $specs = [
            [ContentItemType::Video, ['video_url' => 'https://e.com/v']],
            [ContentItemType::Link, ['url' => 'https://e.com/l']],
            [ContentItemType::Note, ['content_text' => 'ن']],
            [ContentItemType::Exam, []],
        ];

        foreach ($specs as [$type, $detail]) {
            foreach (range(1, 3) as $ignored) {
                $this->makeContentItemWithDetail($this->section, $type, $detail);
            }
        }

        $items = ContentItem::query()->where('section_id', $this->section->getKey())->get();

        $counts = $this->detailQueriesDuring(static function () use ($items): void {
            ContentItem::loadDetailsFor($items);
        });

        $this->assertSame(
            ['videos' => 1, 'links' => 1, 'notes' => 1, 'exams' => 1],
            $counts,
            'Twelve items across four types must cost four queries — one per type, never one per item.'
        );

        $this->assertCount(12, $items);
        $this->assertNotNull($items->first()?->detail());
    }
}
