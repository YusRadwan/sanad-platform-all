<?php

declare(strict_types=1);

namespace App\Actions\ContentItem;

use App\Models\ContentItem;
use App\Models\Note;

/**
 * `notes` — port of saveTypeDetail()'s note branch + upsertNote().
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ A NOTE WITH NEITHER `content_text` NOR `file_path` GETS **NO ROW AT     ║
 * ║ ALL** — NOT AN EMPTY ONE. THIS IS A RULE, NOT AN OVERSIGHT.             ║
 * ║                                                                          ║
 * ║   elseif ($type === 'note'                                               ║
 * ║           && (isset($data['content_text']) || isset($data['file_path'])))║
 * ║                                                                          ║
 * ║ Both halves of that condition matter and both are ported verbatim:       ║
 * ║                                                                          ║
 * ║  * It is an OR, so `file_path` alone (an uploaded document with no       ║
 * ║    inline text) is enough, and so is `content_text` alone.                ║
 * ║  * It is isset(), so an explicit null counts as absent.                   ║
 * ║                                                                          ║
 * ║ "Just create an empty row, it is harmless" is wrong in a concrete way:   ║
 * ║ GET /content-items/{id}/file (row 73) treats a `notes` row with an       ║
 * ║ empty `file_path` and a MISSING `notes` row identically today (both      ║
 * ║ 404), but NotesManagement.tsx reads `row.detail?.file_name` and would    ║
 * ║ start rendering an empty-string filename where it currently renders      ║
 * ║ nothing. The absent row is the signal.                                    ║
 * ║                                                                          ║
 * ║ Pinned by ContentItemDetailSemanticsTest::test_a_note_with_neither_     ║
 * ║ field_creates_no_notes_row_at_all.                                       ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Once the condition IS met the write is a FULL REPLACE, like video and link
 * and unlike exam/assignment: upsertNote() binds all three columns with
 * `?? null`, so posting `content_text` alone to an existing note clears its
 * `file_name`/`file_path`. Read PersistVideoDetail's banner for why that
 * asymmetry is preserved rather than harmonised.
 */
final class PersistNoteDetail implements PersistsContentDetail
{
    public function persist(ContentItem $item, array $data): void
    {
        $hasText = ($data['content_text'] ?? null) !== null;
        $hasFile = ($data['file_path'] ?? null) !== null;

        if (!$hasText && !$hasFile) {
            return;
        }

        $note = Note::query()->findOrNew($item->getKey());

        $note->setAttribute('content_item_id', $item->getKey());
        $note->setAttribute('content_text', $data['content_text'] ?? null);
        $note->setAttribute('file_name', $data['file_name'] ?? null);
        $note->setAttribute('file_path', $data['file_path'] ?? null);

        $note->save();
    }
}
