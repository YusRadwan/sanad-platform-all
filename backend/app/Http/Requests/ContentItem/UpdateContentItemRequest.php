<?php

declare(strict_types=1);

namespace App\Http\Requests\ContentItem;

use App\Enums\ContentItemType;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\ContentItem\Concerns\ValidatesContentDetail;
use App\Models\ContentItem;
use App\Support\ContentItemAccess;

/**
 * PUT /api/v1/content-items/{id} — freeze-table row 24.
 *
 * THE REFUSAL LADDER, from backend/ContentItemService::update():
 *
 *   404 'Content item not found'           the id names nothing
 *   404 'Course not found'                 the item's course was soft-deleted
 *   403 'You do not own this content item' ← the ITEM wording, a third string
 *   422                                    a bad body / an unsafe URL
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE TYPE IS TAKEN FROM THE STORED ROW, NEVER FROM THE REQUEST.          ║
 * ║                                                                          ║
 * ║   $data = $this->validateUrls($data, (string) $item['type']);            ║
 * ║   …                                                                      ║
 * ║   $this->saveTypeDetail($id, $item['type'], $data);                      ║
 * ║                                                                          ║
 * ║ Both read `$item['type']`. There is NO rule accepting `type` below and   ║
 * ║ there must not be: a content item cannot change type through this route  ║
 * ║ (a video that became a note would strand its `videos` row and its        ║
 * ║ `notes` row would never be created), and accepting the key while         ║
 * ║ ignoring it is how a caller comes to believe it works.                    ║
 * ║                                                                          ║
 * ║ It also matters for SAFETY: the URL rules are selected by the STORED     ║
 * ║ type, so a caller cannot post `type: 'note'` alongside a hostile         ║
 * ║ `video_url` and have the allow-list skipped.                              ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── FIELD SEMANTICS. `''` AND `null` MEAN DIFFERENT THINGS. ───────────────┐
 * │ backend/'s parent write is                                               │
 * │   array_filter([...], fn ($v) => $v !== null)                            │
 * │ over `title`, `is_hidden`, `description`, `start_at`, `end_at`, each     │
 * │ read as `$data[…] ?? null`. So:                                          │
 * │                                                                          │
 * │  field        absent     null       ''                                   │
 * │  ───────────  ─────────  ─────────  ───────────────────────────────────  │
 * │  title        untouched  untouched  WRITTEN (VARCHAR, '' is legal)       │
 * │  description  untouched  untouched  **CLEAR** (TEXT, '' is legal)        │
 * │  is_hidden    untouched  n/a        n/a — presence-based, `false` writes │
 * │  start_at     untouched  untouched  **422** (was a 1292 500 — see        │
 * │  end_at                             ValidatesContentDetail::parentRules)│
 * │                                                                          │
 * │ The `description: ''` row is why `api/v1/content-items/*` had to be      │
 * │ added to AppServiceProvider::preserveEmptyStringsOnClearableWrites() —   │
 * │ open decision 4.1's standing obligation. Without it Laravel's global     │
 * │ ConvertEmptyStringsToNull rewrites the '' to null before any rule runs,  │
 * │ "clear this description" silently becomes a no-op, and the request still │
 * │ answers 200. Measured, not assumed: the semantics suite fails without    │
 * │ the entry.                                                               │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class UpdateContentItemRequest extends ApiFormRequest
{
    use ValidatesContentDetail;

    private ?ContentItem $item = null;

    public function authorize(): bool
    {
        ContentItemAccess::assertItemManageable($this->item());

        return true;
    }

    /** `''` on a DATETIME/DECIMAL field means "not sent" — see the trait. */
    protected function prepareForValidation(): void
    {
        $this->dropBlankTypedFields();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(
            [
                // `nullable`: null means "leave it", matching `?? null` +
                // array_filter. '' passes `string` on purpose — see the
                // semantics table in the class docblock.
                'title' => ['sometimes', 'nullable', 'string', 'max:190'],
                // Update-only, deliberately NOT in the shared parentRules()
                // trait (also used by the two store requests) — creation
                // assigns its own order_index server-side; only an existing
                // item's position should ever be client-settable. Lets
                // CourseStructureManagement.tsx persist a drag-reorder within
                // a group with one PUT per item, no new /reorder route.
                'order_index' => ['sometimes', 'integer', 'min:0'],
            ],
            $this->parentRules(),
            $this->detailRules($this->storedType()),
        );
    }

    /** The type of the row being edited — the ONLY source of truth. */
    public function storedType(): ?ContentItemType
    {
        return $this->item()->getAttribute('type');
    }

    public function item(): ContentItem
    {
        return $this->item ??= ContentItemAccess::findOrFail((string) $this->route('id'));
    }
}
