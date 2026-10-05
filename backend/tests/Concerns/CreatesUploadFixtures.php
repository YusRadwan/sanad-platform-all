<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;

/**
 * REAL BYTES, NOT `UploadedFile::fake()`.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE WHOLE POINT OF STAGE 9's UPLOAD VALIDATION IS THAT THE DECLARED     ║
 * ║ EXTENSION AND THE SNIFFED CONTENT ARE CHECKED **SEPARATELY**. A fixture ║
 * ║ helper that cannot make them DISAGREE cannot test it at all.            ║
 * ║                                                                          ║
 * ║ `UploadedFile::fake()->create('x.pdf', 10)` writes ten kilobytes of NUL  ║
 * ║ and lets the caller assert a mime — i.e. it fakes exactly the half the   ║
 * ║ endpoint is supposed to verify. `fake()->image()` needs GD and produces  ║
 * ║ a real raster, but still cannot produce "a genuine PNG named .php".      ║
 * ║                                                                          ║
 * ║ So every fixture here is REAL FILE CONTENT written to a temp file and    ║
 * ║ handed to Symfony's UploadedFile in TEST MODE, with the client-declared  ║
 * ║ name supplied independently of the bytes. `finfo` then reads whatever is ║
 * ║ actually there, exactly as it will in production.                       ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * The fifth constructor argument (`$test = true`) is what lets
 * UploadedFile::isValid() pass without `is_uploaded_file()` being true. It
 * skips ONLY that one check; getSize(), getMimeType(), getClientOriginalName()
 * and getError() all behave exactly as they do behind a real multipart POST.
 *
 * ┌── EVERY FILE THIS TRAIT TOUCHES IS CLEANED UP. ──────────────────────────┐
 * │ Temp fixtures are unlinked, and — more importantly — anything the        │
 * │ endpoint under test actually WROTE into storage/uploads is removed by    │
 * │ forgetUploadedFiles(). A test suite that leaves real files in the        │
 * │ application's storage directory is a test suite that slowly poisons the  │
 * │ next run's `not_found` assertions.                                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
trait CreatesUploadFixtures
{
    /** @var list<string> */
    private array $temporaryFixturePaths = [];

    /** @var list<string> */
    private array $writtenUploadPaths = [];

    // ── RAW BYTES ─────────────────────────────────────────────────────────

    /** A real, decodable 1x1 PNG. finfo: image/png. */
    protected function pngBytes(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );
    }

    /** A real, decodable 1x1 JPEG. finfo: image/jpeg. */
    protected function jpegBytes(): string
    {
        return (string) base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwc'
            . 'KDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAA'
            . 'AAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
            true
        );
    }

    /** A real, decodable 1x1 WebP. finfo: image/webp. */
    protected function webpBytes(): string
    {
        return (string) base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAQAcJaQAA3AA/v3AgAA=', true);
    }

    /** A minimal but structurally real PDF. finfo: application/pdf. */
    protected function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    /**
     * An SVG carrying a <script> — the exact stored-XSS payload
     * App\Enums\UploadKind bans by omitting image/svg+xml from every kind.
     */
    protected function svgBytes(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1">'
            . '<script>alert(document.domain)</script></svg>';
    }

    /** A PHP webshell. finfo: text/x-php. */
    protected function phpScriptBytes(): string
    {
        return "<?php system(\$_GET['cmd']); ?>\n";
    }

    /**
     * A real OOXML package: a store-only ZIP with the parts finfo reads to
     * distinguish a .docx from a plain archive.
     *
     * Built by hand because ext-zip is NOT loaded in this environment
     * (measured: `class_exists('ZipArchive')` is false), and because a
     * hand-built package is the only way to be certain the bytes are what the
     * test claims rather than whatever a library felt like emitting.
     */
    protected function docxBytes(): string
    {
        return $this->zipBytes([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-'
                . 'officedocument.wordprocessingml.document.main+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/'
                . 'relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                . '<w:body><w:p><w:r><w:t>hello</w:t></w:r></w:p></w:body></w:document>',
        ]);
    }

    /** The presentation twin of docxBytes(). */
    protected function pptxBytes(): string
    {
        return $this->zipBytes([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-'
                . 'officedocument.presentationml.presentation.main+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/'
                . 'relationships/officeDocument" Target="ppt/presentation.xml"/></Relationships>',
            'ppt/presentation.xml' => '<?xml version="1.0"?><p:presentation xmlns:p="http://schemas.'
                . 'openxmlformats.org/presentationml/2006/main"/>',
        ]);
    }

    /**
     * A store-only ZIP, assembled from the spec: local file headers, then the
     * central directory, then the end-of-central-directory record.
     *
     * @param  array<string, string>  $entries  path => contents
     */
    protected function zipBytes(array $entries): string
    {
        $local = '';
        $central = '';
        $offset = 0;

        foreach ($entries as $name => $data) {
            $crc = crc32($data);
            $length = strlen($data);

            $header = "PK\x03\x04" . pack('vvvvv', 20, 0, 0, 0, 0)
                . pack('VVV', $crc, $length, $length)
                . pack('vv', strlen($name), 0) . $name . $data;

            $central .= "PK\x01\x02" . pack('vvvvvv', 20, 20, 0, 0, 0, 0)
                . pack('VVV', $crc, $length, $length)
                . pack('vvvvv', strlen($name), 0, 0, 0, 0)
                . pack('VV', 0, $offset) . $name;

            $offset += strlen($header);
            $local .= $header;
        }

        return $local . $central . "PK\x05\x06" . pack('vvvv', 0, 0, count($entries), count($entries))
            . pack('VV', strlen($central), strlen($local)) . pack('v', 0);
    }

    // ── UPLOADED FILES ────────────────────────────────────────────────────

    /**
     * An UploadedFile whose CONTENT and DECLARED NAME are independent.
     *
     * This is the adversarial primitive: `uploadOf($this->pngBytes(),
     * 'shell.php')` is a genuine image with a webshell's filename, and
     * `uploadOf($this->phpScriptBytes(), 'photo.jpg')` is the reverse. Neither
     * can be expressed with UploadedFile::fake().
     */
    protected function uploadOf(string $contents, string $clientName, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        /*
         * ┌── NOT `tempnam(sys_get_temp_dir(), …)`, AND THAT IS MEASURED. ───┐
         * │ On Windows tempnam() honours only the first THREE characters of  │
         * │ a prefix and recycles names aggressively from a directory the    │
         * │ whole machine shares. Running this suite in file order produced  │
         * │ an intermittent                                                   │
         * │   finfo::file(…\sanXXXX.tmp): Failed to open stream: Invalid     │
         * │   argument                                                        │
         * │ i.e. a 500 where a 422 was owed, on whichever test happened to   │
         * │ draw a name a previous test had just unlinked. The bug was in    │
         * │ the FIXTURE, not the endpoint — but an intermittent 500 in a     │
         * │ security test is worse than no test, because it teaches whoever  │
         * │ sees it to re-run rather than to read.                            │
         * │                                                                   │
         * │ A per-test unique name inside the application's own testing      │
         * │ scratch directory removes the sharing entirely.                   │
         * └───────────────────────────────────────────────────────────────────┘
         */
        $directory = storage_path('framework/testing/uploads');

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory . DIRECTORY_SEPARATOR . 'fixture-' . bin2hex(random_bytes(12));

        $written = file_put_contents($path, $contents);
        $this->temporaryFixturePaths[] = $path;

        // A short write is a different fault from an unreadable one, and
        // distinguishing them is what turns "flaky" into a diagnosis.
        self::assertSame(
            \strlen($contents),
            $written === false ? -1 : $written,
            "Failed to write the upload fixture at {$path} — the test machine, not the code under test."
        );

        $this->assertFixtureIsSniffable($path);

        return new UploadedFile($path, $clientName, null, $error, true);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE FIXTURE GUARANTEES ITS OWN PRECONDITION: THE FILE IT HANDS THE   ║
     * ║ ENDPOINT MUST BE READABLE BY finfo BEFORE THE REQUEST IS MADE.       ║
     * ║                                                                      ║
     * ║ SECOND TIME THIS EXACT WARNING HAS MASQUERADED AS AN ENDPOINT BUG —  ║
     * ║ see the uploadOf() docblock above for the first (tempnam name        ║
     * ║ recycling). Stage 10 hit it again from a different cause: four       ║
     * ║ security tests failed in one full-suite run with                     ║
     * ║   finfo::file(…): Failed to open stream: Invalid argument            ║
     * ║ producing a 500 where a 422 was owed, then all four passed when the  ║
     * ║ class was run alone, and a tight 80-iteration loop reproduced        ║
     * ║ nothing. The machine had just rebooted.                              ║
     * ║                                                                      ║
     * ║ The mechanism is a file-system filter driver briefly holding a       ║
     * ║ handle on a file written microseconds earlier — most visibly one     ║
     * ║ whose bytes are a webshell, which is precisely what these security   ║
     * ║ fixtures contain. It is a property of the MACHINE, not of the code   ║
     * ║ under test.                                                          ║
     * ║                                                                      ║
     * ║ WHY A RETRY IS NOT "PAPERING OVER": the production path was fixed    ║
     * ║ separately and independently — StoreUpload::sniff() now rejects an   ║
     * ║ unreadable file with a clean 422 plus its own log reason instead of  ║
     * ║ letting the warning escalate. THIS half is about the fixture keeping ║
     * ║ its promise: a test that means to assert "a webshell is rejected"    ║
     * ║ must actually deliver a readable webshell, or it asserts nothing.    ║
     * ║ Nothing here is retried in production and no assertion is loosened.  ║
     * ║                                                                      ║
     * ║ It FAILS LOUDLY rather than skipping if the file never becomes       ║
     * ║ readable — a permanently unreadable fixture is a real problem and    ║
     * ║ must not be silently tolerated.                                      ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    private function assertFixtureIsSniffable(string $path): void
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $attempts = 0;

        // ~3s total. Generous on purpose: the budget is only ever consumed on
        // a machine that is already misbehaving, and every millisecond spent
        // here is a millisecond NOT spent producing a false security failure.
        for (; $attempts < 120; $attempts++) {
            clearstatcache(true, $path);

            if (@$finfo->file($path) !== false) {
                return;
            }

            usleep(25_000);
        }

        /*
         * FORENSIC ON FAILURE. The first time this fired, the only evidence
         * was "500 instead of 422" three call-frames away, which cost a long
         * investigation. Everything needed to tell an environment fault from a
         * real one is captured here, at the moment it happens.
         */
        clearstatcache(true, $path);

        $error = error_get_last();

        self::fail(sprintf(
            "Upload fixture never became readable by finfo after %d attempts (~3s).\n"
            . "  path        : %s\n"
            . "  file_exists : %s\n"
            . "  is_file     : %s\n"
            . "  is_readable : %s\n"
            . "  filesize    : %s\n"
            . "  dir_exists  : %s\n"
            . "  dir_writable: %s\n"
            . "  last error  : %s\n"
            . "  free space  : %s bytes\n"
            . "This is an ENVIRONMENT fault (typically a file-system filter driver holding a handle "
            . "on a just-written file), NOT a failure of the code under test. It is reported as a "
            . "failure rather than skipped because a fixture that cannot be read asserts nothing — "
            . "and the production path it would have exercised is covered independently by "
            . 'UploadEndpointTest::test_an_undeterminable_content_type_is_rejected_and_never_escalates.',
            $attempts,
            $path,
            var_export(file_exists($path), true),
            var_export(is_file($path), true),
            var_export(is_readable($path), true),
            var_export(@filesize($path), true),
            var_export(is_dir(\dirname($path)), true),
            var_export(is_writable(\dirname($path)), true),
            $error === null ? '(none)' : ($error['message'] ?? '(no message)'),
            var_export(@disk_free_space(\dirname($path)), true),
        ));
    }

    /** A file of exactly `$bytes` length, declared with `$clientName`. */
    protected function uploadOfSize(int $bytes, string $clientName): UploadedFile
    {
        // A real PNG header first, so a size rejection is unambiguously about
        // SIZE — if the content half ever ran first, this would still pass it.
        $png = $this->pngBytes();
        $padding = max(0, $bytes - strlen($png));

        return $this->uploadOf(substr($png . str_repeat("\0", $padding), 0, $bytes), $clientName);
    }

    // ── STORAGE BOOKKEEPING ───────────────────────────────────────────────

    /** The absolute path of a category directory under the uploads root. */
    protected function uploadsPath(string $relative = ''): string
    {
        $root = (string) config('upload.storage_path');

        return $relative === '' ? $root : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /** Every file currently under the uploads root, as relative paths. @return list<string> */
    protected function currentUploadInventory(): array
    {
        $root = $this->uploadsPath();

        if (!is_dir($root)) {
            return [];
        }

        $found = [];

        foreach ((array) glob($root . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*') as $path) {
            if (is_string($path) && is_file($path)) {
                $found[] = str_replace('\\', '/', substr($path, strlen($root) + 1));
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Write bytes straight onto the uploads disk, BYPASSING every validation
     * rule — the only way to test that the READ path degrades safely on
     * content the WRITE path would never have accepted.
     *
     * Returns the storage-relative reference, in the exact `uploads/…` shape
     * StoreUpload would have returned.
     */
    protected function plantUploadedFile(string $category, string $storedName, string $contents): string
    {
        $directory = $this->uploadsPath($category);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($directory . DIRECTORY_SEPARATOR . $storedName, $contents);
        $this->writtenUploadPaths[] = $category . '/' . $storedName;

        return 'uploads/' . $category . '/' . $storedName;
    }

    /** Remember a path the endpoint wrote, so tearDown can remove it. */
    protected function rememberUploadedPath(string $reference): string
    {
        $this->writtenUploadPaths[] = ltrim(str_replace('uploads/', '', $reference), '/');

        return $reference;
    }

    /**
     * Remove every temp fixture AND every file this test caused to be written
     * into storage/uploads. Called from the test class's tearDown().
     */
    protected function forgetUploadedFiles(): void
    {
        foreach ($this->temporaryFixturePaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        foreach ($this->writtenUploadPaths as $relative) {
            $path = $this->uploadsPath($relative);

            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->temporaryFixturePaths = [];
        $this->writtenUploadPaths = [];
    }

    /**
     * Delete every file the uploads root gained during this test, whether or
     * not the test remembered to record it.
     *
     * @param  list<string>  $before  the inventory taken in setUp()
     */
    protected function pruneUploadsAddedSince(array $before): void
    {
        foreach (array_diff($this->currentUploadInventory(), $before) as $relative) {
            $path = $this->uploadsPath($relative);

            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
