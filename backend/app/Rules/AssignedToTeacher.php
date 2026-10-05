<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Teacher;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ PART 8 OF backend/ — AN INSTRUCTOR MAY ONLY FILE A COURSE UNDER A GRADE  ║
 * ║ OR SUBJECT FROM THEIR **OWN** ASSIGNED LISTS.                            ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Port of backend/app/Services/CourseService::assertGradeSubjectAllowed()
 * (~lines 338-362). CourseManagement.tsx already restricts its two pickers to
 * `currentTeacher.grades` / `.subjects`, but that is PRESENTATION ONLY —
 * nothing server-side ever looked at the submitted strings, so a forged
 * POST /courses could tag a course with any grade or subject at all, including
 * another teacher's specialisation, which then drives the public catalogue's
 * filtering.
 *
 * ┌── WHAT THIS RULE DELIBERATELY DOES **NOT** DO ───────────────────────────┐
 * │                                                                          │
 * │ 1. IT DOES NOT HANDLE ADMINS. backend/ exempts them wholesale, so the    │
 * │    rule is simply NOT ATTACHED for an admin actor (see                   │
 * │    App\Http\Requests\Course\CourseWriteRequest::rules()). Putting an     │
 * │    `if admin: return` here would be a second copy of the admin bypass —  │
 * │    the exact duplication Stage 3's Gate::before exists to have ended —   │
 * │    in a class the Gate never touches.                                    │
 * │                                                                          │
 * │ 2. IT DOES NOT REPORT THE MISSING-TEACHER-PROFILE CASE. backend/ throws  │
 * │    a **ForbiddenException (403)** there — 'لا يوجد ملف مدرس لهذا الحساب' │
 * │    — not a validation failure, and folding it into this rule's 422 path  │
 * │    would silently change the status the frontend branches on. It lives   │
 * │    in CourseWriteRequest::prepareForValidation(), which runs BEFORE any  │
 * │    rule and can therefore throw a real HTTP exception.                   │
 * │                                                                          │
 * │ 3. IT DOES NOT REJECT AN EMPTY VALUE. A blank grade/subject means "not   │
 * │    specified": an instructor whose profile has no assigned grades yet    │
 * │    must still be able to save a course, and omitting the field simply    │
 * │    leaves `grade_id` NULL as it always has. Pinned by                    │
 * │    CourseGradeSubjectRuleTest::test_an_empty_grade_is_allowed.           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── `in_array(..., true)` — STRICT, AND THAT IS A SECURITY PROPERTY. ──────┐
 * │ `teachers.grades` / `.subjects` are JSON arrays of NAME STRINGS (there   │
 * │ is no foreign key; see App\Support\CatalogReferences for why). A loose   │
 * │ in_array over an array of numeric-looking strings is PHP's classic       │
 * │ comparison trap: `in_array(0, ['الصف الأول'])` is TRUE under loose       │
 * │ rules on older semantics, and any caller-controlled needle deserves the  │
 * │ strict form on principle. backend/ used the strict flag too; it is       │
 * │ carried over deliberately, not by transcription.                        │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class AssignedToTeacher implements ValidationRule
{
    /** backend/'s literal strings. Both are rendered by the course form. */
    public const GRADE_MESSAGE = 'غير مسموح لك بإضافة كورس لصف غير مسند إليك';

    public const SUBJECT_MESSAGE = 'غير مسموح لك بإضافة كورس لمادة غير مسندة إليك';

    /**
     * @param Teacher $teacher    the acting instructor's PROFILE row — resolved
     *                            once by the Form Request, never per rule
     * @param string  $jsonColumn 'grades' or 'subjects'; never caller input
     * @param string  $message    the matching literal above
     */
    public function __construct(
        private readonly Teacher $teacher,
        private readonly string $jsonColumn,
        private readonly string $message,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $name = is_string($value) ? trim($value) : '';

        // Absent / blank / non-string = "not specified". Allowed — see the
        // docblock's point 3. This is NOT a fallthrough: the field is also not
        // resolved to an id later, so nothing is written either.
        if ($name === '') {
            return;
        }

        // The 'array' cast on Teacher makes this a real PHP array; the (array)
        // is for the NULL column case (a profile that has never been assigned
        // anything), which must reject rather than crash.
        $assigned = (array) ($this->teacher->getAttribute($this->jsonColumn) ?? []);

        if (!in_array($name, $assigned, true)) {
            $fail($this->message);
        }
    }
}
