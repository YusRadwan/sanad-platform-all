<?php

declare(strict_types=1);

namespace App\Http\Requests\Course;

use App\Http\Requests\ApiFormRequest;
use App\Models\Course;
use App\Support\CourseAccess;

/**
 * DELETE /api/v1/courses/{id} — freeze-table row 14.
 *
 * Rules-free; it exists to run the 404-then-403 ladder before the controller
 * body, exactly as backend/CourseService::delete() runs assertOwnsCourse() as
 * its first statement.
 *
 * ┌── THE DELETE IS SOFT, AND IT DOES **NOT** CASCADE. ──────────────────────┐
 * │ It writes `courses.deleted_at` and nothing else. Sections, groups and    │
 * │ content items are deliberately LEFT IN PLACE — backend/CourseService::   │
 * │ delete() says so in as many words ("Single-table soft delete; sections   │
 * │ and content items are intentionally left in place, so there is nothing   │
 * │ to make atomic here") — and Stage 5 preserves it rather than "tidying    │
 * │ up", because a soft delete that destroyed its children would make the    │
 * │ operation irreversible in fact while pretending to be reversible.        │
 * │                                                                          │
 * │ The consequence is the orphan case that                                  │
 * │ App\Support\CourseAccess::parentCourseOf() exists to answer: those       │
 * │ surviving rows have a null `course` relation for as long as the delete   │
 * │ stands.                                                                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO ADMIN RE-AUTHENTICATION. Stage 4's decision #1 added a password re-check
 * to the three deletes that destroy an ACCOUNT (`users`, `teachers`,
 * `students`); this one is reversible content and backend/ asked for nothing.
 * It is, however, the ONE delete in this session that is audited — see
 * App\Http\Controllers\CourseController::destroy().
 */
final class DeleteCourseRequest extends ApiFormRequest
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
        return [];
    }

    public function course(): Course
    {
        return $this->course ??= CourseAccess::findOrFail((string) $this->route('id'));
    }
}
