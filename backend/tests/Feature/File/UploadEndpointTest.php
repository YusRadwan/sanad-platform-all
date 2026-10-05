<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Actions\Upload\StoreUpload;
use App\Enums\UploadKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\Concerns\CreatesUploadFixtures;
use Tests\TestCase;

/**
 * POST /api/v1/uploads — freeze-table row 71.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ EVERY FIXTURE HERE IS **REAL BYTES**. See Tests\Concerns\               ║
 * ║ CreatesUploadFixtures for why UploadedFile::fake() is unusable for this ║
 * ║ endpoint: it cannot make the DECLARED EXTENSION and the SNIFFED CONTENT ║
 * ║ disagree, which is the only interesting thing about this validator.     ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * The two adversarial cases the whole design exists for:
 *
 *   a genuine PNG named `shell.php`  -> extension_not_allowed, NOTHING WRITTEN
 *   a PHP webshell named `photo.jpg` -> mime_extension_mismatch, NOTHING WRITTEN
 *
 * Both answer the SAME body. Asserting that they do is not pedantry — a
 * different message for each is a free oracle for mapping the allow-list.
 */
final class UploadEndpointTest extends TestCase
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
        // Nothing this suite writes may survive into the next run: a leftover
        // uuid.png would quietly satisfy a later `not_found` assertion.
        $this->pruneUploadsAddedSince($this->inventoryBefore);
        $this->forgetUploadedFiles();

        parent::tearDown();
    }

    // ══ THE HAPPY PATH, PER KIND ══════════════════════════════════════════

    public function test_every_public_image_kind_accepts_its_four_formats(): void
    {
        $this->loginAs($this->makeInstructor());

        $bytes = [
            'jpg' => $this->jpegBytes(),
            'jpeg' => $this->jpegBytes(),
            'png' => $this->pngBytes(),
            'webp' => $this->webpBytes(),
        ];

        foreach ([UploadKind::Avatar, UploadKind::Banner, UploadKind::Image, UploadKind::Profile] as $kind) {
            foreach ($bytes as $extension => $content) {
                $response = $this->postJson('/api/v1/uploads', [
                    'type' => $kind->value,
                    'file' => $this->uploadOf($content, "picture.{$extension}"),
                ]);

                $response->assertStatus(201);

                $file = $response->json('data.file');

                $this->assertSame(
                    'uploads/' . $kind->directory() . '/' . $file['stored_name'],
                    $file['path'],
                    'The `path` format is a DATA CONTRACT: notes.file_path stores exactly this string.'
                );

                $this->assertSame(
                    '/api/v1/files/' . $kind->directory() . '/'
                        . pathinfo((string) $file['stored_name'], PATHINFO_FILENAME),
                    $file['url'],
                    'A public URL is extensionless — a URL ending in .png is claimed by the static-file '
                    . 'handler before PHP ever sees it.'
                );

                $this->assertFileExists($this->uploadsPath($kind->directory() . '/' . $file['stored_name']));
                $this->rememberUploadedPath($file['path']);
            }
        }
    }

    public function test_the_response_carries_the_full_documented_shape(): void
    {
        $this->loginAs($this->makeInstructor());

        $response = $this->postJson('/api/v1/uploads', [
            'type' => 'avatar',
            'file' => $this->uploadOf($this->pngBytes(), 'me.png'),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم رفع الملف بنجاح')
            ->assertJsonPath('data.file.file_name', 'me.png')
            ->assertJsonPath('data.file.mime', 'image/png')
            ->assertJsonPath('data.file.size', strlen($this->pngBytes()));

        $this->assertSame(
            ['file_name', 'mime', 'path', 'size', 'stored_name', 'url'],
            $this->sortedKeys($response->json('data.file')),
            'The response shape is consumed by five frontend call sites; a new or missing key is a break.'
        );

        $this->rememberUploadedPath((string) $response->json('data.file.path'));
    }

    public function test_a_document_upload_gets_a_null_url_and_the_documents_directory(): void
    {
        $this->loginAs($this->makeInstructor());

        $response = $this->postJson('/api/v1/uploads', [
            'type' => 'document',
            'file' => $this->uploadOf($this->pdfBytes(), 'lecture.pdf'),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.file.url', null)
            ->assertJsonPath('data.file.mime', 'application/pdf');

        $this->assertStringStartsWith('uploads/documents/', (string) $response->json('data.file.path'));

        $this->rememberUploadedPath((string) $response->json('data.file.path'));
    }

    public function test_ooxml_documents_are_accepted_however_they_sniff(): void
    {
        $this->loginAs($this->makeInstructor());

        foreach (['docx' => $this->docxBytes(), 'pptx' => $this->pptxBytes()] as $extension => $content) {
            $response = $this->postJson('/api/v1/uploads', [
                'type' => 'document',
                'file' => $this->uploadOf($content, "slides.{$extension}"),
            ]);

            $response->assertStatus(201);

            $this->assertContains(
                $response->json('data.file.mime'),
                UploadKind::Document->extensionMimeMap()[$extension],
                'An OOXML package sniffs as its precise type on some builds and application/zip on '
                . 'others; both are allowed for these two extensions AND FOR NO OTHERS.'
            );

            $this->rememberUploadedPath((string) $response->json('data.file.path'));
        }
    }

    // ══ THE TWO ADVERSARIAL CASES ═════════════════════════════════════════

    public function test_a_real_image_renamed_to_php_is_rejected_and_nothing_is_written(): void
    {
        $this->loginAs($this->makeInstructor());

        $response = $this->postJson('/api/v1/uploads', [
            'type' => 'image',
            'file' => $this->uploadOf($this->pngBytes(), 'shell.php'),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('data.errors.file.0', StoreUpload::UNSUPPORTED_FORMAT);

        $this->assertSame(
            $this->inventoryBefore,
            $this->currentUploadInventory(),
            'The bytes are a legitimate PNG, so only the EXTENSION half can have caught this — and it '
            . 'must catch it BEFORE anything reaches the filesystem. A file named .php on disk is a '
            . 'webshell on any deployment whose document root is one directory too high.'
        );
    }

    public function test_a_php_script_renamed_to_jpg_is_rejected_and_nothing_is_written(): void
    {
        $this->loginAs($this->makeInstructor());

        $response = $this->postJson('/api/v1/uploads', [
            'type' => 'image',
            'file' => $this->uploadOf($this->phpScriptBytes(), 'photo.jpg'),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('data.errors.file.0', StoreUpload::UNSUPPORTED_FORMAT);

        $this->assertSame($this->inventoryBefore, $this->currentUploadInventory());
    }

    /**
     * THE INFORMATION-ASYMMETRY CONTRACT: two different internal reasons, one
     * client-visible answer. Deliberate in backend/ and preserved here.
     */
    public function test_both_validation_halves_answer_with_the_same_indistinguishable_body(): void
    {
        $this->loginAs($this->makeInstructor());

        $extensionHalf = $this->postJson('/api/v1/uploads', [
            'type' => 'image',
            'file' => $this->uploadOf($this->pngBytes(), 'shell.php'),
        ]);

        $mimeHalf = $this->postJson('/api/v1/uploads', [
            'type' => 'image',
            'file' => $this->uploadOf($this->phpScriptBytes(), 'photo.jpg'),
        ]);

        $this->assertSame(
            $extensionHalf->json(),
            $mimeHalf->json(),
            'Telling an uploader WHICH half rejected them is exactly the signal needed to search for a '
            . 'pair that satisfies both. The distinction goes to the security log, not the wire.'
        );
    }

    // ══ SVG, THE FLATLY BANNED FORMAT ═════════════════════════════════════

    public function test_an_svg_is_rejected_for_every_single_kind(): void
    {
        $this->loginAs($this->makeInstructor());

        foreach (UploadKind::cases() as $kind) {
            $this->assertArrayNotHasKey(
                'svg',
                $kind->extensionMimeMap(),
                "UploadKind::{$kind->name} must not allow an svg extension."
            );

            $this->assertNotContains(
                'image/svg+xml',
                array_merge(...array_values($kind->extensionMimeMap())),
                "UploadKind::{$kind->name} must not allow the image/svg+xml mime type."
            );

            $this->postJson('/api/v1/uploads', [
                'type' => $kind->value,
                'file' => $this->uploadOf($this->svgBytes(), 'logo.svg'),
            ])->assertStatus(422);

            // …and renaming it does not help: the mime half catches it.
            $this->postJson('/api/v1/uploads', [
                'type' => $kind->value,
                'file' => $this->uploadOf($this->svgBytes(), 'logo.png'),
            ])->assertStatus(422);
        }

        $this->assertSame($this->inventoryBefore, $this->currentUploadInventory());
    }

    // ══ NO OCTET-STREAM CATCH-ALL ═════════════════════════════════════════

    public function test_random_binary_named_pdf_is_rejected_because_octet_stream_is_allowed_nowhere(): void
    {
        $this->loginAs($this->makeInstructor());

        $this->postJson('/api/v1/uploads', [
            'type' => 'document',
            'file' => $this->uploadOf(random_bytes(4096), 'report.pdf'),
        ])->assertStatus(422)
            ->assertJsonPath('data.errors.file.0', StoreUpload::UNSUPPORTED_FORMAT);

        $this->assertSame($this->inventoryBefore, $this->currentUploadInventory());
    }

    public function test_application_octet_stream_appears_in_no_kinds_allow_list(): void
    {
        foreach (UploadKind::cases() as $kind) {
            $this->assertNotContains(
                'application/octet-stream',
                array_merge(...array_values($kind->extensionMimeMap())),
                "UploadKind::{$kind->name} allows octet-stream, which is a universal bypass: EVERY "
                . 'binary finfo cannot classify sniffs as octet-stream.'
            );
        }
    }

    public function test_a_plain_zip_is_refused_even_though_docx_may_sniff_as_zip(): void
    {
        $this->loginAs($this->makeInstructor());

        // Allowed for .docx/.pptx ONLY — a bare .zip has no branch at all.
        $this->postJson('/api/v1/uploads', [
            'type' => 'document',
            'file' => $this->uploadOf($this->zipBytes(['payload.exe' => 'MZ']), 'archive.zip'),
        ])->assertStatus(422);

        $this->assertSame($this->inventoryBefore, $this->currentUploadInventory());
    }

    // ══ SIZE — open decision 10.22: NO CAP, EXPLICIT USER REQUEST ══════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THERE IS NO SIZE CAP ANY MORE, FOR ANY KIND. THIS IS DELIBERATE.     ║
     * ║                                                                      ║
     * ║ `UploadKind::maxBytes()` and the size branch in                      ║
     * ║ `StoreUpload::handle()` that compared against it are both GONE —     ║
     * ║ removed on explicit user request ("لا تقيديني بمساحة ملف او صورة"),  ║
     * ║ not tightened or renumbered. A file many times larger than the old   ║
     * ║ 2 MB (avatar/banner/image/profile) and 10 MB (document) caps must    ║
     * ║ now be ACCEPTED for both an image kind and the document kind — the   ║
     * ║ opposite of what this test used to assert. The only remaining        ║
     * ║ ceiling is PHP's own upload_max_filesize/post_max_size ini limits,   ║
     * ║ which this application does not control and are not exercised here. ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_file_far_larger_than_the_old_per_kind_caps_is_now_accepted(): void
    {
        $this->loginAs($this->makeInstructor());

        $fifteenMegabytes = 15 * 1024 * 1024;

        // Old avatar cap was 2 MB — this is 7.5x that.
        $avatar = $this->postJson('/api/v1/uploads', [
            'type' => 'avatar',
            'file' => $this->uploadOfSize($fifteenMegabytes, 'huge.png'),
        ]);
        $avatar->assertStatus(201);
        $this->rememberUploadedPath((string) $avatar->json('data.file.path'));

        // Old document cap was 10 MB — this is larger than that too.
        $document = $this->postJson('/api/v1/uploads', [
            'type' => 'document',
            'file' => $this->uploadOf($this->pdfBytes() . str_repeat(' ', $fifteenMegabytes), 'huge.pdf'),
        ]);
        $document->assertStatus(201);
        $this->rememberUploadedPath((string) $document->json('data.file.path'));
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ AN UNDETERMINABLE CONTENT TYPE IS A **422**, NEVER A 500 AND NEVER   ║
     * ║ AN ACCEPT. ADDED IN STAGE 10, FROM A REAL INTERMITTENT FAILURE.      ║
     * ║                                                                      ║
     * ║ Four security tests in this family failed one full-suite run with    ║
     * ║   finfo::file(…): Failed to open stream: Invalid argument            ║
     * ║ — a PHP warning that Laravel escalates to an ErrorException, turning ║
     * ║ an owed 422 into a 500. Symfony's own answer on that path is `null`, ║
     * ║ and `null` was already handled correctly; the WARNING was the bug.   ║
     * ║                                                                      ║
     * ║ This test drives the branch directly rather than trying to           ║
     * ║ reproduce a file-system race, because a race reproduced by luck is   ║
     * ║ a test that passes by luck. Both of Symfony's failure shapes are     ║
     * ║ covered: the thrown one (path is not a readable file) and, via the   ║
     * ║ same code path, the null-returning one.                              ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_an_undeterminable_content_type_is_rejected_and_never_escalates(): void
    {
        $action = app(StoreUpload::class);

        $method = new \ReflectionMethod($action, 'sniff');

        /*
         * The file must EXIST when the UploadedFile is constructed — Symfony's
         * File constructor rejects a missing path outright — and must be gone
         * by the time the sniff runs. Deleting it in between is the closest
         * faithful stand-in for the real fault: a handle that was valid a
         * moment ago and is not valid now.
         *
         * On this shape Symfony's guesser THROWS rather than returning null,
         * which is precisely the case a `null`-only check would miss.
         */
        $path = storage_path('framework/testing/uploads/vanishing-' . bin2hex(random_bytes(8)));
        file_put_contents($path, $this->pngBytes());

        $vanishing = new \Illuminate\Http\UploadedFile($path, 'photo.jpg', null, UPLOAD_ERR_OK, true);

        unlink($path);
        clearstatcache(true, $path);

        self::assertFileDoesNotExist($path, 'The fixture must actually be unreadable for this to test anything.');

        $result = $method->invoke($action, $vanishing);

        self::assertSame('', $result, 'An undeterminable type must degrade to the empty string.');

        // …and the empty string is in NO kind's allow-list, so it can only
        // ever be rejected. This is what makes the degradation safe rather
        // than a hole: it fails closed by construction.
        foreach (UploadKind::cases() as $kind) {
            foreach ($kind->extensionMimeMap() as $extension => $mimes) {
                self::assertNotContains(
                    '',
                    $mimes,
                    "The empty MIME is accepted for .{$extension} under {$kind->value} — the degradation is a hole."
                );
            }
        }
    }

    public function test_a_zero_byte_file_is_rejected(): void
    {
        $this->loginAs($this->makeInstructor());

        $this->postJson('/api/v1/uploads', [
            'type' => 'image',
            'file' => $this->uploadOf('', 'empty.png'),
        ])->assertStatus(422);

        $this->assertSame($this->inventoryBefore, $this->currentUploadInventory());
    }

    // ══ THE REQUEST ENVELOPE ══════════════════════════════════════════════

    public function test_an_unknown_kind_is_rejected_under_the_type_key(): void
    {
        $this->loginAs($this->makeInstructor());

        $this->postJson('/api/v1/uploads', [
            'type' => 'video',
            'file' => $this->uploadOf($this->pngBytes(), 'x.png'),
        ])->assertStatus(422)
            ->assertJsonPath('data.errors.type.0', 'نوع الرفع غير مدعوم');
    }

    public function test_a_missing_file_field_is_rejected(): void
    {
        $this->loginAs($this->makeInstructor());

        $this->postJson('/api/v1/uploads', ['type' => 'image'])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.file.0', 'الملف مطلوب');
    }

    /**
     * ┌── A MISSING `type` DEFAULTS TO `document`. PRESERVED, NOT FIXED. ────┐
     * │ It is the WIDEST bucket — 10 MB, and the only kind that is not four  │
     * │ raster formats. Making the field required would be safer and would   │
     * │ break any frontend call site that omits it, so the default is        │
     * │ preserved and DOCUMENTED (open decision 9.3) instead.                │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_missing_type_field_defaults_to_the_document_kind(): void
    {
        $this->loginAs($this->makeInstructor());

        $response = $this->postJson('/api/v1/uploads', [
            'file' => $this->uploadOf($this->pdfBytes(), 'notes.pdf'),
        ]);

        $response->assertStatus(201)->assertJsonPath('data.file.url', null);

        $this->assertStringStartsWith('uploads/documents/', (string) $response->json('data.file.path'));

        // …and the corollary: with no type, a PNG is now INVALID, because the
        // document allow-list has no png branch.
        $this->postJson('/api/v1/uploads', [
            'file' => $this->uploadOf($this->pngBytes(), 'photo.png'),
        ])->assertStatus(422);

        $this->rememberUploadedPath((string) $response->json('data.file.path'));
    }

    public function test_a_guest_cannot_upload(): void
    {
        $this->postJson('/api/v1/uploads', [
            'type' => 'image',
            'file' => $this->uploadOf($this->pngBytes(), 'x.png'),
        ])->assertStatus(401);

        $this->assertSame($this->inventoryBefore, $this->currentUploadInventory());
    }

    public function test_a_student_may_upload_because_the_rules_are_per_kind_not_per_role(): void
    {
        $this->loginAs($this->makeStudentUser());

        $response = $this->postJson('/api/v1/uploads', [
            'type' => 'document',
            'file' => $this->uploadOf($this->pdfBytes(), 'homework.pdf'),
        ]);

        $response->assertStatus(201);
        $this->rememberUploadedPath((string) $response->json('data.file.path'));
    }

    // ══ THE STORED NAME ═══════════════════════════════════════════════════

    /**
     * NO PART OF THE CLIENT FILENAME SURVIVES. That is what makes
     * StoredUploadPath's strict `<uuid>.<ext>` pattern a PROOF rather than a
     * filter: if any client bytes could reach the name, the pattern would have
     * to be right about every encoding instead of about one regex.
     */
    public function test_an_adversarial_original_filename_leaves_no_trace_in_the_stored_name(): void
    {
        $this->loginAs($this->makeInstructor());

        $hostile = '../../evil.png';

        $response = $this->postJson('/api/v1/uploads', [
            'type' => 'image',
            'file' => $this->uploadOf($this->pngBytes(), $hostile),
        ]);

        $response->assertStatus(201);

        $file = $response->json('data.file');
        $stored = (string) $file['stored_name'];

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.png$/',
            $stored,
            'The stored name must be a bare server-generated uuid plus the verified extension.'
        );

        $this->assertStringNotContainsString('evil', $stored);
        $this->assertStringNotContainsString('..', $stored);
        $this->assertStringNotContainsString('..', (string) $file['path']);
        $this->assertStringNotContainsString('evil', (string) $file['url']);

        /*
         * ┌── `file_name` IS THE ECHOED CLIENT NAME, AND SYMFONY HAS ALREADY ┐
         * │ BASENAMED IT — MEASURED HERE RATHER THAN ASSUMED.                │
         * │                                                                   │
         * │ Symfony's UploadedFile constructor runs the client name through  │
         * │ its own getName() (a basename), so '../../evil.png' arrives at   │
         * │ the action as 'evil.png'. That is a THIRD, independent layer     │
         * │ underneath our two: the stored name would be a uuid regardless,  │
         * │ and this value never touches the filesystem in any case. It is   │
         * │ pinned so that a future change of upload plumbing that stopped   │
         * │ sanitising is visible in a diff instead of silent.               │
         * └───────────────────────────────────────────────────────────────────┘
         */
        $this->assertSame('evil.png', $file['file_name']);
        $this->assertStringNotContainsString('..', (string) $file['file_name']);

        $this->assertFileExists($this->uploadsPath('images/' . $stored));
        $this->assertSame(
            [...$this->inventoryBefore, 'images/' . $stored],
            $this->currentUploadInventory(),
            'Exactly ONE file was created, and it is inside images/ — not one directory up.'
        );

        $this->rememberUploadedPath((string) $file['path']);
    }

    public function test_two_uploads_of_identical_bytes_get_different_stored_names(): void
    {
        $this->loginAs($this->makeInstructor());

        $names = [];

        foreach (range(1, 2) as $ignored) {
            $response = $this->postJson('/api/v1/uploads', [
                'type' => 'image',
                'file' => $this->uploadOf($this->pngBytes(), 'same.png'),
            ]);

            $response->assertStatus(201);
            $names[] = $response->json('data.file.stored_name');
            $this->rememberUploadedPath((string) $response->json('data.file.path'));
        }

        $this->assertNotSame($names[0], $names[1], 'A collision would let one upload overwrite another.');
    }

    // ══ RATE LIMITING (row 71's `throttle.lockout:upload`) ════════════════

    /**
     * ┌── THE LIMITER RUNS **BEFORE** `auth`, WHICH IS THE POINT. ──────────┐
     * │ An upload costs a disk write and a finfo read of real bytes, so a   │
     * │ flood must be refused before the per-request user lookup. Proven by │
     * │ flooding as a GUEST: if `auth` ran first every response would be    │
     * │ 401 forever and the limiter would never be reached.                 │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_the_limiter_runs_before_auth_on_the_upload_route(): void
    {
        $this->enableRateLimitingForThisTest();

        $statuses = [];

        foreach (range(1, 21) as $ignored) {
            $statuses[] = $this->postJson('/api/v1/uploads', [])->getStatusCode();
            $this->startNextRequest();
        }

        $this->assertSame(
            array_fill(0, 20, 401),
            array_slice($statuses, 0, 20),
            'The bucket is 20/minute, so the first twenty must still reach `auth` and answer 401.'
        );

        $this->assertSame(429, $statuses[20], 'Request 21 must be throttled, not answered 401 again.');
    }

    public function test_the_twenty_first_upload_in_a_minute_is_429_with_retry_after_and_arabic(): void
    {
        $this->enableRateLimitingForThisTest();
        $this->loginAs($this->makeInstructor());

        foreach (range(1, 20) as $ignored) {
            $this->postJson('/api/v1/uploads', [
                'type' => 'image',
                'file' => $this->uploadOf($this->pngBytes(), 'x.png'),
            ]);
            $this->startNextRequest();
        }

        $throttled = $this->postJson('/api/v1/uploads', [
            'type' => 'image',
            'file' => $this->uploadOf($this->pngBytes(), 'x.png'),
        ]);

        $throttled->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('success', false);

        $this->assertMatchesRegularExpression(
            '/عدد المحاولات كبير جداً/u',
            (string) $throttled->json('message'),
            'The 429 body is Arabic and is rendered by the frontend verbatim.'
        );

        $this->assertGreaterThanOrEqual(1, (int) $throttled->json('data.retry_after'));
    }

    public function test_the_upload_bucket_carries_backends_exact_numbers(): void
    {
        $this->assertSame(
            [
                'max_attempts' => 20,
                'window_seconds' => 60,
                'lockout_seconds' => 120,
                'max_lockout_seconds' => 1800,
            ],
            config('ratelimit.buckets.upload'),
            'Ported literally from backend/config/ratelimit.php. Changing these silently changes an '
            . 'operational contract nobody re-derives.'
        );
    }

    /**
     * The two GET routes are NOT limited. A course page rendering twelve
     * thumbnails would trip a 20/minute bucket on its own.
     */
    public function test_the_file_serving_routes_carry_no_upload_throttle(): void
    {
        foreach (['files.show', 'content-items.file'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route {$name} is not registered.");
            $this->assertNotContains('throttle.lockout:upload', $route->gatherMiddleware());
        }

        $upload = app('router')->getRoutes()->getByName('uploads.store');

        $this->assertNotNull($upload);
        $this->assertContains('throttle.lockout:upload', $upload->gatherMiddleware());
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @param array<string, mixed>|null $payload @return list<string> */
    private function sortedKeys(?array $payload): array
    {
        $keys = array_keys((array) $payload);
        sort($keys);

        return $keys;
    }

    /**
     * setUp() disables the limiter for the majority of this suite; the three
     * throttle tests turn it back on explicitly rather than the other way
     * round, so a forgotten flag cannot silently disarm them.
     */
    private function enableRateLimitingForThisTest(): void
    {
        config(['ratelimit.enabled' => true]);
        Cache::flush();
    }
}
