<?php

declare(strict_types=1);

namespace App\Http\Requests\ContentItem;

use App\Http\Requests\ApiFormRequest;
use App\Models\ContentItem;
use App\Support\ContentItemAccess;

/**
 * DELETE /api/v1/content-items/{id} — freeze-table row 26.
 *
 * Same ladder as the update route (404 item, 404 course, 403 item wording);
 * see UpdateContentItemRequest.
 *
 * ┌── HARD DELETE, AND **NOT** AUDITED. BOTH ARE PORTED PROPERTIES. ─────────┐
 * │ `content_items` has no `deleted_at` (migration 000014), so this is a     │
 * │ real DELETE. Its detail row goes with it — fk_videos_content_item,       │
 * │ fk_links_content_item, fk_notes_content_item, fk_exams_content_item and  │
 * │ fk_assignments_content_item are all ON DELETE CASCADE — so nothing in    │
 * │ the controller has to remember to clean up, and nothing may start        │
 * │ doing so by hand.                                                        │
 * │                                                                          │
 * │ NO SecurityLog ENTRY. backend/ContentItemService::delete() writes none,  │
 * │ and neither does its section or group sibling (freeze-table rows 19 and  │
 * │ 77 both say "بلا تدقيق"). The only audited deletion in the course        │
 * │ family is DELETE /courses/{id} itself. Inventing an audit event here     │
 * │ would put a log line in production that no operator's runbook expects.   │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * No rules: a DELETE carries no body. authorize() is the whole request.
 */
final class DeleteContentItemRequest extends ApiFormRequest
{
    private ?ContentItem $item = null;

    public function authorize(): bool
    {
        ContentItemAccess::assertItemManageable($this->item());

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function item(): ContentItem
    {
        return $this->item ??= ContentItemAccess::findOrFail((string) $this->route('id'));
    }
}
