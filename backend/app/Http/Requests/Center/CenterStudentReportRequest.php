<?php

declare(strict_types=1);

namespace App\Http\Requests\Center;

use App\Http\Requests\ApiFormRequest;

/** GET /center/reports/students. All filters optional, ANDed. */
final class CenterStudentReportRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function phone(): string
    {
        return trim((string) $this->query('phone', ''));
    }

    public function studentCode(): string
    {
        return trim((string) $this->query('student_code', ''));
    }

    public function centerName(): string
    {
        return trim((string) $this->query('center_name', ''));
    }

    public function groupId(): string
    {
        return trim((string) $this->query('group_id', ''));
    }

    /** "فلتر الصف الدراسي" — replaces the removed student-name filter. Exact match against `students.grade_level`. */
    public function gradeLevel(): string
    {
        return trim((string) $this->query('grade_level', ''));
    }
}
