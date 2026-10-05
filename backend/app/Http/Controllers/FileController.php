<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
use App\Policies\ContentItemPolicy;
use App\Support\ContentItemAccess;
use App\Support\FileStream;
use App\Support\SecurityLog;
use App\Support\StoredUploadPath;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The ONLY read path into storage/uploads. Freeze-table rows 72 and 73.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ storage/ IS OUTSIDE THE DOCUMENT ROOT (public/ IS THE ROOT), SO THE WEB ║
 * ║ SERVER CANNOT SERVE AN UPLOADED FILE AT ALL. EVERY BYTE LEAVES THROUGH  ║
 * ║ HERE — WHICH IS WHAT MAKES "AN AUTHORIZATION DECISION ALWAYS PRECEDES   ║
 * ║ THE READ" A STRUCTURAL FACT RATHER THAN A CONVENTION.                   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * The two entry points are deliberately DIFFERENT IN SHAPE, and the asymmetry
 * is the design:
 *
 *  1. showPublic() is addressed BY STORED FILENAME. The client controls both
 *     path segments, so traversal is the entire threat model and
 *     StoredUploadPath::resolvePublic() is the entire defence.
 *
 *  2. download() is addressed BY RESOURCE ID. The client never supplies a
 *     path, a filename or an extension — it supplies a content-item UUID, the
 *     entitlement gate runs, and the storage path comes out of the `notes`
 *     row. There is deliberately NO "serve an arbitrary private path" method
 *     for the same reason there is no private URL: it would be a door that
 *     only needs one bug to open.
 *
 * UNLIKE EVERY OTHER CONTROLLER these two emit RAW BYTES rather than the
 * {success,message,data} envelope — a binary body has no JSON representation.
 * The envelope is still used for every FAILURE (App\Exceptions\Handler renders
 * the NotFound/Authorization exception as normal JSON), so the error contract
 * is completely unchanged. See App\Http\Middleware\ForceJsonEnvelope, which
 * now returns a BinaryFileResponse untouched.
 */
final class FileController extends Controller
{
    use AuthorizesRequests;

    /**
     * GET /api/v1/files/{category}/{id} — row 72. PUBLIC, NO AUTH.
     *
     * ┌── UNAUTHENTICATED BY DESIGN, AND THAT IS NOT A GAP. ────────────────┐
     * │ These are teacher avatars/banners, course banners, question images  │
     * │ and video thumbnails, rendered on LOGGED-OUT marketing pages —      │
     * │ TeacherProfilePage, TeacherCard, CourseDetailsPage. Requiring auth  │
     * │ would black out the storefront for every visitor who has not signed │
     * │ up yet, which is the audience it exists for.                        │
     * │                                                                      │
     * │ What keeps it safe is that only the `visibility=public` categories  │
     * │ are reachable: `documents` is refused HERE even when the caller     │
     * │ knows a real document's uuid, because the category itself is not on │
     * │ UploadKind::publicDirectories().                                     │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ┌── EVERY REFUSAL IS A 404, WITH THE SAME BODY. ──────────────────────┐
     * │ 'category_not_public', 'invalid_file_id' and 'not_found' are three  │
     * │ different security events and ONE client answer. Distinguishing     │
     * │ them on the wire would tell a prober which of the three walls they  │
     * │ hit; the distinction is written to the security log instead, inside │
     * │ resolvePublic(), where an operator can watch it.                    │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function showPublic(string $category, string $id): BinaryFileResponse
    {
        $file = StoredUploadPath::resolvePublic($category, $id)
            ?? throw new NotFoundException(ContentItemPolicy::FILE_NOT_FOUND);

        // `inline` — images are meant to render. Safe SPECIFICALLY because
        // sniff() can only ever answer an inert raster type or
        // application/octet-stream here; see App\Support\FileStream.
        return FileStream::respond($file['path'], $file['mime'], $file['name'], public: true);
    }

    /**
     * GET /api/v1/content-items/{id}/file — row 73. AUTH + THE COURSE GATE.
     *
     * ┌── THE LADDER, IN backend/noteFileFor()'s ORDER. ────────────────────┐
     * │  1. the item must EXIST                     -> 404 'Content item…'  │
     * │  2. AUTHORIZATION (ContentItemPolicy::downloadFile)                 │
     * │       not a note / hidden & not permitted   -> 404 'File not found' │
     * │       not entitled                          -> 403 'You do not …'   │
     * │  3. the note row must HAVE a file_path      -> 404 'File not found' │
     * │  4. that path must resolve to real bytes    -> 404 'File not found' │
     * │                                                                      │
     * │ STEPS 3 AND 4 ARE EXISTENCE, NOT AUTHORIZATION, AND THAT IS WHY     │
     * │ THEY ARE HERE RATHER THAN IN THE POLICY. A note with no attachment  │
     * │ 404s for EVERYONE INCLUDING ITS OWNER — an answer that has nothing  │
     * │ to do with who is asking. Keeping the policy a pure function of     │
     * │ (user, resource) is what lets the nine-actor isolation matrix       │
     * │ assert on row 73 without seeding a file on disk. See the banner on  │
     * │ ContentItemPolicy::downloadFile().                                   │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * `$this->authorize()` IS USED HERE, AND IT IS ALLOWED because row 73 is
     * NOT a DEGRADE row: there is no reduced payload to fall back to. A file
     * is either handed over or it is not, so raising is the correct shape —
     * unlike rows 20/21/27, where authorize() would take the public course
     * page offline for guests.
     */
    public function download(string $id): BinaryFileResponse
    {
        $item = ContentItemAccess::findOrFail($id);

        $this->authorize('downloadFile', $item);

        // ── existence, step 3 ──────────────────────────────────────────────
        $note = $item->note;
        $storedPath = (string) ($note?->file_path ?? '');

        if (trim($storedPath) === '') {
            throw new NotFoundException(ContentItemPolicy::FILE_NOT_FOUND);
        }

        // ── existence, step 4 ──────────────────────────────────────────────
        // The stored value is RE-VALIDATED rather than trusted: it could have
        // been written by an older, laxer code path. Same implementation
        // Stage 6 uses for assignment submissions — decision 6.10.
        $file = StoredUploadPath::describe($storedPath)
            ?? throw new NotFoundException(ContentItemPolicy::FILE_NOT_FOUND);

        /*
         * A GRANT IS AN AUDIT EVENT. The refusals are logged by the resolver
         * and by App\Exceptions\Handler; without this line the log would
         * record only the attempts that failed, and "who downloaded the exam
         * revision notes" would be unanswerable. The content-item id and the
         * category-scoped stored name go in — never the absolute path.
         */
        SecurityLog::info('File access granted', [
            'event' => 'file.granted',
            'content_item_id' => $item->getKey(),
            'category' => 'documents',
            'stored_name' => $file['name'],
        ]);

        /*
         * ALWAYS `attachment`, never `inline`, whatever the type turns out to
         * be: even a PDF can carry active content in some viewers, and forcing
         * a download means the browser never executes anything in this origin.
         *
         * The download name is the note's ORIGINAL, user-supplied file_name —
         * hostile input, sanitised to ASCII by FileStream::asciiFilename().
         */
        return FileStream::respond(
            $file['path'],
            $file['mime'],
            (string) ($note?->file_name ?: 'document'),
            public: false,
        );
    }
}
