<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * notes — the detail table for `content_items.type = 'note'`.
 *
 * The primary key IS the foreign key; see App\Models\Video's banner.
 *
 * Columns: content_item_id, content_text, file_name, file_path. No timestamps.
 * All three payload columns are nullable because a note may legitimately be
 * inline text OR an uploaded document.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ A ROW HERE IS **CONDITIONAL**, UNLIKE EVERY OTHER DETAIL TABLE.         │
 * │                                                                          │
 * │ backend/ContentItemService::saveTypeDetail() creates a `notes` row only  │
 * │ when the payload carries `content_text` OR `file_path`:                  │
 * │                                                                          │
 * │   elseif ($type === 'note' && (isset($data['content_text'])              │
 * │                                || isset($data['file_path'])))            │
 * │                                                                          │
 * │ A note with NEITHER gets a `content_items` row and NO `notes` row at     │
 * │ all — not an empty one. That is a deliberate rule, not an oversight, and │
 * │ App\Actions\ContentItem\PersistNoteDetail preserves it by no-op'ing.     │
 * │ `content_items.description` is a separate column and still carries the   │
 * │ note's blurb in that case, so the item is not contentless.               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `file_path` is the server-side storage path resolved by
 * GET /content-items/{id}/file (freeze-table row 73, NOT in this session). The
 * client never sends or sees a path on that route — it sends a content-item
 * UUID and the path is read out of this row.
 */
class Note extends BaseModel
{
    protected $table = 'notes';

    protected $primaryKey = 'content_item_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'content_item_id',
        'content_text',
        'file_name',
        'file_path',
    ];

    /** No key generation — the parent supplies it. See App\Models\Video. */
    public function uniqueIds(): array
    {
        return [];
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id', 'id');
    }
}
