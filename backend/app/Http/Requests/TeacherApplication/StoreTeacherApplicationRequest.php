<?php

declare(strict_types=1);

namespace App\Http\Requests\TeacherApplication;

use App\Http\Requests\ApiFormRequest;

/**
 * POST /api/v1/teacher-applications — public, no session. See
 * TeacherApplicationController::store() and migration
 * 2026_08_31_000001_create_teacher_applications_table.php.
 *
 * `phone`/`whatsapp` use `digits:11`, the same rule
 * App\Http\Requests\Auth\RegisterRequest uses for `studentPhone`/
 * `parentPhone` — the platform serves Egypt only, plain local digits
 * including the leading trunk zero.
 *
 * `grades` and `social_links` are both "at least one" arrays of strings —
 * the frontend renders them as an add/remove list, so an empty array here
 * means the visitor never added a single row.
 *
 * `max:` on both is a deliberate ceiling: this route carries no auth
 * middleware at all (see TeacherApplicationController::store()), so it is
 * the one write path in the app a completely anonymous caller can hit
 * directly. `MAX_LIST_ITEMS` mirrors the cap already used for the same
 * shape of "add/remove list" input in
 * App\Http\Requests\Settings\UpdateSettingsRequest.
 */
final class StoreTeacherApplicationRequest extends ApiFormRequest
{
    private const MAX_LIST_ITEMS = 50;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:3', 'max:150'],
            'phone' => ['required', 'digits:11'],
            'whatsapp' => ['required', 'digits:11'],
            'governorate' => ['required', 'string', 'max:100'],
            'age' => ['required', 'integer', 'min:18', 'max:80'],
            'years_of_experience' => ['required', 'integer', 'min:0', 'max:60'],
            'subject' => ['required', 'string', 'max:150'],
            'section_name' => ['required', 'string', 'max:100'],
            'grades' => ['required', 'array', 'min:1', 'max:' . self::MAX_LIST_ITEMS],
            'grades.*' => ['required', 'string', 'max:100'],
            'social_links' => ['required', 'array', 'min:1', 'max:' . self::MAX_LIST_ITEMS],
            'social_links.*' => ['required', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'full_name.required' => 'الاسم الرباعي مطلوب',
            'full_name.min' => 'الاسم الرباعي قصير جداً',
            'phone.required' => 'رقم الهاتف مطلوب',
            'phone.digits' => 'رقم الهاتف يجب أن يتكون من 11 رقماً',
            'whatsapp.required' => 'رقم الواتساب مطلوب',
            'whatsapp.digits' => 'رقم الواتساب يجب أن يتكون من 11 رقماً',
            'governorate.required' => 'برجاء اختيار المحافظة',
            'age.required' => 'السن مطلوب',
            'age.integer' => 'السن يجب أن يكون رقماً',
            'age.min' => 'السن يجب ألا يقل عن 18 سنة',
            'age.max' => 'السن يجب ألا يزيد عن 80 سنة',
            'years_of_experience.required' => 'سنوات الخبرة مطلوبة',
            'years_of_experience.integer' => 'سنوات الخبرة يجب أن تكون رقماً',
            'subject.required' => 'برجاء إدخال المادة التي تدرسها',
            'section_name.required' => 'برجاء اختيار المرحلة الدراسية',
            'grades.required' => 'برجاء اختيار صف دراسي واحد على الأقل',
            'grades.min' => 'برجاء اختيار صف دراسي واحد على الأقل',
            'grades.max' => 'عدد الصفوف الدراسية المختارة كبير جداً',
            'social_links.required' => 'برجاء إضافة رابط واحد على الأقل',
            'social_links.min' => 'برجاء إضافة رابط واحد على الأقل',
            'social_links.max' => 'عدد الروابط المضافة كبير جداً',
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'full_name' => (string) $this->validated('full_name'),
            'phone' => (string) $this->validated('phone'),
            'whatsapp' => (string) $this->validated('whatsapp'),
            'governorate' => (string) $this->validated('governorate'),
            'age' => (int) $this->validated('age'),
            'years_of_experience' => (int) $this->validated('years_of_experience'),
            'subject' => (string) $this->validated('subject'),
            'section_name' => (string) $this->validated('section_name'),
            'grades' => (array) $this->validated('grades'),
            'social_links' => (array) $this->validated('social_links'),
        ];
    }
}
