<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * links — the detail table for `content_items.type = 'link'`.
 *
 * The primary key IS the foreign key; see App\Models\Video's banner for the
 * three things Eloquent has to be told about that, including why uniqueIds()
 * is emptied.
 *
 * Columns: content_item_id, url. No timestamps.
 *
 * `url` is rendered as an `<a href>` by CourseSections.tsx, which is why it is
 * allow-listed through App\Rules\SafeUrlValue before it ever reaches this
 * table — see App\Http\Requests\ContentItem\Concerns\ValidatesContentDetail.
 */
class Link extends BaseModel
{
    protected $table = 'links';

    protected $primaryKey = 'content_item_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'content_item_id',
        'url',
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
