<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Enums\ContentItemType;
use App\Enums\SubscriptionStatus;
use App\Models\Teacher;
use App\Support\StoredUploadPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\Concerns\CreatesUploadFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE FILES FAMILY IS THE ONLY STAGE WHOSE OUTPUT IS ANOTHER STAGE'S      ║
 * ║ INPUT: POST /uploads RETURNS A STRING, AND THREE OTHER SURFACES WRITE   ║
 * ║ THAT STRING INTO A DATABASE COLUMN.                                     ║
 * ║                                                                          ║
 * ║ So the `path` format is not a response field, it is a DATA CONTRACT —   ║
 * ║ and testing each endpoint in isolation proves nothing about whether the ║
 * ║ handoff works. These tests run the real chains end to end.              ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 *   Stage 5  upload document -> PUT /content-items/{id} -> GET .../file
 *   Stage 4  upload avatar   -> teachers.avatar_url     -> GET /files/avatars
 */
final class CrossStageFileIntegrationTest extends TestCase
{
    use CreatesAuthFixtures;
    use CreatesUploadFixtures;
    use RefreshDatabase;

    /** @var list<string> */
    private array $inventoryBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();
        $this->inventoryBefore = $this->currentUploadInventory();
    }

    protected function tearDown(): void
    {
        $this->pruneUploadsAddedSince($this->inventoryBefore);
        $this->forgetUploadedFiles();

        parent::tearDown();
    }

    // ══ STAGE 5: UPLOAD -> ATTACH TO A NOTE -> DOWNLOAD ═══════════════════

    /**
     * The whole chain, with NO hand-written path anywhere: the string the
     * upload endpoint returned is the string the content-item endpoint stored
     * and the string the download endpoint resolved. If `path`'s format ever
     * drifts, this is what breaks — and it breaks loudly, at the byte compare.
     */
    public function test_an_uploaded_document_attaches_to_a_note_and_downloads_byte_for_byte(): void
    {
        $owner = $this->makeInstructor();
        $course = $this->makeCourseFor($owner, ['is_published' => true]);
        $section = $this->makeSection($course);

        $item = $this->makeContentItemWithDetail(
            $section,
            ContentItemType::Note,
            ['content_text' => 'the lecture notes']
        );

        $this->loginAs($owner);

        // ── 1. upload
        $bytes = $this->pdfBytes() . str_repeat('unique-marker-', 100);

        $upload = $this->postJson('/api/v1/uploads', [
            'type' => 'document',
            'file' => $this->uploadOf($bytes, 'الملخص النهائي.pdf'),
        ]);

        $upload->assertStatus(201);

        $path = (string) $upload->json('data.file.path');
        $name = (string) $upload->json('data.file.file_name');
        $this->rememberUploadedPath($path);

        // ── 2. attach, through the REAL Stage 5 write path
        $this->startNextRequest();

        // Detail fields are TOP-LEVEL on this endpoint, not nested — the type
        // is read from the STORED row, never from the payload.
        $this->putJson("/api/v1/content-items/{$item->getKey()}", [
            'title' => $item->getAttribute('title'),
            'file_path' => $path,
            'file_name' => $name,
        ])->assertStatus(200);

        $this->assertSame(
            $path,
            DB::table('notes')->where('content_item_id', $item->getKey())->value('file_path'),
            'The column stores the upload response verbatim — no rewriting, no normalising.'
        );

        // ── 3. download
        $this->startNextRequest();

        $download = $this->get("/api/v1/content-items/{$item->getKey()}/file");

        $download->assertStatus(200);

        $this->assertSame(
            $bytes,
            $download->streamedContent(),
            'The bytes that came out are the bytes that went in.'
        );

        $this->assertStringStartsWith(
            'attachment; filename="',
            (string) $download->headers->get('Content-Disposition')
        );
    }

    /** …and a subscribed student gets the same bytes through the same chain. */
    public function test_a_subscribed_student_downloads_the_attached_document(): void
    {
        $owner = $this->makeInstructor();
        $course = $this->makeCourseFor($owner, ['is_published' => true]);
        $section = $this->makeSection($course);

        $this->loginAs($owner);

        $upload = $this->postJson('/api/v1/uploads', [
            'type' => 'document',
            'file' => $this->uploadOf($this->pdfBytes(), 'notes.pdf'),
        ]);

        $path = (string) $upload->json('data.file.path');
        $this->rememberUploadedPath($path);

        $item = $this->makeContentItemWithDetail(
            $section,
            ContentItemType::Note,
            ['file_path' => $path, 'file_name' => 'notes.pdf']
        );

        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $course, SubscriptionStatus::Active, null);

        $this->startNextRequest();
        $this->loginAs($student);

        $this->get("/api/v1/content-items/{$item->getKey()}/file")
            ->assertStatus(200);
    }

    // ══ STAGE 4: UPLOAD AN AVATAR -> SERVE IT PUBLICLY ════════════════════

    /**
     * The public half of the same contract: the `url` the upload returned is
     * the URL that serves the file, with no rewriting by the caller.
     */
    public function test_an_uploaded_avatar_is_served_at_the_url_the_upload_returned(): void
    {
        [$teacherUser] = $this->makeTeacherWithProfile();

        $this->loginAs($teacherUser);

        $bytes = $this->pngBytes();

        $upload = $this->postJson('/api/v1/uploads', [
            'type' => 'avatar',
            'file' => $this->uploadOf($bytes, 'me.png'),
        ]);

        $upload->assertStatus(201);

        $url = (string) $upload->json('data.file.url');
        $path = (string) $upload->json('data.file.path');
        $this->rememberUploadedPath($path);

        $this->assertStringStartsWith('/api/v1/files/avatars/', $url);

        // Served WITHOUT a session — this is the logged-out storefront path.
        $this->flushSession();
        $this->startNextRequest();

        $served = $this->get($url);

        $served->assertStatus(200);
        $this->assertSame($bytes, $served->streamedContent());
    }

    /**
     * ┌── THE DOCUMENTED LEAK, VERIFIED AS **STILL PRESENT**. ──────────────┐
     * │ Stage 4 made `avatarUrl: ''` CLEAR the column. There is no deletion │
     * │ or garbage collection anywhere in the system, so the bytes stay on  │
     * │ disk forever — orphaned, unreferenced and still fetchable by anyone │
     * │ who kept the URL.                                                    │
     * │                                                                      │
     * │ This test asserts the leak IS STILL THERE, which reads oddly and is │
     * │ deliberate: open decision 9.2 records it as accepted-for-now, and a │
     * │ stage that silently "fixed" it would be shipping an unreviewed      │
     * │ deletion path on a shared storage root. When 9.2 is finally         │
     * │ addressed, THIS is the test that must be rewritten — which is       │
     * │ exactly the review conversation it exists to force.                  │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_clearing_an_avatar_nulls_the_column_and_leaves_the_file_on_disk(): void
    {
        [$teacherUser] = $this->makeTeacherWithProfile();

        $this->loginAsAdmin();

        $upload = $this->postJson('/api/v1/uploads', [
            'type' => 'avatar',
            'file' => $this->uploadOf($this->pngBytes(), 'me.png'),
        ]);

        $upload->assertStatus(201);

        $url = (string) $upload->json('data.file.url');
        $path = (string) $upload->json('data.file.path');
        $absolute = (string) StoredUploadPath::resolve($path);
        $this->rememberUploadedPath($path);

        // `avatar_url` lives on `users`, not `teachers` — the teacher row carries
        // banner_url and profile_photo_url, the THREE images being deliberately
        // separate columns on two tables.
        $teacherId = (string) $teacherUser->getKey();

        DB::table('users')->where('id', $teacherId)->update(['avatar_url' => $url]);

        $this->startNextRequest();

        // The Stage 4 clear: '' means "remove it".
        $this->patchJson("/api/v1/admin/teachers/{$teacherId}", ['avatarUrl' => ''])
            ->assertStatus(200);

        /*
         * MEASURED, NOT ASSUMED: Stage 4 writes the EMPTY STRING rather than
         * NULL. Both read as "no avatar" to every consumer, and the column is
         * not distinguished anywhere — but pinning the actual value here means
         * a later change to null is a visible diff rather than a surprise for
         * whoever writes the eventual cleanup job (open decision 9.2), which
         * will have to know which of the two states it is looking for.
         */
        $this->assertSame(
            '',
            DB::table('users')->where('id', $teacherId)->value('avatar_url'),
            "Stage 4's '' clear must still work."
        );

        $this->assertFileExists(
            $absolute,
            'DOCUMENTED LEAK (open decision 9.2): nothing deletes uploaded files, ever. If this '
            . 'assertion starts failing, a garbage-collection path was added — update decision 9.2 '
            . 'and rewrite this test rather than deleting it.'
        );

        $this->startNextRequest();

        $this->get($url)->assertStatus(200);
    }
}
