<?php

declare(strict_types=1);

namespace Tests\Feature\ContentItem;

use App\Enums\ContentItemType;
use App\Models\ContentItem;
use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\Link;
use App\Models\Section;
use App\Models\User;
use App\Models\Video;
use App\Rules\SafeUrlValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ AN UNSAFE URL IS REJECTED **BEFORE THE PARENT ROW EXISTS** — AND THAT   ║
 * ║ IS ASSERTED AGAINST THE DATABASE, NOT AGAINST THE RESPONSE CODE.       ║
 * ║                                                                          ║
 * ║ backend/ContentItemService validates URLs twice and explains why:        ║
 * ║ saveTypeDetail() is the single write path to the videos/links tables and ║
 * ║ must be safe on its own, but it runs AFTER content_items::create() — so  ║
 * ║ a rejected URL aborted the request with the parent row already inserted, ║
 * ║ leaving an orphan. validateUrls() was added ahead of the insert to close ║
 * ║ that.                                                                    ║
 * ║                                                                          ║
 * ║ In backend-v2 the rejection is a Form Request rule, i.e. it happens      ║
 * ║ before the controller is entered at all — the same guarantee obtained    ║
 * ║ structurally instead of by remembering to pre-validate. A 422 alone      ║
 * ║ would not prove it; every test below counts rows.                        ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class ContentItemUrlSafetyTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    /** The full hostile set from App\Support\SafeUrl's docblock. */
    public static function unsafeUrls(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)'],
            'uppercased javascript' => ['JavaScript:alert(1)'],
            'data uri' => ['data:text/html,<script>alert(1)</script>'],
            'vbscript scheme' => ['vbscript:msgbox(1)'],
            'file scheme' => ['file:///etc/passwd'],
            'protocol relative' => ['//evil.example/steal'],
            'bare host' => ['evil.example/steal'],
            'control character bypass' => ["java\tscript:alert(1)"],
            'newline bypass' => ["java\nscript:alert(1)"],
        ];
    }

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

        $this->loginAs($this->owner);
    }

    // ── CREATE: NO ORPHANED PARENT ROW ────────────────────────────────────

    /**
     * @dataProvider unsafeUrls
     */
    public function test_a_rejected_video_url_leaves_no_content_items_row_behind(string $url): void
    {
        $this->postJson("/api/v1/sections/{$this->section->getKey()}/content-items", [
            'title' => 'درس خبيث',
            'type' => 'video',
            'video_url' => $url,
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.video_url.0', SafeUrlValue::MESSAGE);

        $this->assertSame(0, ContentItem::query()->count(), 'No parent row may survive a rejected URL.');
        $this->assertSame(0, Video::query()->count());
    }

    /**
     * @dataProvider unsafeUrls
     */
    public function test_a_rejected_link_url_leaves_no_content_items_row_behind(string $url): void
    {
        $this->postJson("/api/v1/sections/{$this->section->getKey()}/content-items", [
            'title' => 'رابط خبيث',
            'type' => 'link',
            'url' => $url,
        ])->assertStatus(422);

        $this->assertSame(0, ContentItem::query()->count());
        $this->assertSame(0, Link::query()->count());
    }

    /** The same guarantee on the GROUP creation path. */
    public function test_the_group_route_leaves_no_row_behind_either(): void
    {
        $this->postJson("/api/v1/groups/{$this->group->getKey()}/content-items", [
            'title' => 'درس خبيث',
            'type' => 'video',
            'video_url' => 'javascript:alert(1)',
        ])->assertStatus(422);

        $this->assertSame(0, ContentItem::query()->count());
    }

    /** A hostile POSTER is rejected too — thumbnail_url feeds an <img src>. */
    public function test_a_hostile_thumbnail_url_is_rejected_and_leaves_no_row(): void
    {
        $this->postJson("/api/v1/sections/{$this->section->getKey()}/content-items", [
            'title' => 'درس',
            'type' => 'video',
            'video_url' => 'https://cdn.example/ok.m3u8',
            'thumbnail_url' => 'javascript:alert(1)',
        ])->assertStatus(422);

        $this->assertSame(0, ContentItem::query()->count());
    }

    // ── UPDATE: NO HALF-APPLIED WRITE ─────────────────────────────────────

    /**
     * backend/'s comment on update(): "Reject a bad URL before ANY column is
     * written, so a rejected update cannot half-apply (title changed, url
     * rejected)." Here the Form Request guarantees it structurally.
     */
    public function test_a_rejected_update_does_not_half_apply_the_title(): void
    {
        $item = $this->makeContentItemWithDetail($this->section, ContentItemType::Video, [
            'video_url' => 'https://cdn.example/original.m3u8',
        ], ['title' => 'العنوان الأصلي']);

        $this->putJson("/api/v1/content-items/{$item->getKey()}", [
            'title' => 'عنوان جديد',
            'video_url' => 'javascript:alert(1)',
        ])->assertStatus(422);

        $this->assertSame('العنوان الأصلي', $item->refresh()->getAttribute('title'));
        $this->assertSame(
            'https://cdn.example/original.m3u8',
            Video::query()->find($item->getKey())?->getAttribute('video_url')
        );
    }

    // ── WHAT IS ACCEPTED ──────────────────────────────────────────────────

    public function test_the_three_allowed_shapes_are_accepted(): void
    {
        $accepted = [
            'https://cdn.example/lesson.m3u8',
            'http://cdn.example/lesson.m3u8',
            '/uploads/videos/lesson.m3u8',
        ];

        foreach ($accepted as $url) {
            $this->postJson("/api/v1/sections/{$this->section->getKey()}/content-items", [
                'title' => 'درس',
                'type' => 'video',
                'video_url' => $url,
            ])
                ->assertStatus(201)
                ->assertJsonPath('data.detail.video_url', $url);
        }
    }

    /** The value is TRIMMED, reproducing UrlValidator::required()'s return. */
    public function test_an_accepted_url_is_stored_trimmed(): void
    {
        $this->postJson("/api/v1/sections/{$this->section->getKey()}/content-items", [
            'title' => 'درس',
            'type' => 'video',
            'video_url' => '  https://cdn.example/lesson.m3u8  ',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.detail.video_url', 'https://cdn.example/lesson.m3u8');
    }

    // ── THE '' ASYMMETRY BETWEEN THE TWO URL FIELDS ───────────────────────

    /**
     * `video_url: ''` is a 422 (UrlValidator::required) while
     * `thumbnail_url: ''` is a CLEAR (UrlValidator::nullable). Two entry
     * points, one difference, both ported — asserted in ONE test so the
     * asymmetry is the subject rather than a coincidence of two files.
     */
    public function test_an_empty_video_url_is_422_while_an_empty_thumbnail_is_a_clear(): void
    {
        $url = "/api/v1/sections/{$this->section->getKey()}/content-items";

        $this->postJson($url, ['title' => 'د', 'type' => 'video', 'video_url' => ''])
            ->assertStatus(422);

        $this->postJson($url, [
            'title' => 'د',
            'type' => 'video',
            'video_url' => 'https://cdn.example/ok.m3u8',
            'thumbnail_url' => '',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.detail.thumbnail_url', null);
    }

    // ── THE RULE IS TYPE-SCOPED, AS backend/ IS ───────────────────────────

    /**
     * A NOTE carrying a junk `url` key is accepted, because
     * ContentItemService::validateUrls() only looks at `url` when the type is
     * 'link'. The value is never stored and never rendered, so 422-ing it
     * would break a currently-succeeding request for no security gain.
     */
    public function test_a_note_carrying_a_junk_url_key_is_still_accepted(): void
    {
        $this->postJson("/api/v1/sections/{$this->section->getKey()}/content-items", [
            'title' => 'مذكّرة',
            'type' => 'note',
            'content_text' => 'نصّ',
            'url' => 'javascript:alert(1)',
        ])->assertStatus(201);

        $this->assertSame(1, ContentItem::query()->count());
        $this->assertSame(0, Link::query()->count(), 'The junk value must never be persisted.');
    }
}
