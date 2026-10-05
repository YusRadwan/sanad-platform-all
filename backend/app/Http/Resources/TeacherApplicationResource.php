<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TeacherApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /admin/teacher-applications listing shape. One class for both the
 * create-response echo and the admin list — there is no separate "detail"
 * route, the request explicitly asked only for a list showing every field.
 */
final class TeacherApplicationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TeacherApplication $application */
        $application = $this->resource;

        return [
            'id' => (string) $application->getKey(),
            'full_name' => $application->full_name,
            'phone' => $application->phone,
            'whatsapp' => $application->whatsapp,
            'governorate' => $application->governorate,
            'age' => $application->age,
            'years_of_experience' => $application->years_of_experience,
            'subject' => $application->subject,
            'section_name' => $application->section_name,
            'grades' => $application->grades,
            'social_links' => $application->social_links,
            'created_at' => $application->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
