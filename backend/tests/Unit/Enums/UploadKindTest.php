<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\UploadKind;
use PHPUnit\Framework\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ backend/UploadService::KIND_RULES, PORTED VALUE BY VALUE.               ║
 * ║                                                                          ║
 * ║ Every number and every mime string below was READ OUT of the legacy      ║
 * ║ array, not inferred from the category name. A "reasonable" size cap or   ║
 * ║ an "obviously fine" extension is exactly the kind of guess that silently ║
 * ║ changes what a live client may upload.                                   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * A bare PHPUnit test: the enum touches no container, no database and no
 * config, so booting the application to ask it questions would only make the
 * suite slower and the failure less direct.
 */
final class UploadKindTest extends TestCase
{
    public function test_the_five_kinds_and_their_directories_are_exactly_backends(): void
    {
        $this->assertSame(
            ['avatar', 'banner', 'image', 'profile', 'document'],
            UploadKind::values()
        );

        $this->assertSame(
            [
                'avatar' => 'avatars',
                'banner' => 'banners',
                'image' => 'images',
                'profile' => 'profiles',
                'document' => 'documents',
            ],
            array_combine(
                UploadKind::values(),
                array_map(static fn (UploadKind $k): string => $k->directory(), UploadKind::cases())
            ),
            'These strings are baked into every notes.file_path and every /files/... URL already '
            . 'issued. They are contract, not convention.'
        );
    }

    public function test_only_document_is_private(): void
    {
        $this->assertFalse(UploadKind::Document->isPublic());

        foreach ([UploadKind::Avatar, UploadKind::Banner, UploadKind::Image, UploadKind::Profile] as $kind) {
            $this->assertTrue($kind->isPublic(), "{$kind->value} must remain publicly servable.");
        }
    }

    public function test_the_four_image_kinds_share_one_allow_list(): void
    {
        $expected = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
        ];

        foreach ([UploadKind::Avatar, UploadKind::Banner, UploadKind::Image, UploadKind::Profile] as $kind) {
            $this->assertSame($expected, $kind->extensionMimeMap(), "{$kind->value} drifted.");
        }
    }

    public function test_the_document_allow_list_is_backends_five_extensions(): void
    {
        $this->assertSame(
            [
                'pdf' => ['application/pdf'],
                'doc' => ['application/msword'],
                'docx' => [
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/zip',
                ],
                'ppt' => ['application/vnd.ms-powerpoint'],
                'pptx' => [
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'application/zip',
                ],
            ],
            UploadKind::Document->extensionMimeMap()
        );
    }

    /**
     * ┌── application/zip IS ALLOWED FOR docx/pptx AND FOR NOTHING ELSE. ────┐
     * │ An OOXML file genuinely IS a zip container and finfo reports the     │
     * │ precise type on some builds and plain zip on others. Widening it     │
     * │ one extension further — to `.zip`, say — turns the narrow            │
     * │ portability allowance back into the universal bypass the            │
     * │ octet-stream catch-all used to be.                                   │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_application_zip_is_confined_to_the_two_ooxml_extensions(): void
    {
        $withZip = [];

        foreach (UploadKind::cases() as $kind) {
            foreach ($kind->extensionMimeMap() as $extension => $mimes) {
                if (in_array('application/zip', $mimes, true)) {
                    $withZip[] = $kind->value . '.' . $extension;
                }
            }
        }

        $this->assertSame(['document.docx', 'document.pptx'], $withZip);
    }

    /** SVG is stored XSS with a picture's extension. Banned, never sanitised. */
    public function test_svg_appears_nowhere_at_all(): void
    {
        foreach (UploadKind::cases() as $kind) {
            $this->assertArrayNotHasKey('svg', $kind->extensionMimeMap());

            foreach ($kind->extensionMimeMap() as $mimes) {
                $this->assertNotContains('image/svg+xml', $mimes);
                $this->assertNotContains('text/html', $mimes);
                $this->assertNotContains('text/xml', $mimes);
                $this->assertNotContains('application/xml', $mimes);
            }
        }
    }

    /** octet-stream is a universal bypass: EVERY unclassifiable binary is one. */
    public function test_octet_stream_appears_nowhere_at_all(): void
    {
        foreach (UploadKind::cases() as $kind) {
            foreach ($kind->extensionMimeMap() as $mimes) {
                $this->assertNotContains('application/octet-stream', $mimes);
            }
        }
    }

    /** No executable or script extension may be a KEY, in any kind. */
    public function test_no_executable_extension_is_accepted_anywhere(): void
    {
        $dangerous = ['php', 'php5', 'phtml', 'exe', 'sh', 'bat', 'cmd', 'js', 'html', 'htm', 'svg', 'htaccess'];

        foreach (UploadKind::cases() as $kind) {
            foreach ($dangerous as $extension) {
                $this->assertArrayNotHasKey(
                    $extension,
                    $kind->extensionMimeMap(),
                    "UploadKind::{$kind->name} would accept a .{$extension} filename."
                );
            }
        }
    }

    // ══ THE DERIVED LISTS ═════════════════════════════════════════════════

    /**
     * publicDirectories() must be COMPUTED from isPublic(), never restated.
     * Proven by construction: the assertion rebuilds the expected list by
     * filtering the cases, so a hardcoded literal that happened to be right
     * today would still pass — and the SECOND assertion, that the two stay
     * equal for every case, is what a literal cannot satisfy on its own.
     */
    public function test_public_directories_is_derived_from_is_public(): void
    {
        $derived = [];

        foreach (UploadKind::cases() as $kind) {
            if ($kind->isPublic()) {
                $derived[] = $kind->directory();
            }
        }

        $this->assertSame($derived, UploadKind::publicDirectories());
        $this->assertSame(['avatars', 'banners', 'images', 'profiles'], UploadKind::publicDirectories());
        $this->assertNotContains('documents', UploadKind::publicDirectories());
    }

    public function test_directories_covers_public_and_private_alike(): void
    {
        $this->assertSame(
            ['avatars', 'banners', 'images', 'profiles', 'documents'],
            UploadKind::directories()
        );

        $this->assertSame(
            ['documents'],
            array_values(array_diff(UploadKind::directories(), UploadKind::publicDirectories()))
        );
    }

    public function test_from_directory_round_trips_and_refuses_anything_else(): void
    {
        foreach (UploadKind::cases() as $kind) {
            $this->assertSame($kind, UploadKind::fromDirectory($kind->directory()));
        }

        foreach (['', 'Documents', 'DOCUMENTS', 'avatar', 'uploads', '../documents', 'logs'] as $unknown) {
            $this->assertNull(
                UploadKind::fromDirectory($unknown),
                "fromDirectory('{$unknown}') must be null — it is a directory this server never wrote."
            );
        }
    }

    // ══ THE REQUEST `type` FIELD ══════════════════════════════════════════

    /**
     * A MISSING type defaults to `document` — the widest bucket. Preserved
     * from backend/UploadController deliberately; see open decision 9.3.
     */
    public function test_a_missing_type_defaults_to_document_and_an_unknown_one_is_null(): void
    {
        $this->assertSame(UploadKind::Document, UploadKind::fromRequestType(null));

        foreach (UploadKind::cases() as $kind) {
            $this->assertSame($kind, UploadKind::fromRequestType($kind->value));
        }

        foreach (['video', 'Avatar', 'AVATAR', '', 'documents', 'file'] as $unknown) {
            $this->assertNull(
                UploadKind::fromRequestType($unknown),
                "'{$unknown}' must be rejected, not silently coerced to a kind."
            );
        }
    }

    /**
     * A NON-STRING type is null, not an accidental match. `['document']` and
     * `0` both reach this method from a JSON body, and `tryFrom()` would raise
     * a TypeError on the array — a 500 where a 422 is owed.
     */
    public function test_a_non_string_type_is_rejected_rather_than_fatal(): void
    {
        foreach ([['document'], 0, 1, 1.5, true, false, new \stdClass()] as $value) {
            $this->assertNull(UploadKind::fromRequestType($value));
        }
    }

    public function test_extensions_matches_the_keys_of_the_mime_map(): void
    {
        foreach (UploadKind::cases() as $kind) {
            $this->assertSame(array_keys($kind->extensionMimeMap()), $kind->extensions());
        }
    }
}
