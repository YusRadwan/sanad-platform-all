<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Enums\UserRole;
use App\Http\Requests\ApiFormRequest;
use App\Models\Course;
use App\Rules\SafeUrlValue;
use App\Support\CourseAccess;

/**
 * POST /api/v1/announcements -> 201. Freeze-table row 110.
 *
 * ┌── TWO OWNERSHIP QUESTIONS, ASKED SEPARATELY. ────────────────────────────┐
 * │ 1. "May you author an announcement?"  -> the route's `role:admin,        │
 * │    instructor` middleware. There is nothing else to ask: a new row has   │
 * │    no owner yet, so AnnouncementPolicy has no subject.                   │
 * │ 2. "May you attach it to THIS course?" -> CoursePolicy::manage, via      │
 * │    CourseAccess, and ONLY when a course_id is supplied.                  │
 * │                                                                          │
 * │ Folding (2) into AnnouncementPolicy would have made a course-scoped      │
 * │ announcement owned by the course rather than by its author — see the     │
 * │ policy's own docblock for why that is the wrong model.                   │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class StoreAnnouncementRequest extends ApiFormRequest
{
    use NormalisesAnnouncementInput;

    /** backend/AnnouncementService::create()'s single 422 for both fields. */
    public const TITLE_AND_CONTENT_REQUIRED = 'العنوان والمحتوى مطلوبان';

    private ?Course $course = null;

    /**
     * The course check, when there is a course. 404 before 403, matching
     * backend/: an unknown course_id is 'Course not found' whoever asked.
     */
    public function authorize(): bool
    {
        $courseId = $this->input('course_id');

        if ($courseId === null || $courseId === '') {
            return true;
        }

        // findOrFail -> 404 'Course not found'; assertManageable -> 403 'You do
        // not own this course'. Both strings are CourseAccess's, shared with
        // every other course-scoped write in the application.
        $this->course = CourseAccess::findOrFail((string) $courseId);
        CourseAccess::assertManageable($this->course);

        return true;
    }

    public function rules(): array
    {
        return [
            /*
             * ┌── `required` AND NO `min:3`. THE ABSENCE IS DELIBERATE. ─────┐
             * │ backend/ rejects only the EMPTY (post-trim) case here:       │
             * │   `if ($title === '' || $content === '')` -> 422.             │
             * │ A two-character title is accepted today. `min:3` reads like   │
             * │ a tightening and would be a FIFTH behaviour change in a stage │
             * │ whose approved list has exactly four — and it would 422 rows  │
             * │ the editor can already save. The broadcast surface DOES carry │
             * │ min:3, because backend/ genuinely validates it there; the     │
             * │ asymmetry is backend/'s, and it is ported, not smoothed.      │
             * │                                                              │
             * │ prepareForValidation() trims first, so '   ' reaches this as  │
             * │ '' and is rejected — Laravel's own `required` would otherwise │
             * │ accept a string of spaces as present.                         │
             * └──────────────────────────────────────────────────────────────┘
             */
            'title' => ['required', 'string', 'max:190'],
            'content' => ['required', 'string'],

            'course_id' => ['sometimes', 'nullable', 'string'],

            // allowBlank: '' is a legitimate "no link", exactly what
            // UrlValidator::nullable() means. See App\Rules\SafeUrlValue.
            'link_url' => ['sometimes', 'nullable', 'string', 'max:255', new SafeUrlValue(allowBlank: true)],

            'start_date' => ['sometimes', 'nullable'],
            'end_date' => ['sometimes', 'nullable'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'title.required' => self::TITLE_AND_CONTENT_REQUIRED,
            'content.required' => self::TITLE_AND_CONTENT_REQUIRED,
        ]);
    }

    /** Resolved in authorize(); null for a global (course-less) announcement. */
    public function course(): ?Course
    {
        return $this->course;
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ APPROVED DECISION 3.2 IS **HERE**, AND IT IS ONE LINE.               ║
     * ║                                                                      ║
     * ║ backend/ writes `teacher_id => $userId` unconditionally.             ║
     * ║ `announcements.teacher_id` is a FK into `teachers.user_id` and an     ║
     * ║ ADMIN HAS NO `teachers` ROW — so that line is a raw MySQL FK          ║
     * ║ violation and a **500** every time an admin posts an announcement.    ║
     * ║ Not a hypothetical: it is the state of backend/ today.                ║
     * ║                                                                      ║
     * ║ NULL is written instead, and it MEANS something: a PLATFORM           ║
     * ║ announcement, owned by no teacher, manageable by admins only —        ║
     * ║ AnnouncementPolicy::manage() says exactly that in its null branch.    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function authorTeacherId(): ?string
    {
        $user = $this->user();

        return $user->role === UserRole::Admin ? null : (string) $user->getKey();
    }
}
