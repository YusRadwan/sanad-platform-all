<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;

/**
 * PUT /api/v1/admin/enrollments/toggle — freeze-table row 54.
 *
 * ┌── camelCase, BECAUSE THAT IS WHAT IS ON THE WIRE. ───────────────────────┐
 * │ backend/AdminDashboardController::toggleEnrollment() reads               │
 * │ `$data['studentId']`, `$data['courseId']`, `$data['isActive']` — and     │
 * │ reads them WITHOUT null-coalescing, so a request missing any of the      │
 * │ three was a PHP "Undefined array key" warning and a 500. Three fields    │
 * │ that used to crash now answer 422. That is a real change on the wire     │
 * │ (recorded in docs/open-decisions.md 7.1) and it is the same 500→422      │
 * │ correction Stage 5 made three times (decision 5.2); no request that      │
 * │ previously SUCCEEDED behaves differently.                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── `isActive` IS `boolean`, NOT `required|bool` VIA A CAST. ──────────────┐
 * │ Laravel's `boolean` rule accepts true/false/1/0/"1"/"0" and nothing      │
 * │ else, and `$this->boolean()` then reads it. backend/ used a bare PHP     │
 * │ `(bool)` cast, under which the STRING "false" — which every naive        │
 * │ query-string or form-encoded client sends — is TRUE. A grant where a     │
 * │ cancellation was intended is the worst possible direction for that bug   │
 * │ to fail in: it hands out paid access and, on a first-time pair, records  │
 * │ a revenue transaction that never happened.                               │
 * │                                                                          │
 * │ So `"false"` is now a 422 rather than a silent grant. Deliberate, and    │
 * │ documented; the frontend sends a real JSON boolean.                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO `exists:` RULES. Both 404s are the Action's, with their own literal
 * strings — 'الدورة غير موجودة' when granting into a missing course and
 * 'لا يوجد اشتراك لهذا الطالب في هذه الدورة' when cancelling a subscription
 * that is not there. An `exists` rule would answer 422 for both and collapse
 * two distinct, differently-worded contract answers into one.
 */
final class ToggleEnrollmentRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'studentId' => ['required', 'string'],
            'courseId' => ['required', 'string'],
            'isActive' => ['required', 'boolean'],
        ];
    }

    public function studentId(): string
    {
        return (string) $this->validated('studentId');
    }

    public function courseId(): string
    {
        return (string) $this->validated('courseId');
    }

    public function isActive(): bool
    {
        return $this->boolean('isActive');
    }
}
