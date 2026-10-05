<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Grade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `{id, name}` pair every option-list endpoint emits, plus `sectionId`
 * for grades only (open decision 10.12) — the one relationship a catalog
 * entry carries to another catalog. Matches catalogService.ts's
 * `CatalogItem` TypeScript interface.
 *
 * The whitelist is the point: only these fields are ever serialised, so a
 * future column added to any of the catalog models cannot leak through this
 * endpoint by accident.
 */
final class CatalogItem extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'name' => (string) $this->resource->getAttribute('name'),
            'sectionId' => $this->when(
                $this->resource instanceof Grade,
                fn () => $this->resource->getAttribute('section_id')
            ),
        ];
    }
}
