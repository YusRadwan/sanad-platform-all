<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Enums\UploadKind;
use App\Support\SecurityLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/uploads — the ONLY write path into storage/uploads.
 *
 * Port of backend/app/Services/UploadService::store().
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ VALIDATION IS **TWO INDEPENDENT HALVES**, AND LARAVEL'S BUILT-IN RULES  ║
 * ║ IMPLEMENT ONLY ONE OF THEM. THIS IS NOT A REJECTION OF THE FRAMEWORK;   ║
 * ║ IT IS WHAT THE FRAMEWORK'S RULES ACTUALLY DO, MEASURED.                 ║
 * ║                                                                          ║
 * ║   `mimes:jpg,png`  — resolves the guessed extension FROM THE SNIFFED    ║
 * ║                      MIME and compares that. It never looks at the       ║
 * ║                      extension the CLIENT declared.                      ║
 * ║   `mimetypes:...`  — compares the sniffed MIME only. Same blind spot.    ║
 * ║                                                                          ║
 * ║ Neither expresses "the DECLARED extension is itself in this kind's       ║
 * ║ allow-list". So a genuine PNG uploaded as `shell.php` passes `mimes:png` ║
 * ║ — the bytes are a PNG — and would be written to disk under a name ending ║
 * ║ in `.php`. On a misconfigured deployment that is a webshell, delivered   ║
 * ║ by an endpoint that believed it had validated the upload.                ║
 * ║                                                                          ║
 * ║ Both halves are therefore written out explicitly below, against ONE map  ║
 * ║ (UploadKind::extensionMimeMap()): the declared extension must be a KEY,  ║
 * ║ and the sniffed mime must be in that key's list.                         ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── THE TWO HALVES RETURN THE **SAME** MESSAGE AND LOG **DIFFERENT** ──────┐
 * │ REASONS. THE ASYMMETRY IS THE POINT.                                     │
 * │                                                                          │
 * │ Both answer the client 'صيغة الملف غير مدعومة'. Telling an uploader     │
 * │ WHICH half failed hands them a free oracle for mapping the allow-list —  │
 * │ "extension rejected" vs "content rejected" is exactly the signal needed  │
 * │ to search for a pair that satisfies both. The operator gets the full     │
 * │ story instead, in the security log, under `extension_not_allowed` and    │
 * │ `mime_extension_mismatch`. Ported deliberately from backend/.            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── CHECK ORDER IS backend/'s ORDER AND IS LOAD-BEARING. ──────────────────┐
 * │   unsupported_kind → php_upload_error_{N} → not_an_uploaded_file →      │
 * │   size_out_of_range → extension_not_allowed → mime_extension_mismatch    │
 * │   → move_failed                                                          │
 * │                                                                          │
 * │ Kind first because every later rule is READ FROM the kind. Size before   │
 * │ the mime sniff because sniffing means reading bytes off disk, and a      │
 * │ 500 MB upload must be refused before that cost is paid — the same        │
 * │ reason the rate limiter runs before `auth` on this route.                │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * EVERY REJECTION IS LOGGED WITH ITS OWN REASON BEFORE IT IS THROWN. An
 * attacker probing MIME filtering is visible only if the REJECTIONS are
 * recorded, not just the successes. Only the client-declared name, client
 * mime and size are recorded — never file content, never the absolute server
 * path.
 */
final class StoreUpload
{
    /** The one message BOTH validation halves answer with. */
    public const UNSUPPORTED_FORMAT = 'صيغة الملف غير مدعومة';

    /**
     * @return array{file_name:string, stored_name:string, path:string, url:string|null, mime:string, size:int}
     */
    public function handle(mixed $type, UploadedFile $file): array
    {
        $attempt = [
            'kind' => is_string($type) ? substr(str_replace(["\r", "\n"], ' ', $type), 0, 40) : '(none)',
            'file_name' => substr(str_replace(["\r", "\n"], ' ', (string) $file->getClientOriginalName()), 0, 120),
            'client_mime' => substr(str_replace(["\r", "\n"], ' ', (string) $file->getClientMimeType()), 0, 120),
            'size' => (int) $file->getSize(),
        ];

        // ── 1. the kind ────────────────────────────────────────────────────
        $kind = UploadKind::fromRequestType($type);

        if ($kind === null) {
            $this->rejected($attempt, 'unsupported_kind');

            throw ValidationException::withMessages(['type' => ['نوع الرفع غير مدعوم']]);
        }

        // ── 2. PHP's own upload machinery ──────────────────────────────────
        $error = $file->getError();

        if ($error !== UPLOAD_ERR_OK) {
            $this->rejected($attempt, 'php_upload_error_' . $error);

            throw ValidationException::withMessages(['file' => ['فشل رفع الملف']]);
        }

        /*
         * isValid() is `error === UPLOAD_ERR_OK && is_uploaded_file(path)`,
         * except under UploadedFile::fake() where the is_uploaded_file half is
         * skipped. Calling is_uploaded_file() DIRECTLY, as backend/ does,
         * would therefore be a check no test could ever satisfy and every test
         * fixture would fail here instead of exercising the real rules. The
         * production behaviour is identical; only the test path differs.
         */
        if (!$file->isValid()) {
            $this->rejected($attempt, 'not_an_uploaded_file');

            throw ValidationException::withMessages(['file' => ['ملف غير صالح']]);
        }

        // ── 3. size — the per-kind cap is GONE, open decision 10.22 ─────────
        // Explicit user request: no image/document upload limit at all. Only
        // the zero-byte probe guard remains — a 0-byte "file" cannot be a
        // real image or document regardless of any size policy, so refusing
        // it isn't a size RESTRICTION, it's rejecting something that isn't a
        // file. The only real ceiling left is PHP's own `upload_max_filesize`/
        // `post_max_size` ini limits, which this application does not control
        // and are far above anything an instructor/admin would plausibly
        // upload here (2 GB in this environment).
        $size = (int) $file->getSize();

        if ($size <= 0) {
            $this->rejected($attempt, 'size_out_of_range');

            throw ValidationException::withMessages([
                'file' => ['ملف غير صالح'],
            ]);
        }

        $map = $kind->extensionMimeMap();

        // ── 4. HALF ONE: the DECLARED extension ────────────────────────────
        // A real PNG renamed to `.php` dies HERE, before anything is written,
        // so a webshell filename never reaches the filesystem even though its
        // bytes are a legitimate image.
        $declaredExtension = strtolower($file->getClientOriginalExtension());

        if (!array_key_exists($declaredExtension, $map)) {
            $this->rejected($attempt, 'extension_not_allowed');

            throw ValidationException::withMessages(['file' => [self::UNSUPPORTED_FORMAT]]);
        }

        // ── 5. HALF TWO: the REAL content type ─────────────────────────────
        // A PHP script renamed to `.jpg` dies here.
        //
        // getMimeType() is Symfony's finfo-backed sniff. VERIFIED EMPIRICALLY
        // in this environment (PHP 8.3.30) against finfo_file(FILEINFO_MIME_TYPE)
        // for jpg/png/webp/pdf/docx/pptx/svg/php/zip/random-binary: identical
        // answers on all eleven. See UploadValidationTest.
        $actualMime = $this->sniff($file);

        if (!in_array($actualMime, $map[$declaredExtension], true)) {
            $this->rejected($attempt + ['actual_mime' => $actualMime], 'mime_extension_mismatch');

            throw ValidationException::withMessages(['file' => [self::UNSUPPORTED_FORMAT]]);
        }

        // ── 6. store ───────────────────────────────────────────────────────
        /*
         * THE STORED NAME IS ENTIRELY SERVER-GENERATED: a uuid plus the
         * now-verified extension. NO PART of the client's original filename
         * survives into the name or the path — which is exactly what makes
         * StoredUploadPath's strict "<uuid>.<ext>" pattern SUFFICIENT to rule
         * out traversal later. If any client bytes could leak into the name,
         * that pattern would be a filter rather than a proof.
         */
        $storedName = Str::uuid()->toString() . '.' . $declaredExtension;

        $stored = Storage::disk('uploads')->putFileAs($kind->directory(), $file, $storedName);

        if ($stored === false) {
            $this->rejected($attempt + ['actual_mime' => $actualMime], 'move_failed');

            throw ValidationException::withMessages(['file' => ['تعذر حفظ الملف']]);
        }

        SecurityLog::info('Upload accepted', $attempt + [
            'event' => 'upload.accepted',
            'actual_mime' => $actualMime,
            // Category-scoped identifier, not the absolute server path.
            'stored_name' => $kind->directory() . '/' . $storedName,
        ]);

        return [
            'file_name' => (string) $file->getClientOriginalName(),
            'stored_name' => $storedName,
            /*
             * STORAGE-RELATIVE IDENTIFIER, AND ITS FORMAT IS A DATA CONTRACT.
             * `notes.file_path` and `assignment_submissions.file_path` store
             * exactly this string; StoredUploadPath::resolve() parses exactly
             * this string. Changing the separator, the prefix or the casing
             * breaks every row already written. It is NOT a document-root
             * relative URL and is not fetchable.
             */
            'path' => 'uploads/' . $kind->directory() . '/' . $storedName,
            'url' => $this->urlFor($kind, $storedName),
            'mime' => $actualMime,
            'size' => $size,
        ];
    }

    /**
     * ┌── PRIVATE FILES GET **NO ADDRESSABLE URL AT ALL** — null, not a ─────┐
     * │ guarded one. A note's document is reached only through               │
     * │ GET /content-items/{id}/file, which runs the course-entitlement      │
     * │ gate first. There is no "private file by name" endpoint to point at. │
     * │                                                                      │
     * │ THE PUBLIC URL CARRIES THE BARE UUID, WITHOUT THE EXTENSION, AND     │
     * │ THAT IS A SECURITY DECISION RATHER THAN A COSMETIC ONE.              │
     * │                                                                      │
     * │ The extension is redundant (the served Content-Type comes from a     │
     * │ finfo read of the real bytes) and actively harmful: a URL ending in  │
     * │ a file extension is claimed by the STATIC-FILE HANDLER ahead of the  │
     * │ PHP front controller — Nginx `try_files`, Apache `RewriteCond !-f`,  │
     * │ PHP's own dev server. On a deployment where the document root was    │
     * │ ever pointed one directory too high, that is direct serving with NO  │
     * │ authorization, which is the entire failure mode this design exists   │
     * │ to prevent. An extensionless URL cannot be mistaken for a file.      │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    private function urlFor(UploadKind $kind, string $storedName): ?string
    {
        if (!$kind->isPublic()) {
            return null;
        }

        return '/api/v1/files/' . $kind->directory() . '/' . pathinfo($storedName, PATHINFO_FILENAME);
    }

    /** @param array<string, mixed> $attempt */
    private function rejected(array $attempt, string $reason): void
    {
        SecurityLog::warning('Upload rejected', $attempt + [
            'event' => 'upload.rejected',
            'reason' => $reason,
        ]);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE CONTENT SNIFF MUST NEVER TURN A REJECTION INTO A **500**.        ║
     * ║ ADDED IN STAGE 10 AFTER FOUR INTERMITTENT FAILURES WERE TRACED HERE. ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * `UploadedFile::getMimeType()` already ANSWERS correctly when finfo
     * cannot read the file: Symfony returns `null`, `(string) null` is `''`,
     * `''` is in no kind's allow-list, and the request is a clean 422. The
     * null path was never the problem.
     *
     * The problem is that finfo also emits a PHP **warning** on that path
     * (`finfo::file(…): Failed to open stream: Invalid argument`), and
     * Laravel's error handler escalates every warning to an ErrorException.
     * So a transient failure to open a just-written file produced a **500
     * where a 422 was owed** — on the exact endpoint whose contract is that
     * the two rejection halves are indistinguishable.
     *
     * ┌── HOW IT WAS OBSERVED, BECAUSE "INTERMITTENT" IS NOT A DIAGNOSIS. ───┐
     * │ Four tests failed in one full-suite run — all four with webshell or  │
     * │ random-binary fixture bytes — and passed when the same class was run │
     * │ alone. Reproduced once directly with a booted application, then      │
     * │ 0/80 in a tight loop moments later. The machine had just rebooted.   │
     * │                                                                      │
     * │ The mechanism is a file-system filter (on Windows: the AV/indexer    │
     * │ class of driver) briefly holding a handle on a file that was written │
     * │ microseconds earlier — most visibly one whose bytes are a webshell.  │
     * │ tests/Concerns/CreatesUploadFixtures already records the SAME error  │
     * │ string from a different cause, so this is the second time this one   │
     * │ warning has masqueraded as an endpoint bug.                          │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ┌── WHY SUPPRESSION IS CORRECT HERE AND NOT LAZINESS. ─────────────────┐
     * │ NOTHING IS LOOSENED. An unreadable file still fails the allow-list   │
     * │ check and is still rejected with the identical message; the only     │
     * │ change is the STATUS CODE it is rejected with. The distinct internal │
     * │ reason ('mime_unreadable') goes to the security log, exactly as      │
     * │ every other rejection reason does — so an operator seeing a real     │
     * │ finfo misconfiguration (the extension genuinely absent, say) sees a  │
     * │ rising counter rather than a silent success.                         │
     * │                                                                      │
     * │ The alternative — leaving the warning to escalate — means a          │
     * │ legitimate user's valid PNG can 500 on a busy machine. That is a     │
     * │ worse contract than a 422 on a file we could not read.               │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    private function sniff(UploadedFile $file): string
    {
        /*
         * BOTH failure shapes are caught, because Symfony has two.
         *
         *   - finfo could not READ a file that exists  -> null + a PHP warning
         *     (the warning is what Laravel escalates to a 500)
         *   - the path is not a readable file at all   -> a thrown
         *     InvalidArgumentException from FileinfoMimeTypeGuesser
         *
         * Catching only the first would leave the second producing exactly the
         * 500 this method exists to prevent. The RULE is one sentence: if the
         * content type cannot be determined, the upload is rejected — never
         * accepted, and never a 500.
         */
        try {
            $mime = @$file->getMimeType();
        } catch (\Throwable $e) {
            $mime = null;
        }

        if ($mime === null || $mime === '') {
            SecurityLog::warning('Upload content type could not be determined', [
                'event' => 'upload.rejected',
                'reason' => 'mime_unreadable',
            ]);

            return '';
        }

        return $mime;
    }
}
