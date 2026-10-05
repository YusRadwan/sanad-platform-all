<?php

declare(strict_types=1);

namespace App\Http\Requests\Group;

use App\Exceptions\NotFoundException;
use App\Http\Requests\ApiFormRequest;
use App\Models\CourseGroup;
use App\Support\CourseAccess;

/**
 * PATCH and DELETE /api/v1/groups/{id} — freeze-table rows 76 and 77.
 *
 * ONE class for both verbs because backend/GroupService::update() and
 * ::delete() open with the identical three statements — find the group (404
 * 'Group not found'), find its course, assert ownership — and the delete
 * carries no body at all. A second class would be the same authorize() twice.
 *
 * REFUSAL LADDER:
 *   404 'Group not found'              the id names nothing
 *   404 'Course not found'             its course was soft-deleted
 *   403 'You do not own this course'   backend/GroupService throws the COURSE
 *                                      wording for every group mutation; the
 *                                      'You do not own this group' phrasing
 *                                      belongs to the content-item routes that
 *                                      hang off a group (rows 78-79, Session B)
 *
 * ┌── FIELD SEMANTICS, PORTED FROM GroupService::update() ───────────────────┐
 * │   array_filter([                                                         │
 * │     'title'     => $data['title'] ?? null,                               │
 * │     'is_hidden' => array_key_exists('is_hidden', $data)                  │
 * │                      ? (int) $data['is_hidden'] : null,                  │
 * │   ], fn ($v) => $v !== null)                                             │
 * │                                                                          │
 * │ `title`     absent → untouched;  '' → written (VARCHAR, legal)           │
 * │ `is_hidden` PRESENCE-based via array_key_exists, so `false` is a real    │
 * │             instruction (un-hide) and not "absent". Same rule as         │
 * │             `is_published` on a course and `isActive` on a teacher.      │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class ManageCourseGroupRequest extends ApiFormRequest
{
    private ?CourseGroup $group = null;

    public function authorize(): bool
    {
        CourseAccess::assertManageable(
            CourseAccess::parentCourseOf($this->group())
        );

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:190'],
            'is_hidden' => ['sometimes', 'boolean'],
            // Open decision 10.21 — same presence-based semantics as
            // `is_hidden` above: the key must be explicitly PRESENT to
            // change anything (has()-gated in the controller), so sending
            // `start_at: null` really clears the start gate rather than
            // being mistaken for "field omitted, leave alone".
            'start_at' => ['sometimes', 'nullable', 'date'],
            'end_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_at'],
            // Lets CourseStructureManagement.tsx persist a drag-reorder of a
            // course's groups without a dedicated bulk /reorder route — one
            // PATCH per group, same presence-based semantics as is_hidden:
            // 0 is a legitimate index and must not be treated as "absent".
            'order_index' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function group(): CourseGroup
    {
        return $this->group ??= CourseGroup::query()
            ->whereKey((string) $this->route('id'))
            ->first()
            ?? throw new NotFoundException(CourseAccess::GROUP_NOT_FOUND);
    }
}
