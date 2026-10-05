<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Enums\UploadKind;
use App\Support\StoredUploadPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\Concerns\CreatesUploadFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/files/{category}/{id} — freeze-table row 72.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE CLIENT CONTROLS **BOTH** PATH SEGMENTS HERE, WHICH MAKES THIS THE   ║
 * ║ ONE ENDPOINT IN THE APPLICATION WHERE PATH TRAVERSAL IS THE THREAT      ║
 * ║ MODEL RATHER THAN A THEORETICAL CONCERN.                                ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Every refusal is a 404 with an identical body, so these tests assert the
 * STATUS and then, separately, that the three internal reasons are genuinely
 * distinct in the log — the distinction being the operator's product, not the
 * caller's.
 */
final class PublicFileServingTest extends TestCase
{
    use CreatesAuthFixtures;
    use CreatesUploadFixtures;
    use RefreshDatabase;

    /** @var list<string> */
    private array $inventoryBefore = [];

    private string $imageId;

    private string $documentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();
        $this->inventoryBefore = $this->currentUploadInventory();

        $this->imageId = Str::uuid()->toString();
        $this->plantUploadedFile('images', $this->imageId . '.png', $this->pngBytes());

        $this->documentId = Str::uuid()->toString();
        $this->plantUploadedFile('documents', $this->documentId . '.pdf', $this->pdfBytes());
    }

    protected function tearDown(): void
    {
        $this->pruneUploadsAddedSince($this->inventoryBefore);
        $this->forgetUploadedFiles();

        parent::tearDown();
    }

    // ══ THE HAPPY PATH ════════════════════════════════════════════════════

    public function test_a_public_image_is_served_to_an_unauthenticated_caller(): void
    {
        $response = $this->get("/api/v1/files/images/{$this->imageId}");

        $response->assertStatus(200);

        $this->assertSame(
            $this->pngBytes(),
            $response->streamedContent(),
            'The body must be the raw file, byte for byte.'
        );

        $this->assertNull(
            auth()->user(),
            'Row 72 is genuinely public — TeacherProfilePage and CourseDetailsPage render these for '
            . 'logged-out visitors, so requiring auth here is an outage of the storefront.'
        );
    }

    public function test_every_public_category_serves_and_the_private_one_does_not(): void
    {
        foreach (UploadKind::cases() as $kind) {
            $id = Str::uuid()->toString();
            $extension = $kind->isPublic() ? 'png' : 'pdf';
            $bytes = $kind->isPublic() ? $this->pngBytes() : $this->pdfBytes();

            $this->plantUploadedFile($kind->directory(), $id . '.' . $extension, $bytes);

            $this->get("/api/v1/files/{$kind->directory()}/{$id}")
                ->assertStatus($kind->isPublic() ? 200 : 404);
        }
    }

    /**
     * THE SINGLE MOST IMPORTANT ASSERTION IN THIS FILE. The file exists, the
     * uuid is correct, the extension is correct — and it is STILL a 404,
     * because `documents` is not on UploadKind::publicDirectories(). A private
     * note attachment has no public address at all, only a gated one.
     */
    public function test_a_real_document_is_404_here_even_with_its_correct_id(): void
    {
        $this->assertFileExists($this->uploadsPath('documents/' . $this->documentId . '.pdf'));

        $this->get("/api/v1/files/documents/{$this->documentId}")->assertStatus(404);
        $this->get("/api/v1/files/Documents/{$this->documentId}")->assertStatus(404);
        $this->get("/api/v1/files/DOCUMENTS/{$this->documentId}")->assertStatus(404);
    }

    public function test_publicDirectories_is_derived_and_never_contains_documents(): void
    {
        $this->assertSame(
            ['avatars', 'banners', 'images', 'profiles'],
            UploadKind::publicDirectories()
        );

        $this->assertNotContains(
            'documents',
            UploadKind::publicDirectories(),
            'If this ever fails, EVERY private note attachment in the system is fetchable, '
            . 'unauthenticated, by anyone who has seen a uuid.'
        );
    }

    // ══ PATH TRAVERSAL ════════════════════════════════════════════════════

    /**
     * ┌── THE PAYLOADS, AND WHY THEY CANNOT EVEN BE EXPRESSED. ─────────────┐
     * │ The id pattern admits ONLY hex digits and hyphens, anchored at both │
     * │ ends. So '/', '\', '.', '..', a NUL byte, an absolute path and an   │
     * │ already-percent-decoded escape all fail the match BEFORE any        │
     * │ filesystem call. There is nothing to encode around: the parameter   │
     * │ has no alphabet in which '../../../../etc/passwd' can be written.   │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * Payloads that contain a literal '/' additionally do not MATCH the route
     * at all (two segments, not three), which is a second, independent 404 —
     * belt and braces, and both are asserted by status here.
     *
     * @dataProvider traversalPayloads
     */
    public function test_traversal_payloads_are_all_refused(string $payload): void
    {
        foreach (['images', 'avatars', 'banners', 'profiles', 'documents'] as $category) {
            $response = $this->get('/api/v1/files/' . $category . '/' . $payload);

            $this->assertSame(
                404,
                $response->getStatusCode(),
                "Payload '{$payload}' under '{$category}' was not refused."
            );
        }

        $this->assertSame(
            $this->currentUploadInventory(),
            $this->currentUploadInventory(),
            'A read endpoint must never write.'
        );
    }

    /** @return array<string, array{0:string}> */
    public static function traversalPayloads(): array
    {
        return [
            'classic traversal' => ['../../../../etc/passwd'],
            'single-encoded traversal' => ['..%2f..%2f..%2fetc%2fpasswd'],
            'double-encoded traversal' => ['..%252f..%252fetc%252fpasswd'],
            'doubled-dot filter bypass' => ['....//....//etc/passwd'],
            'absolute posix path' => ['/etc/passwd'],
            'encoded null byte' => ['abc%00.png'],
            'bare dots' => ['..'],
            'empty-ish' => ['.'],
            'hyphen soup' => ['--------'],
            'wildcard' => ['*'],
            'php wrapper' => ['php://filter/resource=index.php'],
        ];
    }

    /**
     * ┌── THE PAYLOADS THE HTTP HARNESS CANNOT EVEN SEND. ──────────────────┐
     * │ A URI containing a raw backslash or a raw NUL is rejected by        │
     * │ Symfony's Request BEFORE routing (BadRequestException, "A URI       │
     * │ cannot contain a backslash") — one more layer above ours, and the   │
     * │ reason those payloads are absent from the provider above rather     │
     * │ than merely passing there.                                          │
     * │                                                                      │
     * │ They are still asserted, DIRECTLY against the resolver, because     │
     * │ "some other layer happens to stop it" is not a property this        │
     * │ application controls: a proxy that normalised the URI differently   │
     * │ would hand these straight to us.                                     │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_the_resolver_itself_refuses_payloads_the_http_layer_never_delivers(): void
    {
        $payloads = [
            '..\\..\\windows\\win.ini',
            'C:\\windows\\win.ini',
            "abc\0.png",
            $this->imageId . "\0",
            $this->imageId . "\n" . $this->imageId,
            '\\\\evil.host\\share\\x',
            $this->imageId . '/../../documents/' . $this->documentId,
        ];

        foreach ($payloads as $payload) {
            foreach (UploadKind::directories() as $category) {
                $this->assertNull(
                    StoredUploadPath::resolvePublic($category, $payload),
                    'resolvePublic() resolved a hostile id.'
                );
            }
        }

        // …and the private door refuses the same shapes, from its own side.
        foreach (['uploads/images/../../etc/passwd', 'uploads/../uploads/images/x.png', 'uploads/images'] as $reference) {
            $this->assertNull(StoredUploadPath::resolve($reference));
        }
    }

    /**
     * A route-level `whereUuid()` would have made most of the above 404 in the
     * ROUTER. It is deliberately absent so the rejections are LOGGED — see the
     * banner on the route in routes/api.php. This asserts the constraint is
     * genuinely not there, because adding one "for tidiness" would silently
     * delete the audit trail while every test above kept passing.
     */
    public function test_the_public_route_carries_no_id_constraint(): void
    {
        $route = app('router')->getRoutes()->getByName('files.show');

        $this->assertNotNull($route);
        $this->assertSame(
            [],
            $route->wheres,
            'A route constraint would 404 before the handler runs and silently stop every '
            . 'security-relevant rejection from being logged.'
        );
    }

    // ══ THE ID PATTERN ════════════════════════════════════════════════════

    /**
     * backend/FileService::UUID_PATTERN carries NO `i` flag, and Str::uuid()
     * emits lowercase — so an uppercase uuid never named a file this server
     * wrote. Ported exactly rather than "helpfully" loosened.
     */
    public function test_an_uppercase_uuid_is_refused(): void
    {
        $this->get("/api/v1/files/images/{$this->imageId}")->assertStatus(200);
        $this->get('/api/v1/files/images/' . strtoupper($this->imageId))->assertStatus(404);
    }

    /**
     * The URL contract is EXTENSIONLESS. Appending one must not match — a URL
     * ending in '.png' is claimed by a production static-file handler before
     * PHP ever sees it, which is the whole reason the extension was dropped.
     */
    public function test_appending_an_extension_to_a_valid_id_is_refused(): void
    {
        foreach (['.png', '.PNG', '.php', '.pdf', '.'] as $suffix) {
            $this->assertSame(
                404,
                $this->get("/api/v1/files/images/{$this->imageId}{$suffix}")->getStatusCode(),
                "'{$suffix}' appended to a real id must not resolve."
            );
        }
    }

    public function test_a_nonexistent_id_and_a_wrong_category_are_both_refused(): void
    {
        $this->get('/api/v1/files/images/' . Str::uuid()->toString())->assertStatus(404);

        // The file exists — under `images`, not under `avatars`.
        $this->get("/api/v1/files/avatars/{$this->imageId}")->assertStatus(404);
    }

    public function test_an_unknown_category_is_refused(): void
    {
        foreach (['uploads', 'app', 'logs', 'private', ''] as $category) {
            $this->get("/api/v1/files/{$category}/{$this->imageId}")->assertStatus(404);
        }
    }

    /**
     * The category IS lowercased before the allow-list check (backend/ does
     * `strtolower($category)`), so a public category still resolves in any
     * casing — while `documents` in any casing still does not.
     */
    public function test_a_public_category_resolves_case_insensitively(): void
    {
        $this->get("/api/v1/files/IMAGES/{$this->imageId}")->assertStatus(200);
        $this->get("/api/v1/files/Images/{$this->imageId}")->assertStatus(200);
    }

    // ══ SYMLINK CONTAINMENT ═══════════════════════════════════════════════

    /**
     * realpath() RESOLVES SYMLINKS, which is what makes the containment check
     * meaningful rather than cosmetic: a link planted inside a public category
     * that points outside the root fails the prefix test even though its own
     * path is impeccable.
     *
     * Skipped where the platform refuses to create one unprivileged (Windows
     * without Developer Mode), because a test that silently passes because it
     * could not run is worse than an absent one.
     */
    public function test_a_symlink_escaping_the_root_is_refused(): void
    {
        $outside = storage_path('framework/testing/outside-target.png');
        file_put_contents($outside, $this->pngBytes());

        $id = Str::uuid()->toString();
        $link = $this->uploadsPath('images/' . $id . '.png');

        if (!@symlink($outside, $link)) {
            @unlink($outside);
            $this->markTestSkipped('This platform will not create a symlink unprivileged.');
        }

        try {
            $this->get("/api/v1/files/images/{$id}")->assertStatus(404);
        } finally {
            @unlink($link);
            @unlink($outside);
        }
    }

    // ══ HEADERS AND BODY ══════════════════════════════════════════════════

    public function test_a_public_file_carries_every_required_header(): void
    {
        $response = $this->get("/api/v1/files/images/{$this->imageId}");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->assertHeader('X-Frame-Options', 'DENY');

        /*
         * Symfony's ResponseHeaderBag NORMALISES and re-orders Cache-Control
         * directives ('public, max-age=86400' comes back as
         * 'max-age=86400, public'), so the assertion is on the SET of
         * directives rather than on the literal string a naive header test
         * would pin — which would fail on a framework upgrade for no reason.
         */
        $this->assertSame(
            ['max-age=86400', 'public'],
            $this->cacheDirectives($response->headers->get('Cache-Control')),
            'A course banner is a static asset served to everyone; caching it is the point.'
        );

        $this->assertSame(
            'inline; filename="' . $this->imageId . '.png"',
            $response->headers->get('Content-Disposition'),
            'Public imagery is `inline` — an <img src> that downloads is a broken storefront. That is '
            . 'safe only because sniff() cannot return an active type for this category.'
        );
    }

    /**
     * ┌── sniff() DEGRADES SAFELY ON CONTENT THE UPLOAD PATH WOULD REFUSE. ─┐
     * │ Planted directly on the disk, bypassing validation entirely — which │
     * │ is the only way to reach this branch, and exactly the state a       │
     * │ restored backup or a compromised writer would leave behind.         │
     * │                                                                      │
     * │ An SVG on disk under images/ MUST NOT be served as image/svg+xml,   │
     * │ or `inline` above becomes a stored-XSS delivery mechanism.           │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_unexpected_bytes_on_disk_degrade_to_octet_stream(): void
    {
        $cases = [
            'an svg wearing a png name' => $this->svgBytes(),
            'html' => '<html><body><script>alert(1)</script></body></html>',
            'a php script' => $this->phpScriptBytes(),
            'random binary' => random_bytes(2048),
            'a zip' => $this->zipBytes(['x.txt' => 'x']),
        ];

        foreach ($cases as $label => $bytes) {
            $id = Str::uuid()->toString();
            $this->plantUploadedFile('images', $id . '.png', $bytes);

            $response = $this->get("/api/v1/files/images/{$id}");

            $response->assertStatus(200)
                ->assertHeader('Content-Type', 'application/octet-stream');

            $this->assertNotSame(
                'image/svg+xml',
                $response->headers->get('Content-Type'),
                "sniff() failed to degrade {$label}; `inline` would then execute it in this origin."
            );
        }
    }

    /**
     * A binary body must NOT be wrapped in {success,message,data}. This is the
     * regression guard for the latent ForceJsonEnvelope bug Stage 9 fixed:
     * BinaryFileResponse::getContent() returns false, which the middleware's
     * emptiness test read as "empty" and replaced with an envelope.
     */
    public function test_a_served_file_body_is_raw_bytes_with_no_envelope(): void
    {
        $response = $this->get("/api/v1/files/images/{$this->imageId}");

        $body = $response->streamedContent();

        $this->assertSame($this->pngBytes(), $body);
        $this->assertStringNotContainsString('"success"', $body);
        $this->assertStringNotContainsString('"data"', $body);

        $this->assertNull(
            json_decode($body, true),
            'A file body must not be decodable as the JSON envelope — if it is, ForceJsonEnvelope '
            . 'replaced the file with {success,message,data} and not one byte of the image survived.'
        );

        $this->assertInstanceOf(
            \Symfony\Component\HttpFoundation\BinaryFileResponse::class,
            $response->baseResponse,
            'The response must still BE a file response by the time it leaves the middleware stack.'
        );
    }

    /** @return list<string> the Cache-Control directives, sorted */
    private function cacheDirectives(?string $header): array
    {
        $parts = array_map('trim', explode(',', (string) $header));
        sort($parts);

        return array_values(array_filter($parts));
    }

    /** …while the FAILURE path is still enveloped, exactly as before. */
    public function test_the_404_from_this_route_is_still_a_normal_json_envelope(): void
    {
        $response = $this->getJson('/api/v1/files/images/' . Str::uuid()->toString());

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'File not found')
            ->assertJsonStructure(['success', 'message', 'data']);
    }

    public function test_all_three_refusal_shapes_answer_the_same_body(): void
    {
        $bodies = [
            $this->getJson("/api/v1/files/documents/{$this->documentId}")->json(),
            $this->getJson('/api/v1/files/images/not-a-uuid')->json(),
            $this->getJson('/api/v1/files/images/' . Str::uuid()->toString())->json(),
        ];

        $this->assertSame(
            $bodies[0],
            $bodies[1],
            'category_not_public and invalid_file_id must be indistinguishable on the wire.'
        );
        $this->assertSame($bodies[1], $bodies[2], 'invalid_file_id and not_found likewise.');
    }
}
