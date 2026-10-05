<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Http\Requests\ApiFormRequest;

/**
 * DELETE /api/v1/announcements/{id}. Freeze-table row 112.
 *
 * HARD delete, matching backend/AnnouncementRepository::delete() — the table
 * has no deleted_at column and the model uses no SoftDeletes trait.
 *
 * Same 404-then-403 ladder as PATCH, through the same trait, so the two verbs
 * cannot drift apart. No rules: there is no body.
 */
final class DeleteAnnouncementRequest extends ApiFormRequest
{
    use ResolvesAnnouncement;

    public function authorize(): bool
    {
        $this->assertManageable();

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
