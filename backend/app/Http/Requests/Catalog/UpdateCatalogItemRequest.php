<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Enums\CatalogType;
use App\Http\Requests\ApiFormRequest;
use Closure;

/**
 * PATCH /api/v1/catalogs/{type}/{id} — the RENAME shape.
 *
 * Deliberately a near-clone of StoreCatalogItemRequest rather than a shared
 * parent: the two differ in exactly one predicate (the duplicate check excludes
 * the row being renamed) and the Arabic messages are a CONTRACT that must stay
 * byte-identical to the create path's, so they are repeated verbatim rather
 * than abstracted into something a future edit could drift.
 *
 * The uniqueness rule goes through CatalogType::query(), not through
 * Rule::unique(...)->ignore($id) — same reason as the create path: the
 * duplicate check sees exactly the rows GET /catalogs/{type} lists.
 * `whereKeyNot` then lets a no-op rename (same name, same row) through
 * instead of failing on the row itself.
 */
final class UpdateCatalogItemRequest extends ApiFormRequest
{
    /**
     * Trimmed before validation, exactly as the create path does — so "   " is
     * a MISSING name, not a three-character one.
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
        $id = (string) $this->route('id');

        return [
            // `bail` mirrors the create path: report the FIRST failing check
            // for this field rather than accumulating them.
            'name' => [
                'bail',
                'required',
                'string',
                'max:100',
                function (string $attribute, mixed $value, Closure $fail) use ($type, $id): void {
                    if ($type->query()->whereKeyNot($id)->where('name', $value)->exists()) {
                        $fail('هذا الاسم موجود بالفعل في القائمة');
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'الاسم مطلوب',
            'name.string' => 'الاسم مطلوب',
            'name.max' => 'الاسم يجب ألا يزيد عن 100 حرف',
        ];
    }

    /** The validated, trimmed name. */
    public function name(): string
    {
        return (string) $this->validated('name');
    }
}
