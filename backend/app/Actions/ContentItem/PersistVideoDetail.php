<?php

declare(strict_types=1);

namespace App\Actions\ContentItem;

use App\Models\ContentItem;
use App\Models\Video;

/**
 * `videos` — port of backend/ContentItemService::saveTypeDetail()'s video
 * branch plus ContentItemRepository::upsertVideo().
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS IS A **FULL REPLACE**, NOT A PARTIAL MERGE. MEASURED AGAINST        ║
 * ║ backend/, NOT ASSUMED FROM THE EXAM BRANCH.                              ║
 * ║                                                                          ║
 * ║ upsertVideo() is                                                         ║
 * ║   INSERT … ON DUPLICATE KEY UPDATE                                        ║
 * ║     video_url = VALUES(video_url), duration = VALUES(duration),          ║
 * ║     is_preview = VALUES(is_preview), provider = VALUES(provider),        ║
 * ║     thumbnail_url = VALUES(thumbnail_url)                                 ║
 * ║ with every bound value defaulted `$data[…] ?? null`. So a PUT carrying   ║
 * ║ ONLY `video_url` sets duration, provider and thumbnail_url back to NULL  ║
 * ║ and is_preview back to 0.                                                ║
 * ║                                                                          ║
 * ║ The `array_filter(fn ($v) => $v !== null)` partial-update rule that the  ║
 * ║ session brief describes applies to the EXAM and ASSIGNMENT branches      ║
 * ║ ONLY — see PersistExamDetail. The two branches of the same method behave ║
 * ║ differently and always have; assuming symmetry here would have INVENTED  ║
 * ║ a merge semantic that production does not have, and would have made it   ║
 * ║ impossible to clear a video's poster or duration through the only route  ║
 * ║ that can. Recorded as decision 5.8 in docs/open-decisions.md.            ║
 * ║                                                                          ║
 * ║ Pinned by ContentItemDetailSemanticsTest::test_a_video_update_replaces_  ║
 * ║ the_whole_detail_row_exactly_as_backend_does.                            ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * NO ROW WITHOUT A `video_url`: the legacy branch is guarded by
 * `isset($data['video_url'])`, so a video content item created with no URL
 * gets a `content_items` row and no `videos` row. That is also the only thing
 * standing between this table and its NOT NULL `video_url` column.
 */
final class PersistVideoDetail implements PersistsContentDetail
{
    public function persist(ContentItem $item, array $data): void
    {
        $url = $data['video_url'] ?? null;

        // isset(), not array_key_exists(): backend/ treats an explicit null
        // exactly like an absent key here.
        if ($url === null) {
            return;
        }

        $video = Video::query()->findOrNew($item->getKey());

        $video->setAttribute('content_item_id', $item->getKey());
        // trim() reproduces UrlValidator::required()'s return value; the rule
        // only validated, it did not normalise.
        $video->setAttribute('video_url', trim((string) $url));
        $video->setAttribute('duration', $data['duration'] ?? null);
        $video->setAttribute('is_preview', (bool) ($data['is_preview'] ?? false));
        $video->setAttribute('provider', $data['provider'] ?? null);
        $video->setAttribute('thumbnail_url', self::blankToNull($data['thumbnail_url'] ?? null));

        $video->save();
    }

    /**
     * UrlValidator::nullable()'s contract: '' and null are both "no poster".
     *
     * This is what makes `thumbnail_url: ''` a genuine CLEAR instruction —
     * and why POST/PUT on these paths are exempted from
     * ConvertEmptyStringsToNull in AppServiceProvider. Without the exemption
     * the '' would already be null by the time it got here and the distinction
     * would be unobservable rather than merely unused.
     */
    private static function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
