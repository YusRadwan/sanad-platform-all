<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Enums\CatalogType;
use App\Http\Requests\ApiFormRequest;
use App\Models\Grade;
use App\Models\GradeSection;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/v1/catalogs/{type}/reorder — {order: [id1, id2, ...]}.
 *
 * `order` must be an EXACT PERMUTATION of the catalog's current row ids: same
 * count, same set, no duplicates, no unknown id. Anything less would make the
 * controller's `foreach ($orderedIds as $index => $id)` silently accept a
 * partial or malformed list and leave the catalog in a state no admin asked
 * for — a validation concern, not something to discover mid-transaction.
 *
 * A catalog type that does not support ordering (subjects — see
 * CatalogType::supportsOrdering()) is rejected here, before the controller
 * ever runs a query, with the same 'نوع القائمة غير معروف'-style precision
 * the rest of this Form Request family uses for out-of-scope input.
 *
 * Grades are reordered WITHIN A SECTION (open decision 10.12): `section_id`
 * is required for this type, and the exact-permutation check compares
 * against that section's grades only — not every grade in the catalog. Every
 * other orderable type (currently only grade_sections) is reordered as one
 * flat, unscoped list, exactly as before.
 */
final class ReorderCatalogRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = CatalogType::fromRoute((string) $this->route('type'));

        $rules = [
            'order' => ['required', 'array'],
            'order.*' => ['required', 'string'],
        ];

        if ($type === CatalogType::Grades) {
            $rules['section_id'] = ['bail', 'required', 'string'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'order.required' => 'قائمة الترتيب مطلوبة',
            'order.array' => 'قائمة الترتيب غير صحيحة',
            'order.*.required' => 'قائمة الترتيب تحتوي عنصراً فارغاً',
            'order.*.string' => 'قائمة الترتيب تحتوي عنصراً غير صحيح',
            'section_id.required' => 'القسم الدراسي مطلوب',
            'section_id.string' => 'القسم الدراسي غير صحيح',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = CatalogType::fromRoute((string) $this->route('type'));

            if (!$type->supportsOrdering()) {
                $validator->errors()->add('order', 'هذا النوع من القوائم لا يدعم إعادة الترتيب');

                return;
            }

            $submitted = (array) $this->input('order', []);

            // Only reachable once the field-level rules above already passed,
            // so every element here is confirmed a string — safe to compare
            // as a plain set against the catalog's real ids.
            if (count($submitted) !== count(array_unique($submitted))) {
                $validator->errors()->add('order', 'قائمة الترتيب تحتوي عنصراً مكرراً');

                return;
            }

            if ($type === CatalogType::Grades) {
                $sectionId = (string) $this->input('section_id', '');

                if ($sectionId === '' || !GradeSection::query()->whereKey($sectionId)->exists()) {
                    $validator->errors()->add('section_id', 'القسم الدراسي غير موجود');

                    return;
                }

                $actual = Grade::query()->where('section_id', $sectionId)->pluck('id')->all();
            } else {
                $actual = $type->query()->pluck($type->query()->getModel()->getKeyName())->all();
            }

            if (array_diff($submitted, $actual) !== [] || array_diff($actual, $submitted) !== []) {
                $validator->errors()->add('order', 'قائمة الترتيب يجب أن تطابق عناصر القائمة الحالية تماماً');
            }
        });
    }

    /** @return list<string> */
    public function orderedIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->validated('order');

        return $ids;
    }

    /** The validated section id, or null for catalog types that have none. */
    public function sectionId(): ?string
    {
        $type = CatalogType::fromRoute((string) $this->route('type'));

        return $type === CatalogType::Grades ? (string) $this->validated('section_id') : null;
    }
}
