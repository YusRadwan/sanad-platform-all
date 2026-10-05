<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ContentItemType;
use App\Models\ContentItem;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Port of backend/ContentItemService::presentPublic() — the UNENTITLED
 * representation, served to guests, unsubscribed students, expired and
 * cancelled subscribers.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS IS A **SEPARATE CLASS**, NOT A `$this->when($entitled, …)` BRANCH   ║
 * ║ INSIDE ContentItemResource. THAT IS THE ENTIRE SECURITY ARGUMENT OF      ║
 * ║ STAGE 5 / SESSION C, AND IT MUST NOT BE "SIMPLIFIED" BACK.               ║
 * ║                                                                          ║
 * ║ A conditional payload leaks the moment its condition is evaluated        ║
 * ║ wrongly — a stale `$entitled`, a `when()` that got the truthiness        ║
 * ║ backwards, a Resource re-used on a route whose flag nobody threaded      ║
 * ║ through. Every one of those is a review-time mistake, i.e. the kind      ║
 * ║ that ships.                                                              ║
 * ║                                                                          ║
 * ║ Two classes make the failure mode structural instead: this class has no  ║
 * ║ code path — none, not one branch — that can reach `notes.content_text`,  ║
 * ║ `notes.file_path`, `notes.file_name`, `links.url`, `videos.video_url`,   ║
 * ║ `exams.pass_score`/`total_score`/`question_group_id`, or any            ║
 * ║ `assignments` column. It never calls ContentItem::detail(), so the four  ║
 * ║ non-video detail models are not merely unserialised — they are          ║
 * ║ unreachable. The forbidden keys cannot appear because there is no line   ║
 * ║ of code that could write them.                                          ║
 * ║                                                                          ║
 * ║ Session B built ContentItemResource as private-only on purpose, so that  ║
 * ║ this session would be FORCED to write a real second class rather than    ║
 * ║ retrofit conditionals into the first. Honour that.                       ║
 * ║                                                                          ║
 * ║ Pinned by tests/Feature/ContentItem/PublicPayloadLeakTest.php, which     ║
 * ║ serialises this class against a fully-populated instance of ALL SIX      ║
 * ║ content types and asserts, recursively, that not one forbidden key or    ║
 * ║ forbidden VALUE appears anywhere in the resulting JSON.                  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── WHAT IS EXPOSED, AND WHY EACH ONE IS SAFE. ────────────────────────────┐
 * │ The base `content_items` columns are syllabus metadata a logged-out      │
 * │ visitor is MEANT to see — the storefront's purchase incentive, and the   │
 * │ same set CourseService::structure() already publishes.                   │
 * │                                                                          │
 * │ `description` is `content_items.description`, a column DISTINCT from     │
 * │ `notes.content_text`. Exposing it leaks no paid body text; conflating    │
 * │ the two would.                                                           │
 * │                                                                          │
 * │ For `type = video` ONLY, three `videos` columns join it:                 │
 * │   duration      — the lesson's length in seconds. A number.              │
 * │   is_preview    — whether this lesson is a free sample. The storefront   │
 * │                   badges it; hiding it would hide the giveaway.          │
 * │   thumbnail_url — the marketing POSTER image, not the stream. It is      │
 * │                   already rendered in the public course card.            │
 * │                                                                          │
 * │ `videos.video_url` and `videos.provider` are NOT here and never may be:  │
 * │ the URL IS the paid product, and playback goes through                   │
 * │ GET /lessons/{id}/video, which enforces the subscription gate.           │
 * │                                                                          │
 * │ The OTHER FIVE TYPES EXPOSE ZERO DETAIL FIELDS. Not `detail: null`, not  │
 * │ `detail: {}` — the key does not exist, so there is not even a            │
 * │ null-shaped hint that a paid payload is being withheld.                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── THE THREE DUPLICATED SPELLINGS ARE PORTED CONTRACT, NOT SLOPPINESS. ───┐
 * │  course_id / courseId  — CourseSections.tsx matches globalVideos on      │
 * │                          `courseId`; other consumers read `course_id`.   │
 * │  is_hidden / isHidden  — same split as ContentItemResource.              │
 * │  isFree / isPreview    — CourseSections.tsx badges a free lesson from    │
 * │                          `isFree`; both spellings ship, both from the    │
 * │                          same column. backend/ emits both.               │
 * │ Dropping any of the six breaks a live consumer.                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * DATETIMES ARE 'Y-m-d H:i:s' — identical reasoning to ContentItemResource:
 * backend/ shipped the raw MySQL DATETIME string and videoApiService.ts's
 * splitDateTime() slices it positionally.
 *
 * @mixin ContentItem
 */
final class PublicContentItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ContentItem $item */
        $item = $this->resource;

        $type = $item->getAttribute('type');

        $safe = [
            'id' => $item->getKey(),
            'section_id' => $item->getAttribute('section_id'),
            'group_id' => $item->getAttribute('group_id'),
            'course_id' => $item->getAttribute('course_id'),
            'courseId' => $item->getAttribute('course_id'),
            'title' => $item->getAttribute('title'),
            'type' => $type?->value,
            'order_index' => (int) $item->getAttribute('order_index'),
            'is_hidden' => (bool) $item->getAttribute('is_hidden'),
            'isHidden' => (bool) $item->getAttribute('is_hidden'),
            'description' => $item->getAttribute('description'),
            'start_at' => $item->getAttribute('start_at')?->format('Y-m-d H:i:s'),
            'end_at' => $item->getAttribute('end_at')?->format('Y-m-d H:i:s'),
        ];

        if ($type !== ContentItemType::Video) {
            return $safe;
        }

        return $safe + self::videoTeaser($item);
    }

    /**
     * The ONLY place in this class that touches a detail table, and it can
     * only ever touch `videos`.
     *
     * ┌── THE `instanceof Video` GUARD IS LOAD-BEARING. ─────────────────────┐
     * │ It is not defensive noise about a relation that "should" be a Video. │
     * │ It is what makes the three reads below provably confined to the      │
     * │ `videos` table even if a caller hands this Resource an item whose    │
     * │ `type` column says video while some future code path attached a      │
     * │ different relation. Without it, `getAttribute('duration')` on an     │
     * │ Exam would happily return `duration_minutes`-adjacent state.         │
     * │                                                                      │
     * │ Note it reads `$item->video` — the NAMED relation — rather than      │
     * │ ContentItem::detail(), which dispatches on `type` and can therefore  │
     * │ return any of the five detail models. detail() is the private        │
     * │ Resource's tool; this class must never call it.                      │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * A null relation is a REAL, EXPECTED state — a video created without its
     * URL never got a `videos` row (see PersistVideoDetail) — and backend/
     * answered `duration: null, isFree: false, isPreview: false,
     * thumbnailUrl: null` for it rather than omitting the keys. Ported.
     *
     * @return array<string, mixed>
     */
    private static function videoTeaser(ContentItem $item): array
    {
        $video = $item->getRelationValue('video');

        if (!$video instanceof Video) {
            return [
                'duration' => null,
                'isFree' => false,
                'isPreview' => false,
                'thumbnailUrl' => null,
            ];
        }

        $duration = $video->getAttribute('duration');
        $isPreview = (bool) $video->getAttribute('is_preview');

        return [
            'duration' => $duration === null ? null : (int) $duration,
            // CourseSections.tsx reads `isFree` to badge a free-preview lesson.
            'isFree' => $isPreview,
            'isPreview' => $isPreview,
            'thumbnailUrl' => $video->getAttribute('thumbnail_url'),
        ];
    }
}
