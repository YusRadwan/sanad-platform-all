<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\Concerns\CreatesUploadFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE REJECTION REASONS ARE THE PRODUCT OF THESE ENDPOINTS — FOR THE      ║
 * ║ OPERATOR, NOT FOR THE CALLER.                                           ║
 * ║                                                                          ║
 * ║ Every wire answer here is deliberately indistinguishable: two upload     ║
 * ║ rejections share one message, three serving rejections share one 404.    ║
 * ║ That is correct, and it means the ONLY place the difference exists is    ║
 * ║ the security log. An untested log is a log that silently stops being     ║
 * ║ written, at which point somebody probing for MIME-filter bypasses or     ║
 * ║ path traversal is invisible — and the indistinguishable responses,       ║
 * ║ which were a security feature, become the reason nobody noticed.        ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class FileSecurityLoggingTest extends TestCase
{
    use CreatesAuthFixtures;
    use CreatesUploadFixtures;
    use RefreshDatabase;

    /** @var list<\Illuminate\Log\Events\MessageLogged> */
    private array $captured = [];

    /** @var list<string> */
    private array $inventoryBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();
        $this->inventoryBefore = $this->currentUploadInventory();

        Log::channel('security')->listen(function ($message): void {
            $this->captured[] = $message;
        });
    }

    protected function tearDown(): void
    {
        $this->pruneUploadsAddedSince($this->inventoryBefore);
        $this->forgetUploadedFiles();

        parent::tearDown();
    }

    // ══ UPLOAD REJECTIONS ═════════════════════════════════════════════════

    /**
     * All seven documented reasons except `move_failed` (which needs a broken
     * filesystem) and `php_upload_error_{N}` (covered separately below).
     *
     * @dataProvider uploadRejections
     */
    public function test_each_upload_rejection_logs_its_own_reason(
        string $expectedReason,
        string $type,
        string $contentKey,
        string $clientName,
    ): void {
        $this->loginAs($this->makeInstructor());

        $contents = match ($contentKey) {
            'png' => $this->pngBytes(),
            'php' => $this->phpScriptBytes(),
            'svg' => $this->svgBytes(),
            'empty' => '',
            default => $this->pdfBytes(),
        };

        $this->postJson('/api/v1/uploads', [
            'type' => $type,
            'file' => $this->uploadOf($contents, $clientName),
        ])->assertStatus(422);

        $this->assertSame(
            [$expectedReason],
            $this->reasonsFor('upload.rejected'),
            "The rejection must be logged as '{$expectedReason}' — and as nothing else."
        );
    }

    /**
     * "Too big" is GONE — open decision 10.22 removed the size cap entirely,
     * explicit user request. Only the zero-byte case still logs
     * `size_out_of_range`; it is not a size RESTRICTION, it is rejecting
     * something that cannot be a real file regardless of any size policy.
     *
     * @return array<string, array{0:string,1:string,2:string,3:string}>
     */
    public static function uploadRejections(): array
    {
        return [
            'unsupported kind' => ['unsupported_kind', 'video', 'png', 'x.png'],
            'size out of range (zero)' => ['size_out_of_range', 'avatar', 'empty', 'x.png'],
            'extension not allowed' => ['extension_not_allowed', 'image', 'png', 'shell.php'],
            'extension not allowed (no extension)' => ['extension_not_allowed', 'image', 'png', 'noextension'],
            'mime mismatch (script as jpg)' => ['mime_extension_mismatch', 'image', 'php', 'photo.jpg'],
            'mime mismatch (svg as png)' => ['mime_extension_mismatch', 'image', 'svg', 'logo.png'],
            'mime mismatch (pdf as png)' => ['mime_extension_mismatch', 'image', 'pdf', 'doc.png'],
            'mime mismatch (png as pdf)' => ['mime_extension_mismatch', 'document', 'png', 'doc.pdf'],
        ];
    }

    /**
     * The kind check runs FIRST, so an upload that is wrong in two ways is
     * logged as `unsupported_kind` and never reaches the extension half. The
     * order is backend/'s and it matters: every later rule is READ FROM the
     * kind, so there is nothing to check against until it resolves.
     */
    public function test_the_check_order_is_preserved_when_several_rules_would_fail(): void
    {
        $this->loginAs($this->makeInstructor());

        // Unknown kind AND a forbidden extension AND mismatched content.
        $this->postJson('/api/v1/uploads', [
            'type' => 'not-a-kind',
            'file' => $this->uploadOf($this->phpScriptBytes(), 'shell.php'),
        ])->assertStatus(422);

        $this->assertSame(['unsupported_kind'], $this->reasonsFor('upload.rejected'));

        $this->captured = [];

        // Valid kind, a zero-byte "file" AND a forbidden extension: the
        // zero-byte probe is still checked before the mime sniff — the last
        // survivor of the old size-check-runs-first ordering, now that an
        // oversized-but-real file is no longer rejected at all (open
        // decision 10.22).
        $this->postJson('/api/v1/uploads', [
            'type' => 'avatar',
            'file' => $this->uploadOf('', 'shell.php'),
        ])->assertStatus(422);

        $this->assertSame(
            ['size_out_of_range'],
            $this->reasonsFor('upload.rejected'),
            'The zero-byte check runs BEFORE the mime sniff so an empty upload is refused before its '
            . 'bytes are read off disk — the same economy as running the limiter before `auth`.'
        );
    }

    public function test_a_php_upload_error_is_logged_with_its_numeric_code(): void
    {
        $this->loginAs($this->makeInstructor());

        $this->postJson('/api/v1/uploads', [
            'type' => 'image',
            'file' => $this->uploadOf($this->pngBytes(), 'x.png', UPLOAD_ERR_INI_SIZE),
        ])->assertStatus(422);

        $this->assertSame(
            ['php_upload_error_' . UPLOAD_ERR_INI_SIZE],
            $this->reasonsFor('upload.rejected'),
            'The numeric code is kept so an operator can tell an ini limit from a partial upload.'
        );
    }

    /** A hostile client filename must not be able to forge extra log lines. */
    public function test_a_newline_in_the_client_filename_cannot_forge_a_log_line(): void
    {
        $this->loginAs($this->makeInstructor());

        $this->postJson('/api/v1/uploads', [
            'type' => 'image',
            'file' => $this->uploadOf($this->pngBytes(), "ok.php\r\n[FORGED] upload.accepted\r\n"),
        ])->assertStatus(422);

        $entries = array_values(array_filter(
            $this->captured,
            static fn ($m): bool => ($m->context['event'] ?? null) === 'upload.rejected'
        ));

        $this->assertCount(1, $entries, 'Exactly ONE log entry, whatever the filename contained.');

        $recorded = (string) $entries[0]->context['file_name'];

        $this->assertStringNotContainsString("\n", $recorded);
        $this->assertStringNotContainsString("\r", $recorded);
        $this->assertLessThanOrEqual(120, strlen($recorded), 'Truncated, so a huge name cannot flood.');
    }

    /** A SUCCESS is logged too — rejections alone are not an audit trail. */
    public function test_an_accepted_upload_logs_the_category_scoped_name_and_no_server_path(): void
    {
        $this->loginAs($this->makeInstructor());

        $response = $this->postJson('/api/v1/uploads', [
            'type' => 'image',
            'file' => $this->uploadOf($this->pngBytes(), 'photo.png'),
        ]);

        $response->assertStatus(201);
        $this->rememberUploadedPath((string) $response->json('data.file.path'));

        $accepted = array_values(array_filter(
            $this->captured,
            static fn ($m): bool => ($m->context['event'] ?? null) === 'upload.accepted'
        ));

        $this->assertCount(1, $accepted);
        $this->assertSame('image/png', $accepted[0]->context['actual_mime']);
        $this->assertSame(
            'images/' . $response->json('data.file.stored_name'),
            $accepted[0]->context['stored_name'],
            'Category-scoped identifier, never the absolute server path.'
        );

        $this->assertStringNotContainsString(
            (string) config('upload.storage_path'),
            (string) json_encode($accepted[0]->context)
        );
    }

    // ══ PUBLIC-SERVING REJECTIONS ═════════════════════════════════════════

    /**
     * Three rejections, ONE wire answer, three distinct log reasons. This is
     * the pairing the whole design depends on — and the reason there is no
     * `whereUuid()` constraint on the route, which would 404 in the router and
     * silently delete every line below.
     */
    public function test_each_public_serving_rejection_logs_its_own_reason(): void
    {
        $id = Str::uuid()->toString();
        $this->plantUploadedFile('documents', $id . '.pdf', $this->pdfBytes());

        $cases = [
            'category_not_public' => "/api/v1/files/documents/{$id}",
            'invalid_file_id' => '/api/v1/files/images/not-a-uuid',
            'not_found' => '/api/v1/files/images/' . Str::uuid()->toString(),
        ];

        foreach ($cases as $expectedReason => $uri) {
            $this->captured = [];

            $this->get($uri)->assertStatus(404);

            $this->assertSame(
                [$expectedReason],
                $this->reasonsFor('file.denied'),
                "'{$uri}' must be logged as '{$expectedReason}'."
            );
        }
    }

    public function test_an_uppercase_uuid_is_logged_as_an_invalid_file_id(): void
    {
        $id = Str::uuid()->toString();
        $this->plantUploadedFile('images', $id . '.png', $this->pngBytes());

        $this->get('/api/v1/files/images/' . strtoupper($id))->assertStatus(404);

        $this->assertSame(['invalid_file_id'], $this->reasonsFor('file.denied'));
    }

    /**
     * ┌── TRAVERSAL IS REFUSED IN **TWO TIERS**, AND ONLY ONE OF THEM LOGS. ─┐
     * │ MEASURED, NOT ASSUMED:                                               │
     * │                                                                       │
     * │  TIER 1 — a payload containing a literal '/' turns the URL into      │
     * │  THREE OR MORE segments, so `/files/{category}/{id}` never matches   │
     * │  and the ROUTER answers 404. Nothing is logged, and nothing should   │
     * │  be: the request never named a file, it named a path that does not   │
     * │  exist. '....//....//etc/passwd' lands here.                          │
     * │                                                                       │
     * │  TIER 2 — a slash-free hostile id DOES reach resolvePublic(), fails  │
     * │  the uuid pattern, and IS logged as `invalid_file_id`. That is the   │
     * │  tier an operator watches, and the tier a route-level whereUuid()    │
     * │  would silently delete by promoting everything to tier 1.             │
     * └───────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_traversal_attempt_is_logged_rather_than_silently_routed_away(): void
    {
        // TIER 1: refused by the router, before any handler.
        $this->get('/api/v1/files/images/....//....//etc/passwd')->assertStatus(404);

        $this->assertSame(
            [],
            $this->reasonsFor('file.denied'),
            'A multi-segment path never reaches the resolver; the router refuses it first.'
        );

        // TIER 2: reaches the resolver, and must be logged.
        $this->captured = [];

        /*
         * DOUBLE-ENCODED on purpose. A single '%2f' is decoded to a real '/'
         * by the time routing happens, which makes it tier 1 again. Decoding
         * this one ONCE yields '..%2f..%2fetc%2fpasswd' — a single segment
         * with no literal slash — which is precisely the "already
         * percent-decoded escape" the uuid pattern exists to refuse, and the
         * shape a double-decoding proxy would hand us.
         */
        $this->get('/api/v1/files/images/..%252f..%252fetc%252fpasswd')->assertStatus(404);

        $this->assertSame(
            ['invalid_file_id'],
            $this->reasonsFor('file.denied'),
            'If this ever returns [], somebody added a route constraint and deleted the audit trail.'
        );

        $entry = array_values(array_filter(
            $this->captured,
            static fn ($m): bool => ($m->context['event'] ?? null) === 'file.denied'
        ))[0];

        $this->assertStringNotContainsString("\n", (string) $entry->context['requested_name']);
        $this->assertLessThanOrEqual(120, strlen((string) $entry->context['requested_name']));

        $this->assertStringNotContainsString(
            (string) config('upload.storage_path'),
            (string) json_encode($entry->context),
            'The absolute server path must never appear in a denial line.'
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return list<string> the `reason` of every captured entry with this event */
    private function reasonsFor(string $event): array
    {
        $reasons = [];

        foreach ($this->captured as $message) {
            if (($message->context['event'] ?? null) === $event) {
                $reasons[] = (string) ($message->context['reason'] ?? '(none)');
            }
        }

        return $reasons;
    }
}
