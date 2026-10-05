<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Enums\ContentItemType;
use App\Models\ContentItem;
use App\Models\Section;
use App\Policies\ContentItemPolicy;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\Concerns\CreatesUploadFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE NINE-ACTOR MATRIX ON `ContentItem::downloadFile` — FREEZE-TABLE ROW ║
 * ║ 73, THE ROUTE THAT HANDS OUT PRIVATE COURSE MATERIAL.                   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Two layers per actor: the Gate's own answer AND the real HTTP status. Every
 * actor in AssertsTenantIsolation::ACTORS must be accounted for, so "I forgot
 * about cancelled subscribers" cannot be silently equivalent to "cancelled
 * subscribers are denied".
 *
 * ┌── THE LOGICAL LAYER IS ONLY POSSIBLE BECAUSE THE ABILITY DOES NOT ───────┐
 * │ THROW. Layer 1 is `Gate::forUser($actor)->allows(...)`. An ability built │
 * │ on App\Support\Authorization\DenyMode raises a NotFoundException, which  │
 * │ escapes allows() as readily as authorize() (open decision 3.5) — so the  │
 * │ whole matrix would ERROR rather than measure. downloadFile() returns an  │
 * │ `Illuminate\Auth\Access\Response` instead, which carries the 404 as DATA │
 * │ and reads as a clean `false` here. That choice is what makes this file   │
 * │ possible at all; see the policy's banner.                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `otherTeacher` is load-bearing: the trait seeds a SECOND teacher with an
 * EQUIVALENT note of their own, so a resolver that returned "any documents/
 * file for any teacher" has somewhere to leak FROM and gets caught.
 */
final class ContentItemDownloadIsolationTest extends TestCase
{
    use AssertsTenantIsolation;
    use CreatesUploadFixtures;
    use RefreshDatabase;

    /** @var list<string> */
    private array $inventoryBefore = [];

    private Section $ownerSection;

    private ContentItem $note;

    private ContentItem $hiddenNote;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryBefore = $this->currentUploadInventory();

        $this->seedTenantActors();

        $this->ownerSection = $this->makeSection($this->ownerCourse);

        $this->note = $this->makeNote($this->ownerSection, false);
        $this->hiddenNote = $this->makeNote($this->ownerSection, true);

        // The leak target: otherTeacher's OWN note, with its OWN document.
        $this->makeNote($this->makeSection($this->otherCourse), false);
    }

    protected function tearDown(): void
    {
        $this->pruneUploadsAddedSince($this->inventoryBefore);
        $this->forgetUploadedFiles();

        parent::tearDown();
    }

    /**
     * ┌── THE MATRIX. ──────────────────────────────────────────────────────┐
     * │ admin                200  Gate::before, before the policy runs.     │
     * │ ownerTeacher         200  ownsCourse().                             │
     * │ otherTeacher         403  owns an equivalent note, not THIS one.    │
     * │ subscribedStudent    200  the paying customer.                      │
     * │ unsubscribedStudent  403  the paywall.                              │
     * │ expiredSubscriber    403  status='active' but expires_at is past —  │
     * │                           only the date betrays the row.            │
     * │ cancelledSubscriber  403  status='cancelled' with a FUTURE expiry — │
     * │                           the scopeActive() parenthesisation guard. │
     * │ guest                401  the auth gate, before any policy.         │
     * │ deactivatedOwner     401  EnsureUserIsActive — and the GATE must    │
     * │                           still answer exactly as the owner does,   │
     * │                           because is_active is an AUTHENTICATION    │
     * │                           fact a policy must never read.            │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_the_full_matrix_on_a_visible_note(): void
    {
        $this->assertIsolated(
            'downloadFile',
            $this->note,
            [
                'admin' => true,
                'ownerTeacher' => true,
                'otherTeacher' => false,
                'subscribedStudent' => true,
                'unsubscribedStudent' => false,
                'expiredSubscriber' => false,
                'cancelledSubscriber' => false,
                'guest' => [false, 401],
                'deactivatedOwner' => self::REJECTED_AT_AUTH_GATE,
            ],
            'get',
            "/api/v1/content-items/{$this->note->getKey()}/file",
        );
    }

    /**
     * THE HIDDEN VARIANT, AND THE ONE ROW THAT CHANGES IS THE INTERESTING
     * ONE: the SUBSCRIBED STUDENT drops from 200 to **404** — not 403.
     *
     * They still pass consumeContent on this very course. `viewHidden` is a
     * strictly narrower ability (owner and admin only), and the refusal is a
     * 404 so a guessed UUID cannot confirm that an unreleased lesson exists.
     */
    public function test_the_full_matrix_on_a_hidden_note(): void
    {
        $this->assertIsolated(
            'downloadFile',
            $this->hiddenNote,
            [
                'admin' => true,
                'ownerTeacher' => true,
                'otherTeacher' => 404,
                'subscribedStudent' => 404,
                'unsubscribedStudent' => 404,
                'expiredSubscriber' => 404,
                'cancelledSubscriber' => 404,
                'guest' => [false, 401],
                'deactivatedOwner' => self::REJECTED_AT_AUTH_GATE,
            ],
            'get',
            "/api/v1/content-items/{$this->hiddenNote->getKey()}/file",
        );
    }

    /**
     * The wrong-type refusal is a 404 for EVERY actor who reaches the policy,
     * including the owner — it is a statement about the resource, not about
     * the caller.
     */
    public function test_the_full_matrix_on_a_video_item_is_404_for_everyone_who_reaches_the_policy(): void
    {
        $video = $this->makeContentItem($this->ownerSection, ['type' => ContentItemType::Video]);

        $this->assertIsolated(
            'downloadFile',
            $video,
            [
                // The admin never enters the body, so the Gate still says true
                // — and the controller's own existence rungs answer the 404.
                'admin' => [true, 404],
                'ownerTeacher' => 404,
                'otherTeacher' => 404,
                'subscribedStudent' => 404,
                'unsubscribedStudent' => 404,
                'expiredSubscriber' => 404,
                'cancelledSubscriber' => 404,
                'guest' => [false, 401],
                'deactivatedOwner' => [false, 401],
            ],
            'get',
            "/api/v1/content-items/{$video->getKey()}/file",
        );
    }

    // ══ THE SHAPE OF THE ANSWER, NOT JUST ITS TRUTH VALUE ═════════════════

    /**
     * A status matrix cannot tell a 404-carrying denial from an ordinary one.
     * These assertions read the Response object itself, which is where the
     * difference between "403 Forbidden" and "404 File not found" lives.
     */
    public function test_the_denial_responses_carry_their_intended_status_and_message(): void
    {
        $student = $this->actor('unsubscribedStudent');

        $unentitled = Gate::forUser($student)->inspect('downloadFile', $this->note);

        $this->assertTrue($unentitled->denied());
        $this->assertNull(
            $unentitled->status(),
            'An ordinary refusal carries NO status — it must surface as the default 403.'
        );
        $this->assertSame(ContentItemPolicy::NO_ACCESS_TO_FILE, $unentitled->message());

        $hidden = Gate::forUser($this->actor('subscribedStudent'))
            ->inspect('downloadFile', $this->hiddenNote);

        $this->assertTrue($hidden->denied());
        $this->assertSame(
            404,
            $hidden->status(),
            'A hidden item must refuse AS NOT FOUND. A 403 here confirms to anyone with a guessed '
            . "UUID that the id names a real, unreleased lesson — the enumeration oracle this 404 closes."
        );
        $this->assertSame(ContentItemPolicy::FILE_NOT_FOUND, $hidden->message());

        $allowed = Gate::forUser($this->actor('ownerTeacher'))->inspect('downloadFile', $this->note);

        $this->assertTrue($allowed->allowed());
        $this->assertInstanceOf(AccessResponse::class, $allowed);
    }

    /**
     * A file attached to a note must never be EASIER to reach than the note
     * itself. Asserted as an equality between two abilities across all nine
     * actors, rather than by re-listing expectations — a drift between them
     * is exactly the bug, and re-listing would hide it behind two lists that
     * were edited separately.
     */
    public function test_downloading_is_never_easier_than_consuming_the_course(): void
    {
        foreach (self::ACTORS as $name) {
            $actor = $this->actor($name);

            $mayConsume = Gate::forUser($actor)->allows('consumeContent', $this->ownerCourse);
            $mayDownload = Gate::forUser($actor)->allows('downloadFile', $this->note);

            if ($mayDownload) {
                $this->assertTrue(
                    $mayConsume || $actor?->role?->value === 'admin',
                    "Actor '{$name}' may download the attachment of a note they may not consume. "
                    . 'The file gate MUST be the same consumeContent gate as the note text.'
                );
            }
        }
    }

    /** …and the tenant boundary itself, stated once, plainly. */
    public function test_the_other_teacher_cannot_reach_this_tenants_document(): void
    {
        $this->loginAs($this->otherTeacher);

        $this->getJson("/api/v1/content-items/{$this->note->getKey()}/file")
            ->assertStatus(403)
            ->assertJsonPath('message', ContentItemPolicy::NO_ACCESS_TO_FILE);
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function makeNote(Section $section, bool $hidden): ContentItem
    {
        $reference = $this->plantUploadedFile(
            'documents',
            Str::uuid()->toString() . '.pdf',
            $this->pdfBytes()
        );

        return $this->makeContentItemWithDetail(
            $section,
            ContentItemType::Note,
            ['file_path' => $reference, 'file_name' => 'lecture.pdf'],
            ['is_hidden' => $hidden],
        );
    }
}
