<?php

declare(strict_types=1);

namespace App\Http\Requests\Section;

use App\Exceptions\NotFoundException;
use App\Http\Requests\ApiFormRequest;
use App\Models\Section;
use App\Support\CourseAccess;

/**
 * PUT /api/v1/sections/{id} — freeze-table row 17.
 *
 * THE REFUSAL LADDER, verbatim from backend/SectionService::update():
 *
 *   404 'Section not found'   the id names nothing
 *   404 'Course not found'    the section's course was soft-deleted
 *   403 'You do not own this course'   (open decision 3.4 — the COURSE
 *                             wording, not 'You do not own this section',
 *                             which belongs to Session B's content-item paths)
 *   422                       a bad body
 *
 * The middle rung is the one that is easy to lose; see
 * App\Support\CourseAccess::parentCourseOf().
 *
 * ┌── `title: ''` WRITES '' HERE, AND THAT IS **NOT** THE COURSE RULE. ──────┐
 * │ backend/SectionService::update() filters with                            │
 * │   array_filter(['title' => $data['title'] ?? null], fn ($v) => $v !== null)│
 * │ so '' survives and is written to a NOT NULL VARCHAR — legal in MySQL,    │
 * │ unlike the DECIMAL case that makes `discount_price: ''` a 1366 error.    │
 * │ The behaviour is therefore real, reachable and ported as-is, with NO     │
 * │ `min:` rule: adding one would 422 a request that succeeds in production  │
 * │ today. Contrast App\Http\Requests\Course\CourseWriteRequest, where       │
 * │ `title: ''` IS a 422 — because backend/ validates `min:3` there and only │
 * │ there.                                                                   │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class UpdateSectionRequest extends ApiFormRequest
{
    private ?Section $section = null;

    public function authorize(): bool
    {
        CourseAccess::assertManageable(
            CourseAccess::parentCourseOf($this->section())
        );

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // `sometimes` + no `nullable`: an absent title means "leave it", and a
        // null one must fail `string` rather than be written to a NOT NULL
        // column. '' passes `string` on purpose — see the class docblock.
        return [
            'title' => ['sometimes', 'string', 'max:190'],
        ];
    }

    public function section(): Section
    {
        return $this->section ??= Section::query()
            ->whereKey((string) $this->route('id'))
            ->first()
            ?? throw new NotFoundException(CourseAccess::SECTION_NOT_FOUND);
    }
}
