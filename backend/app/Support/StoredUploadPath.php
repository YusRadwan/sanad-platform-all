<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UploadKind;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ONE PLACE THAT ANSWERS "DOES THIS REFERENCE NAME BYTES THAT REALLY  ║
 * ║ EXIST, UNDER A CATEGORY WE KNOW, WITH A NAME THE SERVER ITSELF COULD    ║
 * ║ HAVE GENERATED?"                                                        ║
 * ║                                                                          ║
 * ║ Port of backend/FileService — resolveStoredPath(), assertSafeStoredName()║
 * ║ resolvePublic(), sniff() and isInside(), all four of them.               ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── STAGE 6 BUILT THE FIRST HALF; STAGE 9 ADDED THE SECOND **HERE**, IN ───┐
 * │ THIS FILE, RATHER THAN IN A SECOND CLASS. THAT WAS OPEN DECISION 6.10   │
 * │ AND IT IS NOW DISCHARGED.                                                │
 * │                                                                          │
 * │ Stage 6 needed resolve() because AssignmentService's submission-file     │
 * │ validation could not ship without proving that                           │
 * │ `assignment_submissions.file_path` points at real bytes — otherwise a    │
 * │ student could simply NAME a classmate's document. It recorded a forward  │
 * │ dependency: Stage 9 must EXTEND this class, not fork it, because two     │
 * │ implementations of "is this stored name safe" is exactly how one of them │
 * │ ends up missing a case, and the case in question is directory traversal  │
 * │ into another tenant's documents.                                         │
 * │                                                                          │
 * │ Stage 9 ADDED: resolvePublic() (the category+bare-uuid door), sniff()    │
 * │ (whitelist content-typing) and describe() (resolve + name + mime for the │
 * │ download route). Stage 9 CHANGED: the category/extension table is no     │
 * │ longer a private const restating backend/'s KIND_RULES — it is derived   │
 * │ from App\Enums\UploadKind, so the upload endpoint's allow-list and this  │
 * │ resolver's allow-list are the SAME list by construction.                 │
 * │ Stage 9 did NOT change: resolve()'s signature or its `?string` contract, │
 * │ because AssignmentService depends on both.                               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE NAME PATTERN IS THE WHOLE SECURITY ARGUMENT, AND IT IS AN           ║
 * ║ ALLOW-LIST, NOT A `..` FILTER.                                          ║
 * ║                                                                          ║
 * ║ A stored name is always server-generated as "<uuid>.<known-ext>".        ║
 * ║ Requiring exactly that shape means the value can contain no '/', no      ║
 * ║ '\', no '..', no NUL and no second dot — so                              ║
 * ║ '../../../../etc/passwd', an already-percent-decoded '..%2f..%2fx', an   ║
 * ║ absolute path and a null-byte truncation all fail BEFORE the filesystem  ║
 * ║ is touched. A blacklist of '..' would have to be right about every       ║
 * ║ encoding; this has to be right about one regex.                          ║
 * ║                                                                          ║
 * ║ realpath() + the prefix check is the belt to that braces: even a name    ║
 * ║ that somehow passed must still resolve INSIDE the configured root, which ║
 * ║ also catches a symlink planted in the storage directory.                 ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * WHY A PREDICATE-STYLE `?string` RETURN AND NOT AN EXCEPTION. backend/ throws
 * NotFoundException and AssignmentService immediately catches it to re-throw a
 * 422 with different wording. The catch-to-translate round trip is noise: this
 * returns null and the one caller decides the status. Stage 9's file routes
 * raise a 404 from the same facts, at their own call sites.
 */
final class StoredUploadPath
{
    /** The prefix every stored reference carries, as POST /uploads returns it. */
    public const PREFIX = 'uploads/';

    /**
     * A PUBLIC file is addressed by its BARE uuid, with no extension.
     *
     * ┌── THE PATTERN IS CASE-SENSITIVE AND FULLY ANCHORED, ON PURPOSE. ────┐
     * │ Ported byte-for-byte from backend/FileService::UUID_PATTERN, which  │
     * │ carries no `i` flag. Str::uuid() emits lowercase, so an UPPERCASE   │
     * │ uuid never named a file this server wrote and is refused. Loosening │
     * │ it to `/^[0-9a-f-]+$/i` would accept '-', '--', '------' and every  │
     * │ other hyphen soup as a "file id" — harmless only for as long as the │
     * │ realpath check downstream stays perfect, which is not a bet worth   │
     * │ taking when the strict form costs nothing.                          │
     * │                                                                      │
     * │ Anchored at BOTH ends, so 'uuid.png' does not match: appending an   │
     * │ extension to a real id must 404, because the URL contract is        │
     * │ extensionless and a URL ending in '.png' is the thing a production  │
     * │ static-file handler claims before PHP ever sees it.                 │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * The ONLY content types this application will ever name in a
     * Content-Type header for an uploaded file.
     *
     * Ported literally from backend/FileService::sniff()'s `$safe` array —
     * eight entries, every one of them inert in a browser. Anything else
     * (text/html, image/svg+xml, text/x-php, a zip, an unknown binary)
     * degrades to application/octet-stream, which combined with `nosniff`
     * means the browser cannot be talked into executing it.
     *
     * NOTE `application/zip` is NOT here even though the upload allow-list
     * accepts it for docx/pptx. That asymmetry is deliberate: accepting a zip
     * at the door is a portability allowance for OOXML; ANNOUNCING a served
     * body as a zip is not required by anything and octet-stream is stricter.
     *
     * @var list<string>
     */
    private const SAFE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /** Cheap shape test — "is this meant to be an internal reference at all?" */
    public static function looksInternal(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    // ══ THE DATABASE-SOURCED DOOR (Stage 6) ═══════════════════════════════

    /**
     * The ABSOLUTE path of the referenced file, or null if the reference is
     * malformed, names an unknown category, has a name the server could not
     * have generated, or points at bytes that are not on disk.
     *
     * The input is a value that came out of a DATABASE COLUMN — a
     * `notes.file_path` or an `assignment_submissions.file_path` — NEVER out
     * of a request path segment. The caller is responsible for having ALREADY
     * authorized the requester against the owning resource; this method
     * re-validates the PATH SHAPE, and does not re-check authorization.
     *
     * The stored value is nonetheless re-validated rather than trusted: it
     * could have been written by an older, laxer code path.
     *
     * Note all four failure modes collapse into one null: distinguishing
     * "unknown category" from "file missing" would be a positive oracle about
     * the storage layout, and the caller owes one message either way.
     */
    public static function resolve(string $reference): ?string
    {
        // Windows separators are normalised because the value may have been
        // stored by a Windows deployment; the check itself is separator-blind
        // afterwards.
        $relative = ltrim(str_replace('\\', '/', $reference), '/');

        if (str_starts_with($relative, self::PREFIX)) {
            $relative = substr($relative, strlen(self::PREFIX));
        }

        $parts = explode('/', $relative);

        // EXACTLY two segments. 'documents' alone, or 'a/b/c', is not a
        // reference this application ever issued.
        if (count($parts) !== 2) {
            return null;
        }

        [$category, $name] = $parts;

        // fromDirectory() knows the PRIVATE categories too, unlike the public
        // resolver below — that is the entire difference between the two doors.
        $kind = UploadKind::fromDirectory($category);

        if ($kind === null || !self::isServerGeneratedName($kind, $name)) {
            return null;
        }

        return self::realpathInsideBase($category . '/' . $name);
    }

    /**
     * resolve(), plus the two things a download response needs: the sniffed
     * content type and the on-disk name.
     *
     * @return array{path:string, mime:string, name:string}|null
     */
    public static function describe(string $reference): ?array
    {
        $real = self::resolve($reference);

        if ($real === null) {
            return null;
        }

        return [
            'path' => $real,
            'mime' => self::sniff($real),
            'name' => basename(str_replace('\\', '/', $real)),
        ];
    }

    // ══ THE PUBLIC, REQUEST-SOURCED DOOR (Stage 9) ════════════════════════

    /**
     * Resolve a PUBLIC file from a request's `{category}` and bare `{id}`.
     *
     * ┌── THIS IS THE ONLY METHOD HERE WHOSE INPUT IS CLIENT-CONTROLLED. ───┐
     * │ Both segments come straight off the URL, so traversal is the whole  │
     * │ threat model. Three gates, in this order, and each logs its own     │
     * │ distinct reason BEFORE returning null:                              │
     * │                                                                      │
     * │   1. category must be in UploadKind::publicDirectories(). This is   │
     * │      what makes `documents` unreachable here EVEN WITH A CORRECT    │
     * │      UUID — a private note attachment has no public address at all. │
     * │   2. id must match UUID_PATTERN. '../../../../etc/passwd' cannot be │
     * │      EXPRESSED in this parameter: the value may contain only hex    │
     * │      digits and hyphens, so '/', '\', '.', '..', a NUL byte and an  │
     * │      already-decoded percent escape all fail before any filesystem  │
     * │      call happens.                                                   │
     * │   3. the extension is recovered by TRYING THE CATEGORY'S OWN        │
     * │      ALLOW-LIST, never by reading anything the client sent, and the │
     * │      result must realpath() to somewhere inside the storage root.   │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ┌── WHY THERE IS NO `->whereUuid('id')` ON THE ROUTE. ────────────────┐
     * │ A route constraint would 404 in the ROUTER, before this method ran, │
     * │ and every one of the security-relevant log lines below would        │
     * │ silently stop being written. The rejection reason is the product    │
     * │ here — an operator watching `invalid_file_id` climb is watching     │
     * │ someone probe for traversal — so the validation has to live where   │
     * │ it can be observed, not where it is cheapest.                       │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * @return array{path:string, mime:string, name:string}|null
     */
    public static function resolvePublic(string $category, string $id): ?array
    {
        $category = strtolower($category);

        if (!in_array($category, UploadKind::publicDirectories(), true)) {
            // Includes 'documents': a private category is not addressable by
            // filename through this route even if the name is known.
            self::denied($category, $id, 'category_not_public');

            return null;
        }

        if (preg_match(self::UUID_PATTERN, $id) !== 1) {
            self::denied($category, $id, 'invalid_file_id');

            return null;
        }

        $kind = UploadKind::fromDirectory($category);

        if ($kind === null) {
            self::denied($category, $id, 'category_not_public');

            return null;
        }

        foreach ($kind->extensions() as $extension) {
            $real = self::realpathInsideBase($category . '/' . $id . '.' . $extension);

            if ($real !== null) {
                return [
                    'path' => $real,
                    'mime' => self::sniff($real),
                    'name' => $id . '.' . $extension,
                ];
            }
        }

        self::denied($category, $id, 'not_found');

        return null;
    }

    // ══ SHARED PRIMITIVES ═════════════════════════════════════════════════

    /**
     * Content-Type from a FRESH finfo read of the bytes ON DISK — never from
     * a database column, never from the extension, never from what the
     * uploader claimed.
     *
     * If a file ever changed on disk (a compromised writer, a restored
     * backup, a hand-placed file), the served type follows the REAL bytes.
     * And anything that does not sniff to one of the eight known-inert types
     * is downgraded to application/octet-stream, so a surprise text/html or
     * image/svg+xml can never be rendered as active content by a browser.
     *
     * That degradation is what makes `Content-Disposition: inline` safe for
     * the public image route: the worst content type that route can ever
     * announce is octet-stream, which with `nosniff` is not renderable.
     */
    public static function sniff(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = ($finfo !== false ? finfo_file($finfo, $path) : '') ?: '';

        if ($finfo !== false) {
            finfo_close($finfo);
        }

        return in_array($mime, self::SAFE_MIME_TYPES, true) ? $mime : 'application/octet-stream';
    }

    /** "<uuid>.<ext-this-category-allows>", anchored at both ends. */
    private static function isServerGeneratedName(UploadKind $kind, string $name): bool
    {
        $extensions = implode(
            '|',
            array_map(static fn (string $e): string => preg_quote($e, '/'), $kind->extensions())
        );

        if ($extensions === '') {
            return false;
        }

        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(' . $extensions . ')$/';

        return preg_match($pattern, $name) === 1;
    }

    /**
     * realpath() a storage-relative path and require it to land INSIDE the
     * configured root. Null on either failure.
     *
     * realpath() answers false for a path that does not exist — which is the
     * "upload it first" case — and RESOLVES SYMLINKS, which is what makes the
     * containment check meaningful: a symlink planted inside
     * storage/uploads/images pointing at /etc/passwd resolves to /etc/passwd
     * and fails the prefix test.
     *
     * This is deliberately NOT `Storage::disk('uploads')->path()`. Laravel's
     * disk helper concatenates; it performs no containment check at all and
     * would happily hand back a path outside the root.
     */
    private static function realpathInsideBase(string $relative): ?string
    {
        $base = self::baseDirectory();

        if ($base === null) {
            return null;
        }

        $real = realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));

        if ($real === false || !self::isInside($real, $base)) {
            return null;
        }

        return $real;
    }

    /** The configured root, resolved — or null when it does not exist yet. */
    public static function baseDirectory(): ?string
    {
        $configured = (string) config('upload.storage_path');

        $real = $configured === '' ? false : realpath($configured);

        return $real === false ? null : $real;
    }

    /**
     * Containment, compared on REAL paths with a trailing separator.
     *
     * THE TRAILING SEPARATOR IS NOT OPTIONAL. Without it,
     * '/storage/uploads-evil' passes a naive str_starts_with against
     * '/storage/uploads' — a sibling directory that an attacker who can
     * create directories could use to escape the intended root entirely.
     */
    private static function isInside(string $real, string $base): bool
    {
        $normalise = static fn (string $p): string => rtrim(str_replace('\\', '/', $p), '/');

        return str_starts_with($normalise($real) . '/', $normalise($base) . '/');
    }

    /**
     * Every public-door rejection, with its own distinct reason.
     *
     * The requested name is NEWLINE-STRIPPED AND TRUNCATED before it is
     * written: it is attacker-controlled, and a value containing "\n" would
     * otherwise forge additional, well-formed-looking log lines. The absolute
     * server path is never logged.
     */
    private static function denied(string $category, string $name, string $reason): void
    {
        SecurityLog::warning('File access denied', [
            'event' => 'file.denied',
            'category' => substr(str_replace(["\r", "\n"], ' ', $category), 0, 60),
            'requested_name' => substr(str_replace(["\r", "\n"], ' ', $name), 0, 120),
            'reason' => $reason,
        ]);
    }

    /** Static-only. */
    private function __construct()
    {
    }
}
