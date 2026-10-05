<?php

declare(strict_types=1);

namespace App\Http\Requests\Student;

use App\Http\Requests\ApiFormRequest;
use App\Rules\StudentPasswordPolicy;

/**
 * PATCH | PUT /api/v1/admin/students/{id}.
 *
 * Wire names are the frontend's (frontend/src/types/student.ts), NOT the
 * column names: `studentPhone` -> students.phone, `parentPhone` ->
 * parent_phone, `gradeLevel` -> grade_level, `educationalStage` ->
 * educational_stage. The mapping lives in App\Actions\Student\UpdateStudent,
 * in one table, rather than being spread between a request and a model.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ STUDENT UPDATE'S EMPTY-STRING SEMANTIC IS **NOT** TEACHER'S. MEASURED.  │
 * │                                                                          │
 * │ backend/StudentService::update() writes its profile columns through      │
 * │     array_filter([...], fn ($v) => $v !== null)                          │
 * │ — an EXPLICIT `!== null` callback, not array_filter's default. That      │
 * │ difference is the whole behaviour:                                       │
 * │                                                                          │
 * │   default callback   drops '' and '0' as well as null                    │
 * │   `!== null`         keeps '' and '0'; drops only null                   │
 * │                                                                          │
 * │ And the array being filtered is built with `$data['studentPhone'] ??     │
 * │ null`, so an ABSENT key becomes null and is dropped. Net result, which   │
 * │ is what App\Actions\Student\UpdateStudent reproduces exactly:            │
 * │                                                                          │
 * │   absent  -> untouched                                                   │
 * │   ''      -> WRITTEN, i.e. the column is CLEARED                         │
 * │   value   -> written                                                     │
 * │                                                                          │
 * │ Teacher reaches the same outcome for its clearable fields but by a       │
 * │ different route, and reaches the OPPOSITE outcome for fullName/email     │
 * │ (where '' is ignored). Here `fullName` uses `!empty()`, so student       │
 * │ fullName ALSO ignores ''. Verified against the current code line by      │
 * │ line, not inferred from Teacher's shape.                                 │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NOTE the columns are NOT NULL in the schema for `governorate` and
 * `grade_level` (migration 000009), so "cleared" means the empty string, not
 * NULL — which is exactly what backend/ wrote too. The rules below therefore
 * allow '' but not null for those two: sending an explicit null would be a
 * 1048 at the driver, and answering 422 with a field name beats answering 500.
 */
final class UpdateStudentRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'fullName' => ['sometimes', 'nullable', 'string', 'max:255'],

            'studentPhone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'parentPhone' => ['sometimes', 'nullable', 'string', 'max:20'],

            // `present` + `string`: the key may be omitted, but if it IS sent
            // it must be a string ('' included) — never null, because the
            // column is NOT NULL.
            'governorate' => ['sometimes', 'string', 'max:100'],
            'gradeLevel' => ['sometimes', 'string', 'max:100'],

            'educationalStage' => ['sometimes', 'nullable', 'string', 'max:100'],

            // NEW — "تعديل بيانات الطالب" gained the same gender/نوع التعليم
            // pickers AddStudent.tsx already writes at registration
            // (RegisterRequest's own `gender` rule is the same bare
            // `nullable|string`; `eduType` is unvalidated there, tightened
            // here rather than copied loose).
            'gender' => ['sometimes', 'nullable', 'string', 'max:20'],
            'eduType' => ['sometimes', 'nullable', 'string', 'max:20'],

            // Presence, not truthiness, is what the Action tests. See
            // UpdateStudent's docblock.
            'isActive' => ['sometimes', 'boolean'],

            // NEW (open decision 10.19) — "إعادة تعيين كلمة السر" on the
            // student edit page.
            //
            // StudentPasswordPolicy, NOT the stricter teacher/admin
            // PasswordPolicy: an admin resetting a STUDENT's password
            // (Admin/ResetStudentPassword.tsx) must accept exactly what that
            // same student could have chosen for themselves at signup (POST
            // /auth/register) — no letter+digit composition, just a length
            // floor. Using the stricter policy here was an inconsistency:
            // creating a student accepted a 6-character password, but an
            // admin resetting that same student's password later was held to
            // a 12-character, letter-and-digit policy for no product reason.
            //
            // Same filled()-gated shape as UpdateTeacherRequest: absent or ''
            // means "do not touch the password", exactly like every other
            // clearable-on-'' field EXCEPT this one is untouched-on-'' too (a
            // blank password field must never accidentally wipe the
            // account's password).
            'password' => ['sometimes', 'nullable', 'string'],
        ];

        if ($this->filled('password')) {
            $rules['password'][] = new StudentPasswordPolicy();
        }

        return $rules;
    }
}
