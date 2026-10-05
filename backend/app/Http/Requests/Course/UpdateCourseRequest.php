<?php

declare(strict_types=1);

namespace App\Http\Requests\Course;

use App\Models\Course;
use App\Support\CourseAccess;

/**
 * PUT /api/v1/courses/{id} — freeze-table row 13.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ORDER OF REFUSALS IS A CONTRACT, AND THE FORM REQUEST IS WHERE IT    ║
 * ║ IS ENFORCED — NOT THE CONTROLLER.                                        ║
 * ║                                                                          ║
 * ║ backend/CourseService::update() opens with assertOwnsCourse() and only   ║
 * ║ then validates. A Laravel controller body runs AFTER validation, so      ║
 * ║ checking ownership there would invert the two: a foreign instructor      ║
 * ║ probing another teacher's course would receive 422s describing that      ║
 * ║ course's stored price (the DiscountNotAbovePrice baseline!) before ever  ║
 * ║ being told the course is not theirs. That is a genuine information leak, ║
 * ║ not a cosmetic ordering difference.                                      ║
 * ║                                                                          ║
 * ║ FormRequest::validateResolved() runs                                     ║
 * ║     prepareForValidation()  →  passesAuthorization()  →  validate        ║
 * ║ so authorize() below is the exact hook that reproduces backend/'s order: ║
 * ║                                                                          ║
 * ║     404 'Course not found'  →  403 'You do not own this course'          ║
 * ║   →  403 'لا يوجد ملف مدرس لهذا الحساب'  →  422                          ║
 * ║                                                                          ║
 * ║ Pinned by CourseCrudTest::test_ownership_is_refused_before_validation_   ║
 * ║ ever_runs.                                                               ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class UpdateCourseRequest extends CourseWriteRequest
{
    private ?Course $course = null;

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ WHY THIS THROWS INSTEAD OF RETURNING false.                          │
     * │                                                                      │
     * │ `return false` produces Laravel's AuthorizationException, which      │
     * │ App\Exceptions\Handler maps to 403 with the GENERIC message          │
     * │ 'Forbidden' — correct as a status, wrong as a contract. Freeze-table │
     * │ rows 13 and 14 pin the literal 'You do not own this course', and     │
     * │ App\Exceptions\ForbiddenException implements PublicMessage, which is │
     * │ what lets that string survive Handler's flattening.                  │
     * │                                                                      │
     * │ Both the lookup and the refusal are delegated to                     │
     * │ App\Support\CourseAccess so the exact strings live in ONE file for   │
     * │ all sixteen routes rather than being re-typed per Form Request.      │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function authorize(): bool
    {
        CourseAccess::assertManageable($this->course());

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = $this->sharedRules();

        /*
         * `sometimes`, not `required` — a PUT here is semantically a PATCH and
         * the frontend sends partial bodies. But when the key IS present it
         * must be a real title: backend/ re-validates with the full
         * `required|string|min:3` inside `if (array_key_exists('title', …))`
         * precisely so that '' cannot be written over a valid title.
         *
         * ┌── `required` IS NOT REDUNDANT NEXT TO `sometimes`. MEASURED. ────┐
         * │ `sometimes|string|min:3` LETS `title: ''` THROUGH WITH A 200.    │
         * │                                                                  │
         * │ Laravel skips every NON-IMPLICIT rule for a blank string —       │
         * │ Validator::presentOrRuleIsImplicit():                            │
         * │                                                                  │
         * │   if (is_string($value) && trim($value) === '') {                │
         * │       return $this->isImplicit($rule);                           │
         * │   }                                                              │
         * │                                                                  │
         * │ `string` and `min` are not implicit, so NEITHER RUNS on '' and   │
         * │ the value sails into the Action, which writes it over a valid    │
         * │ title. `required` IS implicit, and it is what makes the 422 cell │
         * │ of the field-semantics table real. backend/ reached the same     │
         * │ place from the other direction: it re-ran the FULL               │
         * │ `required|string|min:3` inside `if (array_key_exists('title'))`. │
         * │                                                                  │
         * │ Caught by CourseFieldSemanticsTest::test_an_empty_title_is_      │
         * │ rejected_with_422_and_writes_nothing, which returned 200 until   │
         * │ `required` was added. Not anticipated — observed.                │
         * └──────────────────────────────────────────────────────────────────┘
         *
         * `nullable` IS DELIBERATELY ABSENT. `courses.title` is NOT NULL, so a
         * `title: null` must fail rather than blank the column.
         */
        $rules['title'] = ['sometimes', 'required', 'string', 'min:3', 'max:190'];

        return $rules;
    }

    /**
     * THE STORED PRICE — the whole reason App\Rules\DiscountNotAbovePrice
     * exists rather than a plain `lte:price`.
     *
     * `PUT {discount_price: 900}` on a course stored at 500 must be a 422, and
     * there is no `price` in that body for `lte` to compare against. The cast
     * on Course::$price makes this a fixed-precision string ("500.00"), which
     * (float) reads exactly.
     */
    protected function baselinePrice(): float
    {
        return (float) $this->course()->getAttribute('price');
    }

    /**
     * The course under edit, resolved once and reused by the controller so the
     * row is not fetched twice per request.
     *
     * The 404 shape (and the reason it is not route-model binding) lives in
     * App\Support\CourseAccess::findOrFail().
     */
    public function course(): Course
    {
        return $this->course ??= CourseAccess::findOrFail((string) $this->route('id'));
    }
}
