<?php

declare(strict_types=1);

namespace App\Http\Requests\Center;

use App\Exceptions\NotFoundException;
use App\Http\Requests\ApiFormRequest;
use App\Models\Course;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * POST /center/courses/{id}/activate.
 *
 * EXACTLY ONE of `center_session_id` (bulk, every attendee of that session)
 * or `identifier` (one student, by center code/platform code/phone) — never
 * both, never neither. `Rule::requiredIf`/`prohibits` express that as
 * validation rather than a runtime branch the Action would otherwise have
 * to re-derive from "which one is truthy".
 *
 * ┌── RESTRICTED TO `center`/`both`-VISIBILITY COURSES — decision 10.39. ────┐
 * │ Decision 10.35 briefly dropped this restriction (a teacher wanted to     │
 * │ grant one student/session free access to an ordinary PAID (`platform`)   │
 * │ course without changing its visibility for everyone). A LATER, EXPLICIT  │
 * │ request reversed that: "the whole Center section must show ONLY courses │
 * │ assigned to the center" — confirmed by the user even after being told    │
 * │ this removes the platform-course grant scenario decision 10.35 existed   │
 * │ for. `visibility IN (center, both)` is therefore back, matching          │
 * │ `CenterCourseController::index()`'s own course-picker restriction (row   │
 * │ 141) — the two must agree, or the picker would list courses this Request │
 * │ then 404s on. `hasCourseActivation()` (ResolvesCourseOwnership) is left  │
 * │ visibility-agnostic regardless — harmless now that no platform-only      │
 * │ activation row can ever be created, and simpler than adding a second     │
 * │ narrowing that would never actually fire.                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class ActivateCenterCourseRequest extends ApiFormRequest
{
    private ?Course $course = null;

    /** Same ownership shape CenterCourseController already uses — a plain scoped query, no separate Policy for this one resource. */
    public function course(): Course
    {
        if ($this->course !== null) {
            return $this->course;
        }

        /** @var User $teacher */
        $teacher = $this->user();

        return $this->course = Course::query()
            ->where('teacher_id', $teacher->getKey())
            ->whereIn('visibility', [Course::VISIBILITY_CENTER, Course::VISIBILITY_BOTH])
            ->whereKey((string) $this->route('id'))
            ->first()
            ?? throw new NotFoundException('الكورس غير موجود');
    }

    public function authorize(): bool
    {
        $this->course();

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'center_session_id' => ['nullable', 'string', 'prohibits:identifier'],
            'identifier' => ['nullable', 'string', Rule::requiredIf(fn () => !$this->filled('center_session_id'))],
        ];
    }

    public function sessionId(): ?string
    {
        $value = $this->validated('center_session_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function identifier(): ?string
    {
        $value = $this->validated('identifier');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
