<?php

declare(strict_types=1);

namespace Tests\Feature\ContentItem;

use App\Enums\ContentItemType;
use App\Enums\SubscriptionStatus;
use App\Models\ContentItem;
use App\Models\Course;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * The ACTOR MATRIX for the four public reads — freeze-table rows 10, 20, 21, 22.
 *
 * PublicPayloadLeakTest proves the un-entitled shape cannot carry a paid field.
 * This file proves the other half: that the right actor gets the right shape,
 * and that decision 3.6 and the hidden-item rules hold on every route.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ACTOR THAT DECIDES EVERY INTERESTING QUESTION IS THE SUBSCRIBED     ║
 * ║ STUDENT, AND THE REASON IS THAT THEY ANSWER **DIFFERENTLY** TO THE TWO  ║
 * ║ GATES THIS SESSION CONSULTS ON THE SAME COURSE IN THE SAME REQUEST:     ║
 * ║                                                                          ║
 * ║   consumeContent  YES  -> they get the full `detail` payload            ║
 * ║   viewHidden      NO   -> a hidden item is filtered / 404 for them,     ║
 * ║                           exactly as it is for an anonymous guest       ║
 * ║                                                                          ║
 * ║ Every test below that names them is written so that "simplifying"       ║
 * ║ viewHidden to canConsume() — the single most likely mistake in this     ║
 * ║ layer — fails the suite loudly.                                         ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class PublicContentReadTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $owner;

    private User $subscriber;

    private User $unsubscribed;

    private User $admin;

    private Course $course;

    private Section $section;

    private ContentItem $visible;

    private ContentItem $hidden;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->owner] = $this->makeTeacherWithProfile();
        $this->admin = $this->makeAdmin();
        $this->course = $this->makeCourseFor($this->owner, ['is_published' => true]);
        $this->section = $this->makeSection($this->course);

        $this->visible = $this->makeContentItemWithDetail(
            $this->section,
            ContentItemType::Video,
            ['video_url' => 'https://example.test/visible.m3u8', 'duration' => 600],
            ['title' => 'درس ظاهر', 'is_hidden' => false],
        );

        $this->hidden = $this->makeContentItemWithDetail(
            $this->section,
            ContentItemType::Video,
            ['video_url' => 'https://example.test/hidden.m3u8'],
            ['title' => 'درس مخفي', 'is_hidden' => true],
        );

        $this->subscriber = $this->makeStudentUser();
        $this->makeSubscription($this->subscriber, $this->course);

        $this->unsubscribed = $this->makeStudentUser();
    }

    // ══ 1. THE PAYLOAD SHAPE, PER ACTOR ═══════════════════════════════════

    /**
     * FOUR UNENTITLED ACTORS, THE PUBLIC SHAPE ON ALL FOUR READS.
     *
     * The expired and cancelled subscribers are the ones worth having: a
     * `subscriptions.exists()` check that forgot Subscription::scopeActive()
     * would hand both of them the paid payload, and no guest-only test would
     * ever notice.
     */
    public function test_every_unentitled_actor_gets_the_public_shape_on_a_published_course(): void
    {
        $expired = $this->makeStudentUser();
        $this->makeSubscription($expired, $this->course, expiresAt: now()->subMinute());

        $cancelled = $this->makeStudentUser();
        $this->makeSubscription($cancelled, $this->course, SubscriptionStatus::Cancelled);

        $stranger = $this->makeTeacherWithProfile()[0];

        foreach ([null, $this->unsubscribed, $expired, $cancelled, $stranger] as $actor) {
            $this->actingAsFresh($actor);
            $item = $this->getJson("/api/v1/content-items/{$this->visible->getKey()}")
                ->assertOk()
                ->json('data');

            $label = $actor?->role->value ?? 'guest';

            $this->assertArrayNotHasKey('detail', $item, "{$label} received the private shape.");
            $this->assertArrayHasKey('isPreview', $item, "{$label} did not receive the public shape.");
            $this->assertSame(600, $item['duration']);

            $this->actingAsFresh($actor);
            $list = $this->getJson("/api/v1/sections/{$this->section->getKey()}/content-items")
                ->assertOk()
                ->json('data');

            $this->assertArrayNotHasKey('detail', $list[0]);

            $this->actingAsFresh($actor);
            $videos = $this->getJson("/api/v1/courses/{$this->course->getKey()}/videos")
                ->assertOk()
                ->json('data');

            $this->assertArrayNotHasKey('detail', $videos[0]);
        }
    }

    /** …and the three ENTITLED actors get the full one. */
    public function test_the_subscriber_the_owner_and_an_admin_all_get_the_full_shape(): void
    {
        foreach ([$this->subscriber, $this->owner, $this->admin] as $actor) {
            $this->actingAsFresh($actor);

            $item = $this->getJson("/api/v1/content-items/{$this->visible->getKey()}")
                ->assertOk()
                ->json('data');

            $this->assertArrayHasKey('detail', $item, "{$actor->role->value} was denied the payload.");
            $this->assertSame('https://example.test/visible.m3u8', $item['detail']['video_url']);
        }
    }

    /**
     * THE SUBSCRIBED STUDENT, STATED AS ONE FACT: entitled to the payload,
     * NOT entitled to hidden material. Both halves in one test so they cannot
     * drift apart.
     */
    public function test_a_subscriber_gets_the_payload_but_never_the_hidden_item(): void
    {
        $this->actingAsFresh($this->subscriber);
        $visible = $this->getJson("/api/v1/content-items/{$this->visible->getKey()}")->assertOk();
        $this->assertArrayHasKey('detail', $visible->json('data'));

        $this->actingAsFresh($this->subscriber);
        $this->getJson("/api/v1/content-items/{$this->hidden->getKey()}")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Content item not found');
    }

    // ══ 2. HIDDEN — TWO DIFFERENT SHAPES FOR ONE RULE ═════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE SAME AUTHORIZATION OUTCOME, TWO DELIBERATELY DIFFERENT           ║
     * ║ RESPONSES. THEY ARE NOT UNIFIED AND MUST NOT BE.                     ║
     * ║                                                                      ║
     * ║   LIST   -> the hidden item is SILENTLY ABSENT; 200.                 ║
     * ║   SINGLE -> 404 'Content item not found'.                            ║
     * ║                                                                      ║
     * ║ Unifying either way breaks a real caller: a 404 on the list would    ║
     * ║ take the public syllabus offline whenever an instructor stages next  ║
     * ║ term's material, and a filtered-to-nothing 200 on a direct id fetch  ║
     * ║ would be a lie about a specific resource.                            ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_hidden_item_is_filtered_from_the_list_but_404s_on_a_direct_fetch(): void
    {
        foreach ([null, $this->unsubscribed, $this->subscriber] as $actor) {
            $label = $actor?->role->value ?? 'guest';

            $this->actingAsFresh($actor);
            $ids = array_column(
                $this->getJson("/api/v1/sections/{$this->section->getKey()}/content-items")
                    ->assertOk()
                    ->json('data'),
                'id'
            );

            $this->assertContains($this->visible->getKey(), $ids, "{$label} lost the visible item.");
            $this->assertNotContains(
                $this->hidden->getKey(),
                $ids,
                "{$label} saw a HIDDEN item in the section listing."
            );

            $this->actingAsFresh($actor);
            $this->getJson("/api/v1/content-items/{$this->hidden->getKey()}")
                ->assertStatus(404)
                ->assertJsonPath('message', 'Content item not found');
        }
    }

    /** The owner sees it in BOTH, with full detail — the control for the above. */
    public function test_the_owner_sees_the_hidden_item_in_the_list_and_on_a_direct_fetch(): void
    {
        foreach ([$this->owner, $this->admin] as $actor) {
            $this->actingAsFresh($actor);
            $ids = array_column(
                $this->getJson("/api/v1/sections/{$this->section->getKey()}/content-items")
                    ->assertOk()
                    ->json('data'),
                'id'
            );

            $this->assertContains($this->hidden->getKey(), $ids);

            $this->actingAsFresh($actor);
            $payload = $this->getJson("/api/v1/content-items/{$this->hidden->getKey()}")
                ->assertOk()
                ->json('data');

            $this->assertSame('https://example.test/hidden.m3u8', $payload['detail']['video_url']);
        }
    }

    /**
     * ┌── ROW 22 IS THE EXCEPTION, AND IT IS PORTED, NOT AN INCONSISTENCY. ──┐
     * │ backend/listByCourseAndType() puts `is_hidden = 0` IN THE SQL with   │
     * │ no caller-dependent branch, so this listing has never shown a hidden │
     * │ video to ANYBODY — not an admin, and not the instructor who hid it.  │
     * │ Row 20's filter is entitlement-dependent; this one is not. Both are  │
     * │ real, and this test is what stops a future tidy-up from "making them │
     * │ consistent".                                                          │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_the_video_listing_hides_a_hidden_video_from_the_owner_too(): void
    {
        foreach ([$this->owner, $this->admin] as $actor) {
            $this->actingAsFresh($actor);

            $ids = array_column(
                $this->getJson("/api/v1/courses/{$this->course->getKey()}/videos")
                    ->assertOk()
                    ->json('data'),
                'id'
            );

            $this->assertContains($this->visible->getKey(), $ids);
            $this->assertNotContains(
                $this->hidden->getKey(),
                $ids,
                'Row 22 filters is_hidden unconditionally — including for the owner.'
            );
        }
    }

    /** The course-wide video listing spans GROUP-owned items too. */
    public function test_the_video_listing_includes_a_group_owned_video(): void
    {
        $group = $this->makeCourseGroup($this->course);
        $groupVideo = $this->makeGroupContentItem($group, ['type' => ContentItemType::Video]);

        $this->actingAsFresh(null);

        $ids = array_column(
            $this->getJson("/api/v1/courses/{$this->course->getKey()}/videos")->assertOk()->json('data'),
            'id'
        );

        $this->assertContains($groupVideo->getKey(), $ids);
    }

    /** Non-video types never appear in the video listing. */
    public function test_the_video_listing_carries_only_videos(): void
    {
        $this->makeContentItem($this->section, ['type' => ContentItemType::Note]);

        $this->actingAsFresh(null);

        $types = array_column(
            $this->getJson("/api/v1/courses/{$this->course->getKey()}/videos")->assertOk()->json('data'),
            'type'
        );

        $this->assertSame(['video'], array_values(array_unique($types)));
    }

    // ══ 3. DECISION 3.6 — THE DRAFT-COURSE CASCADE ════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ A DRAFT COURSE'S CONTENT IS 404 ON ALL FOUR READS FOR EVERYONE BUT   ║
     * ║ ITS OWNER AND AN ADMIN — INCLUDING FOR A SUBSCRIBED STUDENT.         ║
     * ║                                                                      ║
     * ║ Decision 3.6 was settled in Session A for the course row and its     ║
     * ║ sections. Hiding those while the content under them stayed readable  ║
     ║ ║ would hide nothing: the lessons ARE the course.                      ║
     * ║                                                                      ║
     * ║ The refusal is 'Course not found', identical to a wholly unknown id, ║
     * ║ so the response cannot be used as an oracle for the draft's          ║
     * ║ existence.                                                            ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_draft_courses_content_is_404_on_all_four_reads_for_a_non_owner(): void
    {
        [$otherOwner] = $this->makeTeacherWithProfile();
        $draft = $this->makeCourseFor($otherOwner, ['is_published' => false]);
        $draftSection = $this->makeSection($draft);
        $draftItem = $this->makeContentItem($draftSection, ['type' => ContentItemType::Video]);

        $subscriber = $this->makeStudentUser();
        $this->makeSubscription($subscriber, $draft);

        $urls = [
            "/api/v1/courses/{$draft->getKey()}/structure",
            "/api/v1/sections/{$draftSection->getKey()}/content-items",
            "/api/v1/content-items/{$draftItem->getKey()}",
            "/api/v1/courses/{$draft->getKey()}/videos",
        ];

        foreach ([null, $this->unsubscribed, $subscriber, $this->owner] as $actor) {
            foreach ($urls as $url) {
                $this->actingAsFresh($actor);

                $this->getJson($url)
                    ->assertStatus(404)
                    ->assertJsonPath('message', 'Course not found');
            }
        }
    }

    /** …and 200 for the draft's own owner and for an admin. */
    public function test_a_draft_courses_content_is_readable_by_its_owner_and_an_admin(): void
    {
        $draft = $this->makeCourseFor($this->owner, ['is_published' => false]);
        $draftSection = $this->makeSection($draft);
        $draftItem = $this->makeContentItem($draftSection, ['type' => ContentItemType::Video]);

        $urls = [
            "/api/v1/courses/{$draft->getKey()}/structure",
            "/api/v1/sections/{$draftSection->getKey()}/content-items",
            "/api/v1/content-items/{$draftItem->getKey()}",
            "/api/v1/courses/{$draft->getKey()}/videos",
        ];

        foreach ([$this->owner, $this->admin] as $actor) {
            foreach ($urls as $url) {
                $this->actingAsFresh($actor);
                $this->getJson($url)->assertOk();
            }
        }
    }

    // ══ 4. THE REST OF THE REFUSAL LADDER ════════════════════════════════

    public function test_an_unknown_section_is_404_section_not_found(): void
    {
        $this->actingAsFresh(null);

        $this->getJson('/api/v1/sections/' . \Illuminate\Support\Str::uuid7() . '/content-items')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Section not found');
    }

    public function test_an_unknown_content_item_is_404_content_item_not_found(): void
    {
        $this->actingAsFresh(null);

        $this->getJson('/api/v1/content-items/' . \Illuminate\Support\Str::uuid7())
            ->assertStatus(404)
            ->assertJsonPath('message', 'Content item not found');
    }

    /**
     * A SOFT-DELETED COURSE LEAVES ITS CONTENT IN PLACE — deliberately, per
     * backend/CourseService::delete(). Every route must answer 404 'Course not
     * found' for the orphan rather than 500 on a null relation. This is the
     * `$item->course === null` branch, exercised over HTTP on all four reads.
     */
    public function test_content_under_a_soft_deleted_course_is_404_not_500(): void
    {
        $item = $this->visible;
        $this->course->delete();

        $urls = [
            "/api/v1/courses/{$this->course->getKey()}/structure",
            "/api/v1/sections/{$this->section->getKey()}/content-items",
            "/api/v1/content-items/{$item->getKey()}",
            "/api/v1/courses/{$this->course->getKey()}/videos",
        ];

        foreach ([null, $this->owner, $this->admin] as $actor) {
            foreach ($urls as $url) {
                $this->actingAsFresh($actor);

                $this->getJson($url)
                    ->assertStatus(404)
                    ->assertJsonPath('message', 'Course not found');
            }
        }
    }

    /** Ordering is `order_index ASC, id ASC` — stated on the relation, once. */
    public function test_the_section_listing_is_ordered_by_order_index(): void
    {
        $second = $this->makeContentItem($this->section, ['order_index' => 99]);
        $first = $this->makeContentItem($this->section, ['order_index' => -5]);

        $this->actingAsFresh(null);

        $ids = array_column(
            $this->getJson("/api/v1/sections/{$this->section->getKey()}/content-items")
                ->assertOk()
                ->json('data'),
            'id'
        );

        $this->assertSame($first->getKey(), $ids[0]);
        $this->assertSame($second->getKey(), end($ids));
    }

    /** A section listing never bleeds another section's items. */
    public function test_the_section_listing_is_scoped_to_its_own_section(): void
    {
        $other = $this->makeSection($this->course);
        $foreign = $this->makeContentItem($other);

        $this->actingAsFresh(null);

        $ids = array_column(
            $this->getJson("/api/v1/sections/{$this->section->getKey()}/content-items")
                ->assertOk()
                ->json('data'),
            'id'
        );

        $this->assertNotContains($foreign->getKey(), $ids);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** A clean session per assertion — null means an anonymous visitor. */
    private function actingAsFresh(?User $user): void
    {
        $this->flushSession();
        $this->startNextRequest();

        if ($user !== null) {
            $this->loginAs($user);
            $this->startNextRequest();
        }
    }
}
