<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Enums\ContentItemType;
use App\Models\ContentItem;
use App\Models\Section;
use App\Models\User;
use App\Policies\ContentItemPolicy;
use App\Support\FileStream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\Concerns\CreatesUploadFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/content-items/{id}/file — freeze-table row 73.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ROUTE THAT CLOSED OPEN DECISION 8.4. Its ability had a              ║
 * ║ LogicException body from Stage 5 to Stage 8 precisely because nothing   ║
 * ║ routed to it; a body without a route would still have been dead code,   ║
 * ║ which is why this file and the policy landed together.                  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * The nine-actor matrix lives next door in ContentItemDownloadIsolationTest.
 * THIS file covers what a status matrix cannot see: the exact refusal
 * STRINGS, the existence rungs that are not authorization at all, the headers,
 * and the audit-log entry.
 */
final class ContentItemDownloadTest extends TestCase
{
    use CreatesAuthFixtures;
    use CreatesUploadFixtures;
    use RefreshDatabase;

    /** @var list<string> */
    private array $inventoryBefore = [];

    private User $owner;

    private Section $section;

    private ContentItem $note;

    private string $reference;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();
        $this->inventoryBefore = $this->currentUploadInventory();

        $this->owner = $this->makeInstructor();
        $course = $this->makeCourseFor($this->owner, ['is_published' => true]);
        $this->section = $this->makeSection($course);

        $this->reference = $this->plantUploadedFile(
            'documents',
            Str::uuid()->toString() . '.pdf',
            $this->pdfBytes()
        );

        $this->note = $this->makeNoteWithFile($this->reference, 'محاضرة الأسبوع الأول.pdf');
    }

    protected function tearDown(): void
    {
        $this->pruneUploadsAddedSince($this->inventoryBefore);
        $this->forgetUploadedFiles();

        parent::tearDown();
    }

    // ══ THE GRANTS ════════════════════════════════════════════════════════

    public function test_the_owning_instructor_downloads_the_exact_bytes(): void
    {
        $this->loginAs($this->owner);

        $response = $this->get("/api/v1/content-items/{$this->note->getKey()}/file");

        $response->assertStatus(200);

        $this->assertSame(
            $this->pdfBytes(),
            $response->streamedContent(),
            'The body must be the file on disk, byte for byte.'
        );
    }

    public function test_a_subscribed_student_downloads_it(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->note->course, \App\Enums\SubscriptionStatus::Active, null);

        $this->loginAs($student);

        $this->get("/api/v1/content-items/{$this->note->getKey()}/file")->assertStatus(200);
    }

    public function test_an_admin_downloads_it_through_the_gate_before_bypass(): void
    {
        $this->loginAs($this->makeAdmin());

        $this->get("/api/v1/content-items/{$this->note->getKey()}/file")->assertStatus(200);
    }

    // ══ THE REFUSALS, WITH THEIR EXACT STRINGS ════════════════════════════

    public function test_an_unsubscribed_student_gets_403_with_the_contract_message(): void
    {
        $this->loginAs($this->makeStudentUser());

        $this->getJson("/api/v1/content-items/{$this->note->getKey()}/file")
            ->assertStatus(403)
            ->assertJsonPath('message', ContentItemPolicy::NO_ACCESS_TO_FILE)
            ->assertJsonPath('message', 'You do not have access to this file');
    }

    public function test_another_teacher_gets_403(): void
    {
        $this->loginAs($this->makeInstructor());

        $this->getJson("/api/v1/content-items/{$this->note->getKey()}/file")
            ->assertStatus(403)
            ->assertJsonPath('message', ContentItemPolicy::NO_ACCESS_TO_FILE);
    }

    public function test_a_guest_gets_401_not_403(): void
    {
        $this->getJson("/api/v1/content-items/{$this->note->getKey()}/file")
            ->assertStatus(401);
    }

    /**
     * ┌── HIDDEN IS A **404**, NOT A 403, AND FOR A SUBSCRIBER TOO. ────────┐
     * │ A 403 on a guessed UUID confirms that the id names a real,          │
     * │ unreleased lesson — repeated, that enumerates an instructor's whole │
     * │ unpublished pipeline without ever seeing a byte of it. A 404 is     │
     * │ indistinguishable from a wrong guess.                                │
     * │                                                                      │
     * │ The subscribed student is the interesting actor: they pass          │
     * │ consumeContent on this very course and STILL get 404 here. That gap │
     * │ between "may consume" and "may see hidden" is the most-repeated     │
     * │ caution in the whole rewrite.                                       │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_hidden_note_is_404_for_a_subscribed_student_and_200_for_its_owner(): void
    {
        $this->note->forceFill(['is_hidden' => true])->save();

        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->note->course, \App\Enums\SubscriptionStatus::Active, null);

        $this->loginAs($student);
        $this->getJson("/api/v1/content-items/{$this->note->getKey()}/file")
            ->assertStatus(404)
            ->assertJsonPath('message', ContentItemPolicy::FILE_NOT_FOUND);

        $this->startNextRequest();
        $this->loginAs($this->owner);
        $this->get("/api/v1/content-items/{$this->note->getKey()}/file")->assertStatus(200);
    }

    /** …and the same for an admin, via Gate::before. */
    public function test_a_hidden_note_is_downloadable_by_an_admin(): void
    {
        $this->note->forceFill(['is_hidden' => true])->save();

        $this->loginAs($this->makeAdmin());

        $this->get("/api/v1/content-items/{$this->note->getKey()}/file")->assertStatus(200);
    }

    // ══ THE TYPE GATE ═════════════════════════════════════════════════════

    /**
     * Only `note` is downloadable. `file` is included on purpose: decision 5.x
     * records that `content_items.type = 'file'` has NO WRITE PATH anywhere in
     * the system, so such a row cannot exist — and if one ever did it would be
     * permanently undownloadable, which is the combined open decision 9.5.
     */
    public function test_every_non_note_type_is_404(): void
    {
        foreach (ContentItemType::cases() as $type) {
            if ($type === ContentItemType::Note) {
                continue;
            }

            $item = $this->makeContentItem($this->section, ['type' => $type]);

            $this->loginAs($this->owner);

            $this->getJson("/api/v1/content-items/{$item->getKey()}/file")
                ->assertStatus(404)
                ->assertJsonPath('message', ContentItemPolicy::FILE_NOT_FOUND);

            $this->startNextRequest();
        }
    }

    // ══ THE EXISTENCE RUNGS (NOT AUTHORIZATION) ═══════════════════════════

    /**
     * ┌── A NOTE WITH NO ATTACHMENT IS 404 FOR **EVERYONE INCLUDING ITS ────┐
     * │ OWNER**, WHICH IS WHY THE CHECK IS NOT IN THE POLICY.               │
     * │ An answer that does not depend on who is asking is an existence     │
     * │ fact, not a permission one. Keeping it in the controller is what    │
     * │ lets the isolation matrix assert on downloadFile without seeding a  │
     * │ single byte on disk.                                                 │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_note_with_no_file_path_is_404_for_the_owner_and_the_admin_alike(): void
    {
        $textOnly = $this->makeContentItemWithDetail(
            $this->section,
            ContentItemType::Note,
            ['content_text' => 'inline only, no attachment']
        );

        foreach ([$this->owner, $this->makeAdmin()] as $actor) {
            $this->loginAs($actor);

            $this->getJson("/api/v1/content-items/{$textOnly->getKey()}/file")
                ->assertStatus(404)
                ->assertJsonPath('message', ContentItemPolicy::FILE_NOT_FOUND);

            $this->startNextRequest();
        }
    }

    public function test_a_note_with_no_notes_row_at_all_is_404(): void
    {
        // PersistNoteDetail no-ops when neither content_text nor file_path is
        // supplied, so this item legitimately has NO detail row.
        $bare = $this->makeContentItem($this->section, ['type' => ContentItemType::Note]);

        $this->loginAs($this->owner);

        $this->getJson("/api/v1/content-items/{$bare->getKey()}/file")->assertStatus(404);
    }

    public function test_a_blank_or_whitespace_file_path_is_404(): void
    {
        foreach (['', '   '] as $blank) {
            $item = $this->makeNoteWithFile($this->reference, 'x.pdf');
            $item->note->forceFill(['file_path' => $blank])->save();

            $this->loginAs($this->owner);
            $this->getJson("/api/v1/content-items/{$item->getKey()}/file")->assertStatus(404);
            $this->startNextRequest();
        }
    }

    /**
     * The stored path is RE-VALIDATED, not trusted: a value written by an
     * older, laxer code path must not become a read primitive.
     */
    public function test_a_stored_path_that_does_not_resolve_is_404(): void
    {
        $hostile = [
            'uploads/documents/../../../../etc/passwd',
            'uploads/documents/not-a-uuid.pdf',
            'uploads/documents/' . Str::uuid()->toString() . '.pdf',
            'uploads/documents/' . Str::uuid()->toString() . '.png',
            'uploads/nowhere/' . Str::uuid()->toString() . '.pdf',
            '/etc/passwd',
            'uploads/documents',
        ];

        foreach ($hostile as $path) {
            $item = $this->makeNoteWithFile($path, 'x.pdf');

            $this->loginAs($this->owner);
            $this->getJson("/api/v1/content-items/{$item->getKey()}/file")
                ->assertStatus(404)
                ->assertJsonPath('message', ContentItemPolicy::FILE_NOT_FOUND);
            $this->startNextRequest();
        }
    }

    public function test_a_nonexistent_content_item_is_404(): void
    {
        $this->loginAs($this->owner);

        $this->getJson('/api/v1/content-items/' . Str::uuid()->toString() . '/file')
            ->assertStatus(404);
    }

    // ══ HEADERS ═══════════════════════════════════════════════════════════

    public function test_a_private_download_is_always_an_attachment_and_never_cached(): void
    {
        $this->loginAs($this->owner);

        $response = $this->get("/api/v1/content-items/{$this->note->getKey()}/file");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->assertHeader('X-Frame-Options', 'DENY');

        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringStartsWith(
            'attachment;',
            $disposition,
            'ALWAYS attachment: even a PDF can carry active content in some viewers, and forcing a '
            . 'download means the browser never executes anything in this origin.'
        );

        $directives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        sort($directives);

        $this->assertSame(
            ['no-store', 'private'],
            array_values(array_filter($directives)),
            'A shared proxy caching an entitlement-gated file would serve it to the next requester '
            . 'without the gate, turning the subscription check into a one-time formality.'
        );
    }

    /**
     * The download name is the note's ORIGINAL, user-supplied file_name, so it
     * is hostile input. An Arabic name must become underscores rather than
     * crash or produce an empty header.
     */
    public function test_an_arabic_filename_is_sanitised_to_ascii(): void
    {
        $this->loginAs($this->owner);

        $response = $this->get("/api/v1/content-items/{$this->note->getKey()}/file");

        $disposition = (string) $response->headers->get('Content-Disposition');

        /*
         * The replacement is BYTE-WISE, so each Arabic code point (two UTF-8
         * bytes) becomes TWO underscores. Asserted by construction rather than
         * with a hand-counted literal, because a hand-counted literal is a
         * puzzle for the next reader and tells them nothing about the rule.
         */
        $arabic = 'محاضرة الأسبوع الأول';
        $expected = str_repeat('_', strlen($arabic)) . '.pdf';

        $this->assertSame('attachment; filename="' . $expected . '"', $disposition);
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $disposition, 'ASCII only.');
        $this->assertNotSame('attachment; filename=""', $disposition, 'Never an empty header.');
    }

    /**
     * ┌── HEADER INJECTION VIA THE FILENAME. ───────────────────────────────┐
     * │ CR/LF in a filename would split the response into forged headers,   │
     * │ and a quote would close the quoted parameter. The defence is an     │
     * │ ALLOW-LIST, so it does not have to enumerate the dangerous          │
     * │ characters — everything outside [A-Za-z0-9._-] becomes '_'.         │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_newline_injecting_filename_cannot_corrupt_the_header(): void
    {
        $item = $this->makeNoteWithFile(
            $this->reference,
            "ok.pdf\r\nX-Injected: yes\r\n\r\n<html>pwned</html>"
        );

        $this->loginAs($this->owner);

        $response = $this->get("/api/v1/content-items/{$item->getKey()}/file");

        $response->assertStatus(200);

        $this->assertNull($response->headers->get('X-Injected'), 'A forged header was accepted.');

        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertStringNotContainsString('"', substr($disposition, strlen('attachment; filename="'), -1));
        $this->assertStringNotContainsString('pwned</html>', $disposition);
    }

    public function test_the_ascii_filename_sanitiser_covers_its_documented_cases(): void
    {
        $this->assertSame('download', FileStream::asciiFilename(''));
        $this->assertSame('download', FileStream::asciiFilename('///'));
        $this->assertSame('passwd', FileStream::asciiFilename('../../etc/passwd'));
        $this->assertSame('passwd', FileStream::asciiFilename('..\\..\\etc\\passwd'));
        $this->assertSame('a_b.pdf', FileStream::asciiFilename('a"b.pdf'));
        $this->assertSame('a_b.pdf', FileStream::asciiFilename("a\nb.pdf"));
        // Byte-wise: three Arabic code points are six UTF-8 bytes.
        $this->assertSame('______.pdf', FileStream::asciiFilename('ملف.pdf'));
        $this->assertSame(100, strlen(FileStream::asciiFilename(str_repeat('a', 500))));
        $this->assertSame('safe-name_1.PDF', FileStream::asciiFilename('safe-name_1.PDF'));
    }

    // ══ BODY SHAPE ════════════════════════════════════════════════════════

    public function test_the_download_body_is_raw_bytes_and_the_refusals_are_enveloped(): void
    {
        $this->loginAs($this->owner);

        $granted = $this->get("/api/v1/content-items/{$this->note->getKey()}/file");

        $this->assertSame($this->pdfBytes(), $granted->streamedContent());
        $this->assertNull(json_decode($granted->streamedContent(), true));

        $this->startNextRequest();
        $this->loginAs($this->makeStudentUser());

        $this->getJson("/api/v1/content-items/{$this->note->getKey()}/file")
            ->assertStatus(403)
            ->assertJsonStructure(['success', 'message', 'data']);
    }

    // ══ THE AUDIT TRAIL ═══════════════════════════════════════════════════

    /**
     * Refusals are logged by the resolver and by the exception handler.
     * Without a GRANT entry the log would record only the attempts that
     * FAILED, and "who downloaded the revision notes" would be unanswerable.
     */
    public function test_every_grant_writes_a_security_log_entry(): void
    {
        $captured = [];

        Log::channel('security')->listen(static function ($message) use (&$captured): void {
            $captured[] = $message;
        });

        $this->loginAs($this->owner);
        $this->get("/api/v1/content-items/{$this->note->getKey()}/file")->assertStatus(200);

        $grants = array_values(array_filter(
            $captured,
            static fn ($m): bool => ($m->context['event'] ?? null) === 'file.granted'
        ));

        $this->assertCount(1, $grants, 'Exactly one grant entry per download.');
        $this->assertSame($this->note->getKey(), $grants[0]->context['content_item_id']);
        $this->assertSame('documents', $grants[0]->context['category']);
        $this->assertSame(basename($this->reference), $grants[0]->context['stored_name']);

        /*
         * `path` IS present — but it is SecurityLog::requestContext()'s
         * REQUEST path, which is public information the caller supplied. What
         * must never appear is the absolute SERVER path, and the difference
         * is asserted rather than assumed: a naive `'path' => $file['path']`
         * in the controller would put the storage root in every log line and
         * pass an assertArrayNotHasKey that only checked for the key.
         */
        $this->assertSame(
            "/api/v1/content-items/{$this->note->getKey()}/file",
            $grants[0]->context['path']
        );

        $serialised = json_encode($grants[0]->context);

        $this->assertStringNotContainsString(
            (string) config('upload.storage_path'),
            (string) $serialised,
            'The absolute server path must never reach the log.'
        );
        $this->assertStringNotContainsString('storage', (string) $serialised);
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function makeNoteWithFile(string $storedPath, string $fileName): ContentItem
    {
        return $this->makeContentItemWithDetail(
            $this->section,
            ContentItemType::Note,
            ['file_path' => $storedPath, 'file_name' => $fileName]
        );
    }
}
