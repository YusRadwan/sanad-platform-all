<?php

declare(strict_types=1);

namespace App\Http\Requests\Section;

use App\Http\Requests\ApiFormRequest;
use App\Models\Course;
use App\Support\CourseAccess;

/**
 * PATCH /api/v1/courses/{courseId}/sections/reorder — freeze-table row 18.
 *
 * Body: `{ "order": ["<section-id>", "<section-id>", …] }`. The ARRAY POSITION
 * is the new `order_index`; backend/SectionRepository::reorder() uses the
 * foreach key directly (`$stmt->execute(['order_index' => $index, …])`).
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ NO `exists:sections,id` RULE, DELIBERATELY.                             │
 * │                                                                          │
 * │ backend/'s UPDATE is scoped `WHERE id = :id AND course_id = :course_id`, │
 * │ so an id belonging to a DIFFERENT course simply matches zero rows: no    │
 * │ error, no partial failure, request still 200. That tolerance is the      │
 * │ ported contract — the frontend's drag-and-drop can post a stale list     │
 * │ after a section was deleted in another tab, and turning that into a 422  │
 * │ would break a working flow. Validating existence here would ALSO leak    │
 * │ whether an arbitrary uuid names a real section in someone else's course. │
 * │                                                                          │
 * │ Pinned by SectionCrudTest::test_a_foreign_section_id_is_silently_        │
 * │ ignored_and_the_request_still_succeeds.                                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `order` itself is `sometimes|array` because backend/SectionController reads
 * `$request->all()['order'] ?? []` — an absent key is an empty reorder, not an
 * error.
 */
final class ReorderSectionsRequest extends ApiFormRequest
{
    private ?Course $course = null;

    public function authorize(): bool
    {
        CourseAccess::assertManageable($this->course());

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

    public function course(): Course
    {
        return $this->course ??= CourseAccess::findOrFail((string) $this->route('courseId'));
    }
}
