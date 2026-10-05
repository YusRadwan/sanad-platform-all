<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TeacherSocialLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of `teacher_social_links`, in the shape
 * frontend/src/types/teacher.ts's SocialLink declares.
 *
 * Port of backend/TeacherRepository::decodeSocialLink(). The ONE translation
 * it performs is `order_index` (the column) -> `order` (the wire name); doing
 * it here means the model can speak the schema's language and exactly one
 * class knows about the rename.
 *
 * @mixin TeacherSocialLink
 */
final class SocialLink extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TeacherSocialLink $link */
        $link = $this->resource;

        return [
            'id' => (string) $link->getKey(),
            'platform' => (string) $link->getAttribute('platform'),
            'url' => (string) $link->getAttribute('url'),
            // TINYINT(1)/INT arrive as ints; the frontend declares
            // `enabled: boolean` and `order: number`. The model casts do the
            // work — these are just where they surface.
            'enabled' => (bool) $link->getAttribute('enabled'),
            'order' => (int) $link->getAttribute('order_index'),
        ];
    }
}
