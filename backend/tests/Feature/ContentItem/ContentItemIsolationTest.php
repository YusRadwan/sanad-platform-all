<?php

declare(strict_types=1);

namespace Tests\Feature\ContentItem;

use App\Enums\ContentItemType;
use App\Models\ContentItem;
use App\Models\CourseGroup;
use App\Models\Section;
use App\Support\ContentItemAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ TENANT ISOLATION FOR content_items, THROUGH Tests\Concerns\             ║
 * ║ AssertsTenantIsolation — the contract every stage from 4 to 10 imports. ║
 * ║                                                                          ║
 * ║ Nine actors, two layers (the Gate's own answer AND the HTTP status), and ║
 * ║ EVERY actor must appear in every expectation map: "I forgot about        ║
 * ║ cancelled subscribers" must never be silently equivalent to "cancelled   ║
 * ║ subscribers are denied".                                                 ║
 * ║                                                                          ║
 * ║ `otherTeacher` is the load-bearing one here. It is NOT "some teacher who ║
 * ║ does not own it" — the trait seeds a SECOND teacher who owns an          ║
 * ║ EQUIVALENT resource of their own, so a scope that returned "all rows for ║
 * ║ any teacher" has somewhere to leak FROM and gets caught.                 ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * THE THREE REFUSAL STRINGS are asserted separately at the bottom: the trait
 * checks statuses, and rows 23-26 / 78-79 pin three DIFFERENT 403 bodies that
 * a status assertion cannot tell apart. That is the second half of open
 * decision 3.4 and it is a real contract, not a nicety.
 */
final class ContentItemIsolationTest extends TestCase
{
    use AssertsTenantIsolation;
    use RefreshDatabase;

    private Section $ownerSection;

    private CourseGroup $ownerGroup;

    private ContentItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTenantActors();

        $this->ownerSection = $this->makeSection($this->ownerCourse);
        $this->ownerGroup = $this->makeCourseGroup($this->ownerCourse);
        $this->item = $this->makeContentItem($this->ownerSection);

        // The leak target: otherTeacher's OWN equivalent resources.
        $this->makeContentItem($this->makeSection($this->otherCourse));
    }

    /**
     * Every actor but the owner and an admin is denied.
     *
     * ┌── $allowedStatus, AND WHY IT IS SOMETIMES 422. ──────────────────────┐
     * │ assertIsolated() issues each request with NO BODY. On the two POST    │
     * │ routes that is a malformed create, so an ALLOWED actor answers 422    │
     * │ rather than 201 — and that is a STRONGER assertion here, not a        │
     * │ weaker one: authorize() runs BEFORE rules() in Laravel, so a 422      │
     * │ proves the caller got past the ownership gate and reached validation, │
     * │ while every denied actor is still stopped at 403 before any rule ran. │
     * │ The 201 path with a real body is covered by ContentItemCrudTest.      │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    private function ownerOnly(int $allowedStatus = 200, int $deniedStatus = 403): array
    {
        return [
            'admin' => [true, $allowedStatus],
            'ownerTeacher' => [true, $allowedStatus],
            'otherTeacher' => [false, $deniedStatus],
            'subscribedStudent' => [false, $deniedStatus],
            'unsubscribedStudent' => [false, $deniedStatus],
            'expiredSubscriber' => [false, $deniedStatus],
            'cancelledSubscriber' => [false, $deniedStatus],
            'guest' => [false, 401],
            'deactivatedOwner' => self::REJECTED_AT_AUTH_GATE,
        ];
    }

    /** The same matrix without the HTTP half — for routes run logically only. */
    private function ownerOnlyLogical(): array
    {
        return [
            'admin' => true,
            'ownerTeacher' => true,
            'otherTeacher' => false,
            'subscribedStudent' => false,
            'unsubscribedStudent' => false,
            'expiredSubscriber' => false,
            'cancelledSubscriber' => false,
            'guest' => false,
            'deactivatedOwner' => self::REJECTED_AT_AUTH_GATE,
        ];
    }

    // ── THE FOUR ITEM-SCOPED / SECTION-SCOPED ROUTES ──────────────────────

    /**
     * PUT /content-items/{id} — row 24.
     *
     * NOTE the non-owner statuses are 403 and NOT 404: the item exists, it is
     * simply not yours. content_items only becomes a DenyMode (404) surface on
     * the PUBLIC read that Session C builds, where `is_hidden` makes the
     * resource's very EXISTENCE the secret. On a write route reached by an id
     * the caller already had, 403 is the honest answer.
     */
    public function test_only_the_owner_and_an_admin_may_update_an_item(): void
    {
        $this->assertIsolated(
            'manage',
            $this->item,
            $this->ownerOnly(),
            'PUT',
            "/api/v1/content-items/{$this->item->getKey()}",
        );
    }

    /**
     * DELETE /content-items/{id} — row 26. LOGICAL LAYER ONLY, deliberately.
     *
     * assertIsolated() walks the actors in order and the first one (admin) is
     * ALLOWED, so a real HTTP DELETE would destroy the fixture and every later
     * actor would measure a 404 instead of the refusal under test. Running the
     * matrix logically keeps all nine actors honest; the HTTP half is covered
     * by test_each_route_family_carries_its_own_refusal_string() (403 with the
     * item wording), ContentItemAuthGateTest (401 for a guest) and
     * ContentItemCrudTest (200 for the owner).
     */
    public function test_only_the_owner_and_an_admin_may_delete_an_item(): void
    {
        $this->assertIsolated('manage', $this->item, $this->ownerOnlyLogical());
    }

    /** POST /sections/{id}/content-items — row 23. The judgement is on the SECTION. */
    public function test_only_the_owner_and_an_admin_may_create_under_a_section(): void
    {
        $this->assertIsolated(
            'manage',
            $this->ownerSection,
            $this->ownerOnly(422),
            'POST',
            "/api/v1/sections/{$this->ownerSection->getKey()}/content-items",
        );
    }

    /** PATCH .../reorder — row 25. Same ability, same section. */
    public function test_only_the_owner_and_an_admin_may_reorder(): void
    {
        $this->assertIsolated(
            'manage',
            $this->ownerSection,
            $this->ownerOnly(),
            'PATCH',
            "/api/v1/sections/{$this->ownerSection->getKey()}/content-items/reorder",
        );
    }

    // ── THE TWO GROUP-SCOPED ROUTES ───────────────────────────────────────

    /** POST /groups/{id}/content-items — row 79. */
    public function test_only_the_owner_and_an_admin_may_create_under_a_group(): void
    {
        $this->assertIsolated(
            'manage',
            $this->ownerGroup,
            $this->ownerOnly(422),
            'POST',
            "/api/v1/groups/{$this->ownerGroup->getKey()}/content-items",
        );
    }

    /**
     * GET /groups/{id}/content-items — row 78, and the ability is `view`, not
     * `manage`. A subscribed student is denied here even though this is a
     * READ: the group listing serves the FULL entitled payload (video_url and
     * note bodies included), and it is admin/instructor in the final contract
     * too. See App\Policies\CourseGroupPolicy::view().
     */
    public function test_only_the_owner_and_an_admin_may_list_a_groups_content(): void
    {
        $this->assertIsolated(
            'view',
            $this->ownerGroup,
            $this->ownerOnly(),
            'GET',
            "/api/v1/groups/{$this->ownerGroup->getKey()}/content-items",
        );
    }

    // ── THE LEAK DIRECTION, EXPLICITLY ────────────────────────────────────

    /**
     * A foreign instructor cannot renumber our section by naming OUR item in
     * THEIR reorder call, and cannot renumber THEIR items through OUR section.
     * Both directions, because the scoped UPDATE and the ownership gate stop
     * different halves of the attack.
     */
    public function test_a_foreign_instructor_cannot_renumber_across_the_tenant_boundary(): void
    {
        $theirSection = $this->makeSection($this->otherCourse);
        $mine = $this->makeContentItem($this->ownerSection, ['order_index' => 4]);

        $this->loginAs($this->otherTeacher);

        // Their own section, our item id: 200, but the `section_id` predicate
        // matches nothing, so our item is untouched.
        $this->patchJson("/api/v1/sections/{$theirSection->getKey()}/content-items/reorder", [
            'order' => [$mine->getKey()],
        ])->assertStatus(200);

        $this->assertSame(4, (int) $mine->refresh()->getAttribute('order_index'));

        // Our section: refused outright, before any UPDATE is attempted.
        $this->startNextRequest();
        $this->patchJson("/api/v1/sections/{$this->ownerSection->getKey()}/content-items/reorder", [
            'order' => [$mine->getKey()],
        ])->assertStatus(403);

        $this->assertSame(4, (int) $mine->refresh()->getAttribute('order_index'));
    }

    /** A foreign instructor cannot create content inside our section or group. */
    public function test_a_foreign_instructor_cannot_create_inside_our_containers(): void
    {
        $this->loginAs($this->otherTeacher);

        $this->postJson("/api/v1/sections/{$this->ownerSection->getKey()}/content-items", [
            'title' => 'اختراق', 'type' => 'note', 'content_text' => 'ن',
        ])->assertStatus(403);

        $this->startNextRequest();
        $this->postJson("/api/v1/groups/{$this->ownerGroup->getKey()}/content-items", [
            'title' => 'اختراق', 'type' => 'note', 'content_text' => 'ن',
        ])->assertStatus(403);

        $this->assertSame(
            1,
            ContentItem::query()->where('course_id', $this->ownerCourse->getKey())->count(),
            'Only the fixture item may exist under the owner course.'
        );
    }

    // ── THE THREE REFUSAL STRINGS ─────────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THREE DIFFERENT 403 BODIES ON SIX ROUTES — OPEN DECISION 3.4'S       ║
     * ║ SECOND HALF, RESOLVED.                                               ║
     * ║                                                                      ║
     * ║ Session A settled rows 17/19 on 'You do not own this course' and     ║
     * ║ said explicitly that the section/group/item wordings belong to this  ║
     * ║ session and must not be unified on a whim. They are not unified:     ║
     * ║ backend/ContentItemService passes a different literal into the same  ║
     * ║ private guard per entry point, and all three reach a frontend that   ║
     * ║ renders the server's message.                                        ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_each_route_family_carries_its_own_refusal_string(): void
    {
        $this->loginAs($this->otherTeacher);

        $this->postJson("/api/v1/sections/{$this->ownerSection->getKey()}/content-items", [
            'title' => 'x', 'type' => 'note',
        ])->assertStatus(403)->assertJsonPath('message', ContentItemAccess::NOT_OWNER_SECTION);

        $this->startNextRequest();
        $this->patchJson("/api/v1/sections/{$this->ownerSection->getKey()}/content-items/reorder", [])
            ->assertStatus(403)->assertJsonPath('message', ContentItemAccess::NOT_OWNER_SECTION);

        $this->startNextRequest();
        $this->putJson("/api/v1/content-items/{$this->item->getKey()}", ['title' => 'x'])
            ->assertStatus(403)->assertJsonPath('message', ContentItemAccess::NOT_OWNER_ITEM);

        $this->startNextRequest();
        $this->deleteJson("/api/v1/content-items/{$this->item->getKey()}")
            ->assertStatus(403)->assertJsonPath('message', ContentItemAccess::NOT_OWNER_ITEM);

        $this->startNextRequest();
        $this->getJson("/api/v1/groups/{$this->ownerGroup->getKey()}/content-items")
            ->assertStatus(403)->assertJsonPath('message', ContentItemAccess::NOT_OWNER_GROUP);

        $this->startNextRequest();
        $this->postJson("/api/v1/groups/{$this->ownerGroup->getKey()}/content-items", [
            'title' => 'x', 'type' => 'note',
        ])->assertStatus(403)->assertJsonPath('message', ContentItemAccess::NOT_OWNER_GROUP);
    }

    // ── THE POLICY'S OWN HOP ──────────────────────────────────────────────

    /**
     * ContentItemPolicy::manage() resolves ownership through the DENORMALISED
     * `content_items.course_id` — one hop, not two, and the only hop that
     * works for a GROUP-scoped item, which has no section chain at all.
     */
    public function test_the_policy_resolves_a_group_scoped_item_through_course_id(): void
    {
        $groupItem = ContentItem::createUnderGroup($this->ownerGroup, [
            'title' => 'عنصر مجموعة',
            'type' => ContentItemType::Note,
        ]);

        $this->assertNull($groupItem->getAttribute('section_id'));
        $this->assertTrue(Gate::forUser($this->ownerTeacher)->allows('manage', $groupItem));
        $this->assertFalse(Gate::forUser($this->otherTeacher)->allows('manage', $groupItem));
    }

    /**
     * An ORPHANED item — its course soft-deleted — belongs to nobody, so the
     * policy fails CLOSED even for the original owner. The controller layer
     * turns the same fact into a 404 'Course not found' one step earlier.
     */
    public function test_an_orphaned_item_belongs_to_nobody(): void
    {
        $this->ownerCourse->delete();

        $this->assertFalse(Gate::forUser($this->ownerTeacher)->allows('manage', $this->item->fresh()));
    }
}
