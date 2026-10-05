<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CourseGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Port of backend/app/Services/GroupService::present() — five keys.
 *
 * `is_hidden` ships as a real JSON boolean. The column is TINYINT(1) and MySQL
 * returns PHP int 1|0 (proven in tests/Feature/PdoTypeCastingTest.php), while
 * frontend/src/services/groupApiService.ts declares `isHidden: boolean`. The
 * model's cast already does this; the `(bool)` here is the same belt-and-braces
 * the legacy presenter applied.
 *
 * SNAKE CASE ONLY — no `isHidden` twin, unlike CourseResource's several
 * duplicated keys. backend/'s group presenter emits `is_hidden` alone and the
 * group service in the frontend maps it itself, so adding a camelCase alias
 * would invent a contract rather than preserve one.
 *
 * @mixin CourseGroup
 */
final class CourseGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CourseGroup $group */
        $group = $this->resource;

        return [
            'id' => $group->getKey(),
            'course_id' => $group->getAttribute('course_id'),
            'title' => $group->getAttribute('title'),
            'is_hidden' => (bool) $group->getAttribute('is_hidden'),
            'order_index' => (int) $group->getAttribute('order_index'),
            // Open decision 10.21 — formatted the same way ContentItem's own
            // start_at/end_at are (CourseStructureResource::lesson()).
            'start_at' => $group->getAttribute('start_at')?->format('Y-m-d H:i:s'),
            'end_at' => $group->getAttribute('end_at')?->format('Y-m-d H:i:s'),
        ];
    }
}
