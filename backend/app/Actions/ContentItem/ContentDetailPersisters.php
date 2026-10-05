<?php

declare(strict_types=1);

namespace App\Actions\ContentItem;

use App\Enums\ContentItemType;

/**
 * The type -> persister dispatch. One `match`, exhaustive over the enum.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `File => null` IS AN EXPLICIT BRANCH, NOT A `default`, AND NOT A SILENT  ║
 * ║ FALLTHROUGH. APPROVED DECISION #1 FOR THIS SESSION.                      ║
 * ║                                                                          ║
 * ║ `content_items.type = 'file'` is UNIMPLEMENTED and stays that way: it is ║
 * ║ accepted by validation as a legal enum value, the parent row is written, ║
 * ║ and NOTHING else happens — byte for byte what backend/                   ║
 * ║ ContentItemService::saveTypeDetail() does, whose if/elseif chain simply  ║
 * ║ has no `file` arm and therefore falls out of the bottom.                 ║
 * ║                                                                          ║
 * ║ Writing it as `ContentItemType::File => null` rather than omitting it    ║
 * ║ costs one line and buys two things: the reader learns that the gap is    ║
 * ║ intentional, and a SEVENTH content type added later cannot inherit the   ║
 * ║ same silence — `match` throws UnhandledMatchError instead of quietly     ║
 * ║ persisting nothing.                                                      ║
 * ║                                                                          ║
 * ║ App\Models\ContentItem::relationNameFor() carries the identical branch   ║
 * ║ for the READ side. The two must agree, and                               ║
 * ║ ContentItemTypeCoverageTest asserts they do for every enum case.         ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class ContentDetailPersisters
{
    /** The persister for a type, or NULL when the type has no detail table. */
    public static function for(ContentItemType $type): ?PersistsContentDetail
    {
        return match ($type) {
            ContentItemType::Video => new PersistVideoDetail(),
            ContentItemType::Link => new PersistLinkDetail(),
            ContentItemType::Note => new PersistNoteDetail(),
            ContentItemType::Exam => new PersistExamDetail(),
            // Decision #1 — deliberately unimplemented. See the banner above.
            ContentItemType::File => null,
        };
    }

    /**
     * Persist the detail for $type if there is one to persist.
     *
     * The null-check lives here, once, so no call site has to remember that
     * one of the five types answers null — and so `file` cannot become an
     * accidental fatal error at whichever call site forgets.
     *
     * @param  array<string, mixed>  $data
     */
    public static function apply(\App\Models\ContentItem $item, array $data): void
    {
        self::for($item->getAttribute('type'))?->persist($item, $data);
    }

    /** Static-only. */
    private function __construct()
    {
    }
}
