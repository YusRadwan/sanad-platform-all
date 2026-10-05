<?php

declare(strict_types=1);

namespace App\Actions\ContentItem;

use App\Models\ContentItem;
use App\Models\Link;

/**
 * `links` — port of saveTypeDetail()'s link branch + upsertLink().
 *
 * The simplest of the five: one column, upserted. Guarded by
 * `isset($data['url'])`, so a link item with no URL gets a `content_items` row
 * and no `links` row — which is also what keeps the NOT NULL `url` column
 * satisfiable.
 *
 * The URL was allow-listed at the Form Request (App\Rules\SafeUrlValue); this
 * class never sees an unsafe value, and the 422 that would have been raised
 * happened before any parent row existed.
 */
final class PersistLinkDetail implements PersistsContentDetail
{
    public function persist(ContentItem $item, array $data): void
    {
        $url = $data['url'] ?? null;

        if ($url === null) {
            return;
        }

        $link = Link::query()->findOrNew($item->getKey());

        $link->setAttribute('content_item_id', $item->getKey());
        $link->setAttribute('url', trim((string) $url));

        $link->save();
    }
}
