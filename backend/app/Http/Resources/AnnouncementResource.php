<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Port of backend/app/Services/AnnouncementService::present() — ten keys, and
 * a deliberately INCONSISTENT mix of snake_case and camelCase, because that is
 * what AnnouncementsManagement.tsx reads:
 *
 *   id, course_id, teacher_id   snake_case (the raw identifiers)
 *   title                        (no rename)
 *   content                      <- the `body` column
 *   linkUrl                      <- `link_url`
 *   startDate / endDate          <- `start_date` / `end_date`
 *   isPublished                  <- `is_published`
 *   createdAt                    <- `created_at`
 *
 * Normalising that would be a nicer API and a broken screen. Not this stage's
 * business — Stage 8's approved change list has exactly four entries and this
 * is not one of them.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `startDate`/`endDate` CARRY A **WRONG** `Z` SUFFIX, AND IT IS PRESERVED. ║
 * ║ (Open decision 8.6.)                                                     ║
 * ║                                                                          ║
 * ║ backend/ emits `str_replace(' ', 'T', $row['start_date']) . 'Z'` — it     ║
 * ║ takes a MySQL DATETIME stored in the application's LOCAL time and labels  ║
 * ║ it UTC. For any deployment east of Greenwich the frontend renders a       ║
 * ║ start date shifted by the offset.                                        ║
 * ║                                                                          ║
 * ║ IT IS SHIPPED ANYWAY, UNCHANGED. The frontend parses these with `new     ║
 * ║ Date(...)` and formats them back for display; "fixing" the suffix here    ║
 * ║ silently moves every existing announcement's displayed schedule by the    ║
 * ║ server's offset, in a stage whose approved change list does not include   ║
 * ║ it. The correct fix is to store and emit a real timezone throughout —     ║
 * ║ a data migration, not a presenter edit. Recorded, not performed.          ║
 * ║                                                                          ║
 * ║ NOTE the asymmetry with `createdAt` directly below, which is the RAW      ║
 * ║ 'Y-m-d H:i:s' with no T and no Z. Two date shapes in one payload, both    ║
 * ║ ported verbatim. Neither is a typo here.                                  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * @mixin Announcement
 */
final class AnnouncementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Announcement $announcement */
        $announcement = $this->resource;

        return [
            'id' => $announcement->getKey(),
            'course_id' => $announcement->getAttribute('course_id'),
            'teacher_id' => $announcement->getAttribute('teacher_id'),
            'title' => $announcement->getAttribute('title'),
            'content' => $announcement->getAttribute('body'),
            'linkUrl' => $announcement->getAttribute('link_url'),
            'startDate' => self::pseudoUtc($announcement->getAttribute('start_date')),
            'endDate' => self::pseudoUtc($announcement->getAttribute('end_date')),
            'isPublished' => (bool) $announcement->getAttribute('is_published'),
            'createdAt' => $announcement->getAttribute('created_at')?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 'Y-m-d H:i:s' -> 'Y-m-d\TH:i:sZ'.
     *
     * Named `pseudoUtc` and not `toIso8601` so that nobody reading a call site
     * believes the value is genuinely UTC. See the class docblock.
     */
    private static function pseudoUtc(mixed $value): ?string
    {
        if (!$value instanceof \DateTimeInterface) {
            return null;
        }

        return $value->format('Y-m-d\TH:i:s') . 'Z';
    }
}
