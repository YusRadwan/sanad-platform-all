<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The `type` field of POST /api/v1/uploads — the five upload categories.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS ENUM REPLACES backend/UploadService::KIND_RULES, THE STATIC ARRAY   ║
 * ║ THAT FOUR SEPARATE PLACES USED TO READ BY STRING KEY.                    ║
 * ║                                                                          ║
 * ║ In backend/ the rules lived in one private const and were re-projected   ║
 * ║ by FileService three different ways: publicCategories() filtered on      ║
 * ║ `visibility`, extensionsFor() searched on `dir`, and resolveStoredPath() ║
 * ║ read array_column(..., 'dir'). Each projection was a loop over an        ║
 * ║ untyped array that could silently return [] for a typo. Here the same    ║
 * ║ three questions are METHODS on a closed type, so a wrong category is a   ║
 * ║ null from fromDirectory() rather than an empty allow-list that fails     ║
 * ║ open in whichever direction the caller happens to branch.                ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── SECURITY: THE TWO MIME TYPES THAT ARE ABSENT ON PURPOSE. ──────────────┐
 * │                                                                          │
 * │ `image/svg+xml` IS ABSENT FROM EVERY KIND, AND IT MUST STAY ABSENT.      │
 * │ An SVG is an XML document that may carry <script> or <foreignObject>,    │
 * │ and it executes in the ORIGIN OF THE PAGE THAT RENDERS IT — including    │
 * │ on direct navigation, which is precisely what GET /files/{cat}/{id}      │
 * │ is. It is stored XSS with a picture's file extension. It is BANNED,      │
 * │ never sanitised: a sanitiser is a parser, and an XML parser that has to  │
 * │ be right about every entity/namespace trick is a strictly worse bet      │
 * │ than one `in_array()` that has to be right about nothing.                │
 * │                                                                          │
 * │ `application/octet-stream` IS ABSENT FROM EVERY KIND. backend/'s own     │
 * │ comment records why: it used to sit in the `document` list as a          │
 * │ catch-all for zip-based Office files, and that made it a UNIVERSAL       │
 * │ BYPASS — any binary finfo could not classify (a PE executable, an ELF    │
 * │ binary, an encrypted blob) sniffs as octet-stream and was accepted.      │
 * │ The narrow replacement is `application/zip`, allowed for `docx` and      │
 * │ `pptx` ONLY, because an OOXML file genuinely IS a zip container and      │
 * │ finfo reports the precise OOXML type on some builds and plain zip on     │
 * │ others. Measured in this environment (PHP 8.3.30 / Windows): both        │
 * │ report the precise type — the `application/zip` branch is the           │
 * │ portability allowance, not the observed behaviour here.                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── publicDirectories() IS DERIVED, NEVER A SECOND LIST. ──────────────────┐
 * │ backend/FileService::publicCategories() already made this point: it      │
 * │ looped KIND_RULES filtering on visibility rather than restating four     │
 * │ directory names, "so adding a category cannot desynchronise the two      │
 * │ lists". A hardcoded second list here would be the single highest-value   │
 * │ bug in this stage — `documents` appearing in it makes every private      │
 * │ note attachment fetchable, unauthenticated, by anyone who knows a uuid.  │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
enum UploadKind: string
{
    use HasValues;

    case Avatar = 'avatar';

    case Banner = 'banner';

    case Image = 'image';

    case Profile = 'profile';

    case Document = 'document';

    /**
     * The subdirectory under storage/uploads. NOT derivable from the case
     * value: every one of them is the plural, and 'profile' -> 'profiles'
     * would work only by accident of English. These strings are baked into
     * every `notes.file_path` and every `/api/v1/files/...` URL already
     * issued, so they are contract, not convention.
     */
    public function directory(): string
    {
        return match ($this) {
            self::Avatar => 'avatars',
            self::Banner => 'banners',
            self::Image => 'images',
            self::Profile => 'profiles',
            self::Document => 'documents',
        };
    }

    /**
     * Reachable without authentication through GET /files/{category}/{id}?
     *
     * Public = profile/marketing imagery (teacher avatar, teacher banner,
     * course banner, question images, video thumbnails). These render on
     * LOGGED-OUT marketing pages, so they are unauthenticated BY DESIGN.
     *
     * Private = the educational payload (note attachments). Never addressable
     * by filename at all; reached only through
     * GET /content-items/{id}/file, behind the same course-entitlement gate
     * as the note's own text.
     */
    public function isPublic(): bool
    {
        return $this !== self::Document;
    }

    /**
     * declared-extension => the sniffed MIME types that extension may have.
     *
     * BOTH halves of the upload check read this ONE map (see
     * App\Actions\Upload\StoreUpload): the client-declared extension must be
     * a KEY, and the finfo-sniffed MIME must be in that key's VALUE list.
     * Neither half alone is sufficient — a real PNG named `.php` fails the
     * key half, a PHP script named `.jpg` fails the value half.
     *
     * @return array<string, list<string>>
     */
    public function extensionMimeMap(): array
    {
        if ($this === self::Document) {
            return [
                'pdf' => ['application/pdf'],
                'doc' => ['application/msword'],
                // An OOXML file is literally a zip container; see the class
                // banner for why `application/zip` is allowed HERE and only
                // here, and why `application/octet-stream` is allowed nowhere.
                'docx' => [
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/zip',
                ],
                'ppt' => ['application/vnd.ms-powerpoint'],
                'pptx' => [
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'application/zip',
                ],
            ];
        }

        return [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
        ];
    }

    /** The extensions this kind may store, in map order. @return list<string> */
    public function extensions(): array
    {
        return array_keys($this->extensionMimeMap());
    }

    /**
     * Every directory reachable unauthenticated — DERIVED BY ITERATING CASES.
     *
     * See the class banner: this must never become a literal array.
     *
     * @return list<string>
     */
    public static function publicDirectories(): array
    {
        $out = [];

        foreach (self::cases() as $kind) {
            if ($kind->isPublic()) {
                $out[] = $kind->directory();
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Every KNOWN directory, public and private both.
     *
     * Distinct from publicDirectories() and the difference is the whole
     * point: the PUBLIC resolver consults publicDirectories() so `documents`
     * is unreachable there, while the DATABASE-sourced resolver consults this
     * one because a `notes.file_path` legitimately names `documents/`.
     *
     * @return list<string>
     */
    public static function directories(): array
    {
        return array_values(array_map(static fn (self $k): string => $k->directory(), self::cases()));
    }

    /** The kind owning a storage directory, or null for an unknown one. */
    public static function fromDirectory(string $directory): ?self
    {
        foreach (self::cases() as $kind) {
            if ($kind->directory() === $directory) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * The `type` field of an upload request.
     *
     * ┌── A MISSING `type` DEFAULTS TO `document`, AND THAT IS PRESERVED. ───┐
     * │ backend/UploadController::store() reads                              │
     * │   $kind = (string) ($request->input('type') ?? 'document');          │
     * │ so an upload with NO type at all lands in the WIDEST bucket: 10 MB,  │
     * │ and the only kind whose allow-list is not four raster formats.       │
     * │                                                                      │
     * │ Making `type` required would be the safer design and it is NOT done  │
     * │ here: five frontend call sites post to this endpoint and any of them │
     * │ omitting the field would start 422ing. Recorded as open decision     │
     * │ 9.3 — a documented default, not an accident.                         │
     * │                                                                      │
     * │ An UNKNOWN type is a different matter and is rejected: `null` here,  │
     * │ which StoreUpload turns into `unsupported_kind` + 422.               │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public static function fromRequestType(mixed $type): ?self
    {
        if ($type === null) {
            return self::Document;
        }

        return is_string($type) ? self::tryFrom($type) : null;
    }
}
