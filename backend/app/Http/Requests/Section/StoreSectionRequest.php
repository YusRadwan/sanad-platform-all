<?php

declare(strict_types=1);

namespace App\Http\Requests\Section;

use App\Http\Requests\ApiFormRequest;
use App\Models\Course;
use App\Support\CourseAccess;

/**
 * POST /api/v1/courses/{courseId}/sections — freeze-table row 16.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE JUDGEMENT IS ON THE **COURSE**, NOT ON THE SECTION.                 │
 * │ There is no section yet to have an owner, and `sections` carries no      │
 * │ owner column even once there is one. Row 16 says `Course::manage`.       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ORDERING, as in UpdateCourseRequest: authorize() runs before validation, so
 * a foreign instructor gets 403 'You do not own this course' rather than a 422
 * describing the body they sent. backend/SectionService::create() likewise
 * calls assertOwnsCourse() as its first statement.
 *
 * ┌── `title` IS VALIDATED HERE AND WAS VALIDATED NOWHERE IN backend/. ──────┐
 * │ SectionService::create() reads `$data['title']` unconditionally and      │
 * │ SectionRepository::create binds it into a NOT NULL column, so            │
 * │ POST .../sections with no body was an "Undefined array key" 500 —        │
 * │ the identical bug CourseService::create() already had a validator added  │
 * │ for (see its comment about the stack trace reaching the caller). Same    │
 * │ class of fix, applied to the sibling endpoint. Recorded as decision 5.2. │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * max:190 matches `sections.title` VARCHAR(190) exactly — see
 * StoreCourseRequest for why the column, not 255, is the right bound.
 */
final class StoreSectionRequest extends ApiFormRequest
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
            'title' => ['required', 'string', 'max:190'],
        ];
    }

    /** The parent course, resolved once and reused by the controller. */
    public function course(): Course
    {
        return $this->course ??= CourseAccess::findOrFail((string) $this->route('courseId'));
    }
}
