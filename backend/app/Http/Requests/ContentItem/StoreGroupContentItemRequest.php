<?php

declare(strict_types=1);

namespace App\Http\Requests\ContentItem;

use App\Enums\ContentItemType;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\ContentItem\Concerns\ValidatesContentDetail;
use App\Models\CourseGroup;
use App\Support\ContentItemAccess;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/groups/{groupId}/content-items — freeze-table row 79.
 *
 * THE REFUSAL LADDER, from backend/ContentItemService::createForGroup() ->
 * requireGroupAccess():
 *
 *   404 'Group not found'              the id names nothing
 *   404 'Course not found'             the group's course was soft-deleted
 *   403 'You do not own this group'    ← the GROUP wording. Session A used the
 *                                        COURSE wording on rows 75-77 and said
 *                                        explicitly that this one differs
 *                                        (decision 5.4).
 *   422                                a bad type or a bad body
 *
 * ┌── `type` DEFAULTS TO 'note' HERE AND IS REQUIRED ON THE SECTION ROUTE. ──┐
 * │ createForGroup() reads `$data['type'] ?? 'note'`; create() reads         │
 * │ `$data['type'] ?? null` and rejects the miss. The default exists because │
 * │ this endpoint was built for NotesManagement.tsx first and the other      │
 * │ three consumers (videoApiService, linkApiService,                        │
 * │ examAssignmentApiService) were added later, each sending an explicit     │
 * │ `type`. Ported as-is.                                                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── `title` WAS UNVALIDATED AND IS NOW REQUIRED — A 500 BECOMES A 422. ────┐
 * │ createForGroup() reads `$data['title']` with no `??`, so                 │
 * │ POST /groups/{id}/content-items with no title was an "Undefined array    │
 * │ key" 500 — the exact bug Session A fixed on POST /courses/{id}/sections  │
 * │ and recorded as decision 5.2. Same class of fix, third occurrence.       │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class StoreGroupContentItemRequest extends ApiFormRequest
{
    use ValidatesContentDetail;

    private ?CourseGroup $group = null;

    public function authorize(): bool
    {
        ContentItemAccess::assertGroupManageable($this->group());

        return true;
    }

    /**
     * `$data['type'] ?? 'note'` — and `??` treats an explicit null as absent
     * too, so the default is applied for both. Injecting it here rather than
     * in the controller means detailRules() and the persister factory see the
     * same resolved value the write will use; there is no second place for the
     * default to drift to.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('type') === null) {
            $this->merge(['type' => ContentItemType::Note->value]);
        }

        // `''` on a DATETIME/DECIMAL field means "not sent" — see the trait.
        $this->dropBlankTypedFields();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(
            [
                'title' => ['required', 'string', 'max:190'],
                'type' => ['required', 'string', Rule::in(ContentItemType::values())],
            ],
            $this->parentRules(),
            $this->detailRules($this->resolvedType()),
        );
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'type.required' => 'نوع المحتوى غير صحيح',
            'type.in' => 'نوع المحتوى غير صحيح',
        ]);
    }

    public function resolvedType(): ?ContentItemType
    {
        $type = $this->input('type');

        return is_string($type) ? ContentItemType::tryFrom($type) : null;
    }

    public function group(): CourseGroup
    {
        return $this->group ??= ContentItemAccess::findGroupOrFail((string) $this->route('groupId'));
    }
}
