<?php

declare(strict_types=1);

namespace App\Http\Requests\ContentItem;

use App\Enums\ContentItemType;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\ContentItem\Concerns\ValidatesContentDetail;
use App\Models\Section;
use App\Support\ContentItemAccess;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/sections/{sectionId}/content-items — freeze-table row 23.
 *
 * THE REFUSAL LADDER, verbatim from backend/ContentItemService::create() ->
 * requireOwnsSection() -> assertOwnsCourseId():
 *
 *   404 'Section not found'              the id names nothing
 *   404 'Course not found'               the section's course was soft-deleted
 *   403 'You do not own this section'    ← the SECTION wording, not the course
 *                                          one Session A used on rows 17/19.
 *                                          Open decision 3.4, second half.
 *   422                                  a bad type or a bad body
 *
 * ┌── OWNERSHIP BEFORE VALIDATION, AND THAT ORDER IS READ OFF backend/. ─────┐
 * │ requireOwnsSection() is create()'s FIRST statement, before the type is   │
 * │ even looked at. Laravel runs authorize() before rules(), so putting the  │
 * │ gate here reproduces it exactly: a foreign instructor sending a          │
 * │ malformed body gets 403, not 422.                                        │
 * │                                                                          │
 * │ Contrast the GROUP route in this same session, where backend/ ALSO gates │
 * │ first — so both content-item creates agree. The section-vs-group         │
 * │ ordering split recorded as decision 5.5 is a property of                 │
 * │ SectionService/GroupService, NOT of ContentItemService, and does not     │
 * │ carry over. Verified rather than assumed.                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `type` IS REQUIRED HERE AND OPTIONAL ON THE GROUP ROUTE. Not an
 * inconsistency to fix: create() reads `$data['type'] ?? null` and rejects a
 * miss, while createForGroup() reads `$data['type'] ?? 'note'`. Two call
 * sites, two defaults, both ported.
 */
final class StoreSectionContentItemRequest extends ApiFormRequest
{
    use ValidatesContentDetail;

    private ?Section $section = null;

    public function authorize(): bool
    {
        ContentItemAccess::assertSectionManageable($this->section());

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
                'title' => ['required', 'string', 'max:190'],
                'type' => ['required', 'string', Rule::in(ContentItemType::values())],
            ],
            $this->parentRules(),
            $this->detailRules($this->resolvedType()),
        );
    }

    /**
     * backend/ throws ValidationException(['type' => ['نوع المحتوى غير صحيح']])
     * for BOTH a missing type and an unrecognised one — the same `!in_array`
     * check answers both — so both rule keys carry that one string.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'type.required' => 'نوع المحتوى غير صحيح',
            'type.in' => 'نوع المحتوى غير صحيح',
        ]);
    }

    /**
     * The submitted type, or null when it is absent/unrecognised — in which
     * case the `in` rule above is what fails, and detailRules() contributes
     * nothing rather than guessing at a shape.
     */
    public function resolvedType(): ?ContentItemType
    {
        $type = $this->input('type');

        return is_string($type) ? ContentItemType::tryFrom($type) : null;
    }

    public function section(): Section
    {
        return $this->section ??= ContentItemAccess::findSectionOrFail((string) $this->route('sectionId'));
    }
}
