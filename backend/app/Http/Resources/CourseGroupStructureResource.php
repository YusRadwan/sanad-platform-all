<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ContentItem;
use App\Models\CourseGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The group-owned twin of CourseStructureResource — GET /courses/{id}/structure
 * (freeze-table row 10), open decision 10.21.
 *
 * WRITTEN OUT LITERALLY RATHER THAN SHARED with CourseStructureResource, for
 * the exact reason that class's own docblock gives: it has no `detail`, no
 * `courseId`, and a future field must not arrive here by inheritance from a
 * sibling that happens to look similar today.
 *
 * ┌── `isHidden` HERE IS REAL, UNLIKE THE SECTION'S SYNTHETIC `false`. ──────┐
 * │ `course_groups.is_hidden` is a real column (unlike `sections`, which has │
 * │ none). This resource is only ever handed a group the caller has already  │
 * │ decided is visible (CourseController::structure() filters BEFORE         │
 * │ constructing it, same pattern as the hidden-lesson filter) — so `isHidden`│
 * │ is always `false` in practice for a non-owner, but it reports the real   │
 * │ column rather than fabricating a constant, since an owner/admin previewing│
 * │ their own hidden groups DOES see `true` here.                            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * @mixin CourseGroup
 */
final class CourseGroupStructureResource extends JsonResource
{
    /**
     * @param  CourseGroup  $resource
     * @param  list<ContentItem>  $lessons  already filtered and ordered by the controller
     */
    public function __construct($resource, private array $lessons)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CourseGroup $group */
        $group = $this->resource;

        return [
            'id' => $group->getKey(),
            'title' => $group->getAttribute('title'),
            'order_index' => (int) $group->getAttribute('order_index'),
            'isHidden' => (bool) $group->getAttribute('is_hidden'),
            'lessons' => array_map(self::lesson(...), $this->lessons),
        ];
    }

    /**
     * Identical shape to CourseStructureResource::lesson() — deliberately
     * duplicated, not shared, per this class's own banner above.
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
