<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ContentItem;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Port of backend/CourseService::structure()'s per-section shape — the course
 * TABLE OF CONTENTS. Freeze-table row 10.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE **THIRD** CONTENT RESOURCE, AND IT HAS NO `detail` KEY AT ALL —      ║
 * ║ NOT EVEN FOR THE OWNER, NOT EVEN FOR AN ADMIN.                           ║
 * ║                                                                          ║
 * ║ ContentItemResource carries `detail`. PublicContentItemResource carries  ║
 * ║ a three-field video teaser. This one carries NEITHER, for anybody.       ║
 * ║                                                                          ║
 * ║ That is a deliberate architectural line, not an omission:                ║
 * ║ `/courses/{id}/structure` is the outline a storefront visitor sees on    ║
 * ║ every course page load. It answers "what is in this course?" — a list    ║
 * ║ of titles — and nothing else. Full per-item payloads are fetched from    ║
 * ║ GET /content-items/{id} and GET /sections/{id}/content-items, each of    ║
 * ║ which runs its own entitlement decision.                                 ║
 * ║                                                                          ║
 * ║ Making this endpoint entitlement-AWARE would be strictly worse than      ║
 * ║ useless: it would add a second, differently-shaped place where the       ║
 * ║ public/private split has to be got right, on the hottest public read in  ║
 * ║ the application, to serve data every consumer already fetches elsewhere. ║
 * ║ One shape for everyone means there is no condition here to evaluate      ║
 * ║ wrongly.                                                                 ║
 * ║                                                                          ║
 * ║ Pinned by CourseStructureTest::test_the_owner_gets_no_detail_key_either. ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── `isHidden: false` ON A SECTION IS **SYNTHETIC**, AND STAYS SYNTHETIC. ─┐
 * │ There is no `is_hidden` column on `sections` — migration 000013 does not │
 * │ have one and this session does NOT add one. backend/ fabricates the      │
 * │ constant `false` because CourseDetailsPage.tsx and                       │
 * │ CourseStructureManagement.tsx both read `section.isHidden` and would     │
 * │ render `undefined` without it.                                          │
 * │                                                                          │
 * │ Ported exactly. Adding a real column would be a schema change with a     │
 * │ hiding RULE behind it that nothing in the product asks for, and          │
 * │ removing the key would break two live components. The constant is the    │
 * │ honest port of a shape-compatibility hack.                               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── HIDDEN ITEMS ARE FILTERED, NOT REFUSED — AND THE FILTER IS UPSTREAM. ──┐
 * │ backend/ drops `is_hidden = 1` rows from the lesson list for anyone who  │
 * │ fails canSeeHidden(), rather than 404ing the whole tree. That belongs in │
 * │ the CONTROLLER (CourseController::structure), which has the Gate answer  │
 * │ once for the whole course, not in this Resource — a Resource that        │
 * │ consulted a Gate per row would put an authorization decision inside a    │
 * │ serialiser and cost one query per lesson.                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * @mixin Section
 */
final class CourseStructureResource extends JsonResource
{
    /**
     * @param  Section  $resource
     * @param  list<ContentItem>  $lessons  already filtered and ordered by the controller
     */
    public function __construct($resource, private array $lessons)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Section $section */
        $section = $this->resource;

        return [
            'id' => $section->getKey(),
            'title' => $section->getAttribute('title'),
            'order_index' => (int) $section->getAttribute('order_index'),
            // Synthetic. There is no such column. See the class docblock.
            'isHidden' => false,
            'lessons' => array_map(self::lesson(...), $this->lessons),
        ];
    }

    /**
     * The NINE keys backend/CourseService::structure() emits per lesson.
     *
     * Written out literally rather than delegated to either sibling Resource,
     * because it is neither of them: it has no `detail`, no `courseId`, no
     * video teaser, and it keeps BOTH `is_hidden` spellings (backend/ emits
     * both here, exactly as ContentItemResource does).
     *
     * A future field added to the content-item payload must NOT arrive here by
     * inheritance. That is the point of the duplication.
     *
     * @return array<string, mixed>
     */
    private static function lesson(ContentItem $item): array
    {
        return [
            'id' => $item->getKey(),
            'title' => $item->getAttribute('title'),
            'type' => $item->getAttribute('type')?->value,
            'order_index' => (int) $item->getAttribute('order_index'),
            'is_hidden' => (bool) $item->getAttribute('is_hidden'),
            'isHidden' => (bool) $item->getAttribute('is_hidden'),
            'description' => $item->getAttribute('description'),
            'start_at' => $item->getAttribute('start_at')?->format('Y-m-d H:i:s'),
            'end_at' => $item->getAttribute('end_at')?->format('Y-m-d H:i:s'),
        ];
    }
}
