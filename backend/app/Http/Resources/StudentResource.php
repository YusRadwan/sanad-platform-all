<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The student shape shared by GET /admin/students, GET /admin/students/{id},
 * GET /admin/students/by-code/{code} and GET /enrollments/instructor-students.
 *
 * Port of backend/StudentService::present(). Field names are
 * frontend/src/types/student.ts's Student interface verbatim —
 * SubscriptionsManagement.tsx filters on name / studentCode / phone /
 * parentPhone / governorate / gradeLevel / status, so every one of those keys
 * is load-bearing.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ `status` IS A STRING, NOT A BOOLEAN, AND IT DERIVES FROM users.is_active.│
 * │                                                                          │
 * │ backend/present() emits `'active' | 'inactive'`, and the frontend         │
 * │ compares it as a string. Emitting the boolean instead would render an    │
 * │ empty status chip everywhere — a silent degradation, not an error.       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `->user` IS DEREFERENCED WITHOUT `?->`: every read path filters through
 * Student::scopeWithLiveUser() first, so null here means a bug rather than a
 * deleted student, and hiding it behind a null-safe chain would emit a
 * well-formed row describing a deleted account. Same reasoning as
 * TeacherResource.
 *
 * @mixin Student
 */
final class StudentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Student $student */
        $student = $this->resource;

        /** @var User $user */
        $user = $student->getRelationValue('user');

        return [
            'student_id' => $student->getKey(),
            'username' => $user->getAttribute('username'),
            'name' => $user->getAttribute('full_name'),
            'status' => $user->getAttribute('is_active') ? 'active' : 'inactive',
            'studentCode' => $student->getAttribute('student_code'),
            'governorate' => $student->getAttribute('governorate'),
            'phone' => $student->getAttribute('phone'),
            'parentPhone' => $student->getAttribute('parent_phone'),
            'gradeLevel' => $student->getAttribute('grade_level'),
            'educationalStage' => $student->getAttribute('educational_stage'),
            // NEW (open decision 10.17) — ManageUsers.tsx's student table
            // gained a "نوع التعليم" column; the column existed on `students`
            // already (showByCode() has read it for a while) but was never
            // exposed on this shared listing shape until now.
            'educationType' => $student->getAttribute('education_type'),
            // NEW — "تعديل بيانات الطالب" needs this to prefill the same
            // gender picker AddStudent.tsx writes at registration; it was
            // stored on every student since sign-up but never read back by
            // any admin-facing route until now.
            'gender' => $student->getAttribute('gender'),
        ];
    }
}
