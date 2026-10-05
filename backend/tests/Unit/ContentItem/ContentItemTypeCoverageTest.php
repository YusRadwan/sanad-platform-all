<?php

declare(strict_types=1);

namespace Tests\Unit\ContentItem;

use App\Actions\ContentItem\ContentDetailPersisters;
use App\Actions\ContentItem\PersistExamDetail;
use App\Actions\ContentItem\PersistLinkDetail;
use App\Actions\ContentItem\PersistNoteDetail;
use App\Actions\ContentItem\PersistsContentDetail;
use App\Actions\ContentItem\PersistVideoDetail;
use App\Enums\ContentItemType;
use App\Models\ContentItem;
use PHPUnit\Framework\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE READ DISPATCHER AND THE WRITE DISPATCHER MUST AGREE, FOR EVERY      ║
 * ║ ENUM CASE, FOREVER.                                                     ║
 * ║                                                                          ║
 * ║ There are two `match`es over ContentItemType:                            ║
 * ║   ContentItem::relationNameFor()      READ  — which relation carries it  ║
 * ║   ContentDetailPersisters::for()      WRITE — which action persists it   ║
 * ║                                                                          ║
 * ║ A type that has a relation but no persister is silently unwritable; one  ║
 * ║ that has a persister but no relation writes a row nothing will ever read ║
 * ║ back. Neither failure produces an error at the point it is introduced.   ║
 * ║                                                                          ║
 * ║ This is a bare unit test — no application, no database — so it runs in   ║
 * ║ milliseconds and cannot be disabled by an unrelated fixture problem.     ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class ContentItemTypeCoverageTest extends TestCase
{
    /** The one table of truth for both dispatchers. */
    private const EXPECTED = [
        'video' => ['video', PersistVideoDetail::class],
        'link' => ['link', PersistLinkDetail::class],
        'note' => ['note', PersistNoteDetail::class],
        'exam' => ['exam', PersistExamDetail::class],
        // Approved decision #1: unimplemented, on BOTH sides, explicitly.
        'file' => [null, null],
    ];

    public function test_every_enum_case_is_covered_by_both_dispatchers(): void
    {
        foreach (ContentItemType::cases() as $type) {
            $this->assertArrayHasKey(
                $type->value,
                self::EXPECTED,
                "A new content type '{$type->value}' was added. Decide EXPLICITLY whether it has "
                . 'a detail table, update both matches, and list it here.'
            );

            [$relation, $persister] = self::EXPECTED[$type->value];

            $this->assertSame(
                $relation,
                ContentItem::relationNameFor($type),
                "relationNameFor({$type->value}) disagrees with the table."
            );

            $actual = ContentDetailPersisters::for($type);

            if ($persister === null) {
                $this->assertNull($actual, "'{$type->value}' must have NO persister.");
            } else {
                $this->assertInstanceOf($persister, $actual);
                $this->assertInstanceOf(PersistsContentDetail::class, $actual);
            }
        }
    }

    /**
     * The agreement stated as one claim: a type has a relation IF AND ONLY IF
     * it has a persister. This is the assertion that catches a half-added type
     * even if someone updates EXPECTED to match their mistake.
     */
    public function test_a_type_has_a_relation_exactly_when_it_has_a_persister(): void
    {
        foreach (ContentItemType::cases() as $type) {
            $this->assertSame(
                ContentItem::relationNameFor($type) === null,
                ContentDetailPersisters::for($type) === null,
                "'{$type->value}' has a relation but no persister, or the reverse. "
                . 'A type that can be written but never read back — or read but never written — '
                . 'is a silent data bug, not a partial feature.'
            );
        }
    }

    /** `file` is the ONLY unimplemented type, and it is unimplemented on purpose. */
    public function test_file_is_the_only_type_without_a_detail_table(): void
    {
        $without = array_values(array_filter(
            ContentItemType::cases(),
            static fn (ContentItemType $t): bool => ContentItem::relationNameFor($t) === null
        ));

        $this->assertSame([ContentItemType::File], $without);
    }

    /** A null type (an unhydrated model) resolves to nothing rather than exploding. */
    public function test_a_null_type_resolves_to_no_relation(): void
    {
        $this->assertNull(ContentItem::relationNameFor(null));
    }
}
