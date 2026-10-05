<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Port of backend/app/Services/SectionService::present() — four keys, no more.
 *
 * `order_index` is `(int)`. MySQL hands INT back as a PHP int already, but the
 * legacy presenter cast it explicitly and the frontend's drag-and-drop sorts
 * on it numerically; the cast is kept so the guarantee is in the code rather
 * than in the driver's current behaviour.
 *
 * NOTHING ELSE IS EXPOSED. In particular there is no `isHidden`: `sections`
 * has no such column (migration 000013). backend/CourseService::structure()
 * reports a hard-coded `isHidden: false` for the course TREE because the
 * frontend reads it there — but that is a different endpoint with a different
 * shape, and it belongs to Session B/C. Inventing the key here would imply a
 * feature the table cannot support.
 *
 * @mixin Section
 */
final class SectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Section $section */
        $section = $this->resource;

        return [
            'id' => $section->getKey(),
            'course_id' => $section->getAttribute('course_id'),
            'title' => $section->getAttribute('title'),
            'order_index' => (int) $section->getAttribute('order_index'),
        ];
    }
}
