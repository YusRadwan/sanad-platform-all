<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Enums\UploadKind;
use App\Support\StoredUploadPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use ReflectionMethod;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\Concerns\CreatesUploadFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE PROPERTIES THE WHOLE FILES DESIGN RESTS ON, ASSERTED DIRECTLY       ║
 * ║ RATHER THAN INFERRED FROM THE ENDPOINTS BEHAVING WELL.                  ║
 * ║                                                                          ║
 * ║  1. storage/uploads is NOT reachable by the web server.                  ║
 * ║  2. `php artisan storage:link` has never been run and must never be.     ║
 * ║  3. the WRITE root and the READ root are the same directory.             ║
 * ║  4. there is exactly ONE implementation of path safety (decision 6.10).  ║
 * ║  5. the two dead config keys were not ported (decision 9.6).             ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Every one of these is the kind of fact that stays true for years and then
 * quietly stops being true in a deployment commit nobody reviewed as a
 * security change. That is exactly what a test is for.
 */
final class StorageIsolationTest extends TestCase
{
    use CreatesAuthFixtures;
    use CreatesUploadFixtures;
    use RefreshDatabase;

    // ══ 1 & 2. THE SYMLINK PROHIBITION ════════════════════════════════════

    /**
     * ┌── `public/storage` MUST NOT EXIST. ─────────────────────────────────┐
     * │ The entire threat model for private files is that storage/ sits     │
     * │ OUTSIDE the document root (public/ is the root), so Apache/Nginx    │
     * │ has no URL that maps there and every read is forced through PHP —   │
     * │ which is what makes an authorization decision ALWAYS precede a      │
     * │ read.                                                               │
     * │                                                                      │
     * │ `php artisan storage:link` is the one command that erodes it. It    │
     * │ does not expose the uploads disk by itself, but it establishes the  │
     * │ pattern and creates the `links` habit; the next "helpful" entry     │
     * │ makes every note attachment a static file with no gate at all.      │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_the_public_storage_symlink_does_not_exist(): void
    {
        $this->assertFalse(
            File::exists(public_path('storage')),
            'public/storage exists. Someone ran `php artisan storage:link`. Remove the link: with it '
            . 'in place the web server can serve files from storage/ without PHP, and therefore '
            . 'without any authorization check.'
        );

        $this->assertFalse(is_link(public_path('storage')));
    }

    public function test_no_configured_link_points_anywhere_near_the_uploads_root(): void
    {
        foreach ((array) config('filesystems.links') as $link => $target) {
            $this->assertStringNotContainsString(
                'uploads',
                str_replace('\\', '/', (string) $target),
                "filesystems.links maps {$link} -> {$target}. A symlink into storage/uploads makes "
                . 'every private note attachment a static file served with no gate.'
            );
        }
    }

    public function test_the_document_root_contains_only_the_front_controller_assets(): void
    {
        $entries = array_values(array_diff(scandir(public_path()) ?: [], ['.', '..']));

        sort($entries);

        $this->assertSame(
            ['.htaccess', 'favicon.ico', 'index.php', 'robots.txt'],
            $entries,
            'Anything else under public/ is served by the web server with no PHP involvement.'
        );
    }

    /** Defence in depth for a deployment that points the root one level too high. */
    public function test_the_uploads_directory_carries_a_defensive_htaccess(): void
    {
        $htaccess = $this->uploadsPath('.htaccess');

        $this->assertFileExists(
            $htaccess,
            'Kept as defence in depth for the one scenario that matters: a deployment whose document '
            . 'root is backend-v2/ instead of backend-v2/public.'
        );

        $contents = (string) file_get_contents($htaccess);

        $this->assertStringContainsString('php_flag engine off', $contents);
        $this->assertStringContainsString('Require all denied', $contents);
        $this->assertStringContainsString('nosniff', $contents);

        foreach (['php', 'phtml', 'svg', 'exe'] as $extension) {
            $this->assertStringContainsString($extension, $contents);
        }
    }

    // ══ 3. ONE ROOT, TWO CONSUMERS ════════════════════════════════════════

    /**
     * The disk WRITES and StoredUploadPath READS. If they ever pointed at
     * different directories, every freshly uploaded file would fail its own
     * containment check and download as a 404 — a total outage that no unit
     * test of either half would catch.
     */
    public function test_the_uploads_disk_root_and_the_configured_storage_path_are_the_same_directory(): void
    {
        $diskRoot = (string) config('filesystems.disks.uploads.root');
        $configured = (string) config('upload.storage_path');

        $this->assertSame(
            realpath($configured),
            realpath($diskRoot),
            'config/filesystems.php `uploads.root` and config/upload.php `storage_path` must resolve '
            . 'to one directory. They are deliberately spelled with the SAME expression.'
        );

        $this->assertSame(realpath(storage_path('uploads')), realpath($diskRoot));

        $this->assertSame(
            realpath($diskRoot),
            realpath(StoredUploadPath::baseDirectory() ?? ''),
            'The containment check must be anchored on the same root the writer uses.'
        );
    }

    public function test_the_uploads_disk_is_private_and_non_throwing(): void
    {
        $this->assertSame('local', config('filesystems.disks.uploads.driver'));
        $this->assertSame('private', config('filesystems.disks.uploads.visibility'));
        $this->assertFalse(config('filesystems.disks.uploads.throw'));
    }

    /** The root is NOT inside public/, asserted on resolved paths. */
    public function test_the_uploads_root_is_outside_the_document_root(): void
    {
        $root = str_replace('\\', '/', (string) realpath((string) config('upload.storage_path')));
        $public = str_replace('\\', '/', (string) realpath(public_path()));

        $this->assertNotSame('', $root);
        $this->assertStringStartsNotWith(
            rtrim($public, '/') . '/',
            rtrim($root, '/') . '/',
            'The uploads root is inside the document root. Every uploaded file is now a static asset.'
        );
    }

    public function test_the_default_disk_is_not_the_uploads_disk(): void
    {
        $this->assertNotSame(
            'uploads',
            config('filesystems.default'),
            'A stray Storage::put() elsewhere in the application must not land in the uploads root.'
        );
    }

    /**
     * Laravel's `serve` route on the `local` disk would be one endpoint with
     * no authorization at all. It was switched off in Stage 0 for exactly the
     * reason this stage exists; re-asserted here so the two facts sit together.
     */
    public function test_no_disk_serves_itself_over_http(): void
    {
        foreach ((array) config('filesystems.disks') as $name => $disk) {
            $this->assertNotTrue(
                $disk['serve'] ?? false,
                "Disk '{$name}' has serve=true, which registers a GET route outside the api "
                . 'middleware group with no envelope, no headers and no authorization.'
            );
        }
    }

    // ══ 4. ONE IMPLEMENTATION OF PATH SAFETY (decision 6.10) ══════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ STAGE 6 BUILT `StoredUploadPath::resolve()` AND RECORDED A FORWARD   ║
     * ║ DEPENDENCY: STAGE 9 MUST **EXTEND** IT, NOT FORK IT. THIS ASSERTS    ║
     * ║ IDENTITY, NOT RESEMBLANCE.                                            ║
     * ║                                                                      ║
     * ║ "Looks similar" is how the second copy ends up missing a case, and   ║
     * ║ the case in question is traversal into another tenant's documents.   ║
     * ║ So the claim is made with REFLECTION: the containment primitive is    ║
     * ║ declared exactly once and imported, never re-implemented.             ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_stage_6_and_stage_9_call_the_same_path_safety_implementation(): void
    {
        $fileControllerSource = (string) file_get_contents(
            app_path('Http/Controllers/FileController.php')
        );

        $this->assertStringContainsString(
            'use App\Support\StoredUploadPath;',
            $fileControllerSource,
            'Every consumer must import the SAME class — not a copy, not a subclass.'
        );

        // And the containment primitive itself is declared exactly once.
        $this->assertSame(
            [StoredUploadPath::class],
            $this->classesDeclaringMethod('isInside'),
            'A second isInside() anywhere in app/ is a second answer to "is this path contained", '
            . 'and decision 6.10 exists because the two would drift.'
        );
    }

    public function test_resolve_keeps_the_signature_stage_6_depends_on(): void
    {
        $method = new ReflectionMethod(StoredUploadPath::class, 'resolve');

        $this->assertTrue($method->isStatic());
        $this->assertSame('?string', (string) $method->getReturnType());
        $this->assertSame(1, $method->getNumberOfParameters());
        $this->assertSame(
            'string',
            (string) $method->getParameters()[0]->getType(),
            'Callers pass a raw string to resolve($value) and branch on null. '
            . 'Changing either half breaks the file routes silently.'
        );
    }

    /**
     * The category/extension table is DERIVED from UploadKind now, so the
     * upload allow-list and the resolver allow-list cannot disagree. Proven
     * behaviourally: an extension a kind does not allow never resolves under
     * that kind's directory, whatever bytes are on disk.
     */
    public function test_the_resolver_allow_list_is_the_upload_allow_list(): void
    {
        $inventoryBefore = $this->currentUploadInventory();

        try {
            foreach (UploadKind::cases() as $kind) {
                $id = '00000000-0000-4000-8000-00000000000' . array_search($kind, UploadKind::cases(), true);

                foreach (['png', 'pdf', 'exe', 'php'] as $extension) {
                    $this->plantUploadedFile($kind->directory(), "{$id}.{$extension}", 'x');

                    $reference = "uploads/{$kind->directory()}/{$id}.{$extension}";
                    $allowed = in_array($extension, $kind->extensions(), true);

                    $this->assertSame(
                        $allowed,
                        StoredUploadPath::resolve($reference) !== null,
                        "'{$reference}' resolved=" . var_export(!$allowed, true)
                        . " but the kind's allow-list says otherwise."
                    );
                }
            }
        } finally {
            $this->pruneUploadsAddedSince($inventoryBefore);
            $this->forgetUploadedFiles();
        }
    }

    public function test_the_two_doors_disagree_about_documents_and_agree_about_everything_else(): void
    {
        $this->assertSame(
            ['avatars', 'banners', 'images', 'profiles', 'documents'],
            UploadKind::directories()
        );

        $this->assertSame(
            ['documents'],
            array_values(array_diff(UploadKind::directories(), UploadKind::publicDirectories())),
            'Exactly one private category. The PUBLIC resolver must refuse it and the DATABASE-sourced '
            . 'resolver must accept it — that difference IS the two doors.'
        );
    }

    // ══ 5. THE DEAD CONFIG KEYS (decision 9.6) ════════════════════════════

    /**
     * ┌── backend/config/upload.php's `max_size_mb` AND `allowed_mime` WERE ─┐
     * │ NOT PORTED, ON PURPOSE.                                              │
     * │                                                                      │
     * │ Verified by exhaustive grep across backend/app, backend/routes and   │
     * │ backend/bootstrap: nothing reads either one. The real (type) caps    │
     * │ are per-kind (UploadKind::extensionMimeMap), because a single        │
     * │ global MIME list cannot say "pdf is fine for a note attachment but   │
     * │ not for an avatar". There is no size cap anywhere any more — open    │
     * │ decision 10.22 removed UploadKind::maxBytes() itself, not just this  │
     * │ dead config key, per explicit user request.                          │
     * │                                                                      │
     * │ Keeping them "so existing env vars do not error" is an OPERATIONAL   │
     * │ TRAP: an operator edits UPLOAD_MAX_SIZE_MB, sees a config key change │
     * │ shape, and believes the cap moved. Absent keys are honest.           │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_the_two_dead_upload_config_keys_were_not_ported(): void
    {
        $this->assertSame(
            ['storage_path'],
            array_keys((array) config('upload')),
            'config/upload.php holds exactly one key, and it is the only one anything reads.'
        );

        $this->assertNull(config('upload.max_size_mb'));
        $this->assertNull(config('upload.allowed_mime'));
    }

    public function test_no_code_path_reads_the_removed_env_vars(): void
    {
        foreach (['UPLOAD_MAX_SIZE_MB', 'UPLOAD_ALLOWED_MIME'] as $variable) {
            $this->assertSame(
                [],
                $this->filesContaining($variable),
                "{$variable} is referenced somewhere. It is fully inert and must stay unreferenced, "
                . 'or it becomes a setting that looks load-bearing and is not.'
            );
        }
    }


    // ══ THE DISK ITSELF ═══════════════════════════════════════════════════

    public function test_the_uploads_disk_writes_where_the_resolver_reads(): void
    {
        $inventoryBefore = $this->currentUploadInventory();
        $name = '00000000-0000-4000-8000-0000000000ff.png';

        try {
            Storage::disk('uploads')->put('images/' . $name, $this->pngBytes());

            $resolved = StoredUploadPath::resolve('uploads/images/' . $name);

            $this->assertNotNull($resolved, 'A file the disk just wrote must resolve.');
            $this->assertSame($this->pngBytes(), (string) file_get_contents((string) $resolved));
        } finally {
            $this->pruneUploadsAddedSince($inventoryBefore);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Classes under app/ that DECLARE a method of this name.
     *
     * @return list<string>
     */
    private function classesDeclaringMethod(string $method): array
    {
        $found = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $source) !== 1) {
                continue;
            }

            if (preg_match('/namespace\s+([^;]+);/', $source, $namespace) !== 1) {
                continue;
            }

            $found[] = trim($namespace[1]) . '\\' . basename($file, '.php');
        }

        sort($found);

        return $found;
    }

    /** @return list<string> paths under app/, routes/ and bootstrap/ containing $needle */
    private function filesContaining(string $needle): array
    {
        $found = [];

        foreach ([app_path(), base_path('routes'), base_path('bootstrap'), base_path('config')] as $directory) {
            foreach ($this->phpFilesUnder($directory) as $file) {
                if (str_contains((string) file_get_contents($file), $needle)) {
                    $found[] = $file;
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
