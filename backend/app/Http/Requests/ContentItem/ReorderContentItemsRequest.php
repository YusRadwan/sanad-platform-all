<?php

declare(strict_types=1);

namespace App\Http\Requests\ContentItem;

use App\Http\Requests\ApiFormRequest;
use App\Models\Section;
use App\Support\ContentItemAccess;

/**
 * PATCH /api/v1/sections/{sectionId}/content-items/reorder — row 25.
 *
 * Body: `{ "order": ["<item-id>", "<item-id>", …] }`. The ARRAY POSITION is
 * the new `order_index`; backend/ContentItemRepository::reorder() uses the
 * foreach key directly.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ NO `exists:content_items,id` RULE, DELIBERATELY — the same decision      │
 * │ Session A took on ReorderSectionsRequest, for the same two reasons.      │
 * │                                                                          │
 * │ backend/'s UPDATE is scoped `WHERE id = :id AND section_id = :section_id`│
 * │ so an id from a DIFFERENT section matches zero rows: no error, no        │
 * │ partial failure, request still 200. That tolerance is the ported         │
 * │ contract — the course editor's drag-and-drop can post a stale list after │
 * │ an item was deleted in another tab. And validating existence would leak  │
 * │ whether an arbitrary uuid names a real content item in someone else's    │
 * │ course.                                                                  │
 * │                                                                          │
 * │ The tenant guard is the `section_id` predicate on the UPDATE itself      │
 * │ (App\Models\ContentItem::reorderWithinSection), not this validator.      │
 * │ Pinned by ContentItemCrudTest::test_a_foreign_item_id_is_silently_       │
 * │ ignored_and_the_request_still_succeeds.                                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `order` is `sometimes|nullable|array` because backend/ContentItemController
 * reads `$request->all()['order'] ?? []` — an absent key is an empty reorder,
 * not an error.
 */
final class ReorderContentItemsRequest extends ApiFormRequest
{
    private ?Section $section = null;

    public function authorize(): bool
    {
        ContentItemAccess::assertSectionManageable($this->section());

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order' => ['sometimes', 'nullable', 'array'],
            'order.*' => ['string'],
        ];
    }

    /** @return list<string> the ids in their new order, possibly empty */
    public function orderedIds(): array
    {
        return array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            (array) ($this->input('order') ?? [])
        ));
    }

    public function section(): Section
    {
        return $this->section ??= ContentItemAccess::findSectionOrFail((string) $this->route('sectionId'));
    }
}
