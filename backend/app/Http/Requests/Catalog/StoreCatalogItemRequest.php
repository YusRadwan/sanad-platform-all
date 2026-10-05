<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Enums\CatalogType;
use App\Http\Requests\ApiFormRequest;
use App\Models\GradeSection;
use Closure;

/**
 * POST /api/v1/catalogs/{type} — the only write shape the flat catalogs take.
 *
 * Port of the inline checks in backend/app/Services/CatalogService::create(),
 * with its three Arabic messages preserved verbatim. They are generic ("الاسم"
 * rather than "اسم الصف") because ONE endpoint serves all three catalogs and
 * the legacy code shared one message set across them.
 *
 * The uniqueness rule goes through CatalogType::query(), not through
 * Rule::unique('grades', 'name'), so the duplicate check sees exactly the
 * rows GET /catalogs/{type} lists — one predicate, one place, no drift.
 * uq_grades_name backstops it at the database level.
 */
final class StoreCatalogItemRequest extends ApiFormRequest
{
    /**
     * Trimmed before validation, exactly as the legacy service did with
     * trim((string) ($data['name'] ?? '')) — so "   " is a MISSING name, not a
     * three-character one.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = CatalogType::fromRoute((string) $this->route('type'));

        $rules = [
            // `bail` reproduces the legacy control flow: it returned on the
            // FIRST failing check for this field rather than accumulating
            // "required" and "too long" together.
            'name' => [
                'bail',
                'required',
                'string',
                'max:100',
                function (string $attribute, mixed $value, Closure $fail) use ($type): void {
                    if ($type->query()->where('name', $value)->exists()) {
                        $fail('هذا الاسم موجود بالفعل في القائمة');
                    }
                },
            ],
        ];

        // Grades are the one catalog scoped by another — see open decision
        // 10.12. Every grade must name a real, already-existing section.
        if ($type === CatalogType::Grades) {
            $rules['section_id'] = [
                'bail',
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (!GradeSection::query()->whereKey($value)->exists()) {
                        $fail('القسم الدراسي غير موجود');
                    }
                },
            ];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'الاسم مطلوب',
            'name.string' => 'الاسم مطلوب',
            'name.max' => 'الاسم يجب ألا يزيد عن 100 حرف',
            'section_id.required' => 'القسم الدراسي مطلوب',
            'section_id.string' => 'القسم الدراسي غير صحيح',
        ];
    }

    /** The validated, trimmed name. */
    public function name(): string
    {
        return (string) $this->validated('name');
    }

    /** The validated section id, or null for catalog types that have none. */
    public function sectionId(): ?string
    {
        $type = CatalogType::fromRoute((string) $this->route('type'));

        return $type === CatalogType::Grades ? (string) $this->validated('section_id') : null;
    }
}
