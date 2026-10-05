<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;

/**
 * GET /api/v1/admin/enrollment-codes/search — the admin review/filter screen.
 *
 * Six optional query-string filters, all ANDed. `teacher_id` is an EXACT
 * match (the frontend now sends a real teacher id from a `<Select>`, not
 * free text); the other five are partial (`LIKE`) matches — a search box,
 * not an exact lookup (contrast ShowEnrollmentCodeRequest, which owes ONE
 * exact code an exact answer). `teacher_name` is kept alongside `teacher_id`
 * rather than replaced — a caller that still only has a name string (e.g. a
 * future non-UI consumer) is not broken by this addition. Every filter
 * absent/blank means "no filter", matching the convention
 * AdminDashboardController::subscriptions()/ListSubscriptionsForAdmin already
 * established for the five-filter subscriptions screen.
 */
final class SearchEnrollmentCodesRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:100'],
            'teacher_id' => ['nullable', 'string', 'max:100'],
            'teacher_name' => ['nullable', 'string', 'max:100'],
            'student_name' => ['nullable', 'string', 'max:100'],
            'student_phone' => ['nullable', 'string', 'max:100'],
            'parent_phone' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function code(): string
    {
        return trim((string) $this->validated('code'));
    }

    public function teacherId(): string
    {
        return trim((string) $this->validated('teacher_id'));
    }

    public function teacherName(): string
    {
        return trim((string) $this->validated('teacher_name'));
    }

    public function studentName(): string
    {
        return trim((string) $this->validated('student_name'));
    }

    public function studentPhone(): string
    {
        return trim((string) $this->validated('student_phone'));
    }

    public function parentPhone(): string
    {
        return trim((string) $this->validated('parent_phone'));
    }
}
