<?php

declare(strict_types=1);

namespace App\Http\Requests\Teacher;

use App\Http\Requests\ApiFormRequest;
use App\Rules\PasswordPolicy;

/**
 * PATCH | PUT /api/v1/admin/teachers/{id}.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THIS CLASS VALIDATES **SHAPE ONLY**. IT DOES NOT DECIDE WHAT GETS       │
 * │ WRITTEN.                                                                 │
 * │                                                                          │
 * │ Teacher update has THREE different absent/empty/real-value semantics     │
 * │ across its fields (name and email ignore '', the image and text fields   │
 * │ treat '' as CLEAR, socialLinks treats [] as DELETE ALL). Encoding that   │
 * │ in rules() is not possible without conditional rule construction that    │
 * │ would then be the real business logic hiding in a validator. It lives in │
 * │ App\Actions\Teacher\UpdateTeacher instead, field by field, next to the   │
 * │ table that documents it.                                                 │
 * │                                                                          │
 * │ Consequently every rule below is `sometimes` + `nullable`: absence and   │
 * │ emptiness are both LEGAL here and MEANINGFUL later.                      │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ STAGE 4 DECISION #2, SECOND HALF — PASSWORD RESET IS NOW VALIDATED.     │
 * │ DELIBERATE BEHAVIOUR CHANGE FROM backend/.                              │
 * │                                                                          │
 * │ backend/TeacherService::update() reset the password with                 │
 * │   if (!empty($data['password'])) { …password_hash(…) }                   │
 * │ and NO validation whatsoever. "1" was a valid new instructor password.   │
 * │ It now goes through the same shared PasswordPolicy as creation and as    │
 * │ /auth/update-credentials.                                                │
 * │                                                                          │
 * │ THE `!empty` SEMANTIC IS PRESERVED EXACTLY, and that is why the rule is  │
 * │ attached conditionally below instead of being listed unconditionally:    │
 * │ an absent password and an EMPTY password both mean "do not touch the     │
 * │ password", and must not 422. Listing PasswordPolicy unconditionally      │
 * │ would reject `password: ''` with 'كلمة المرور مطلوبة' — turning a        │
 * │ save-without-password-change into an error. `filled()` is precisely the  │
 * │ "present AND non-empty" test that matches `!empty`.                      │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `isActive` IS VALIDATED AS `boolean`, WHICH ACCEPTS true/false/1/0/"1"/"0".
 * backend/ ran the value through filter_var(FILTER_VALIDATE_BOOLEAN), which is
 * broader still (it also accepts "yes"/"on"). Laravel's `boolean` rule is the
 * native equivalent for every spelling the frontend actually sends; the Action
 * reads it back with $request->boolean() only AFTER establishing presence with
 * has(), never instead of it. See UpdateTeacher's docblock for why that
 * ordering is load-bearing.
 */
final class UpdateTeacherRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'fullName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],

            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:190'],
            'bio' => ['sometimes', 'nullable', 'string'],

            'subjects' => ['sometimes', 'nullable', 'array'],
            'subjects.*' => ['string'],
            'grades' => ['sometimes', 'nullable', 'array'],
            'grades.*' => ['string'],

            'avatarUrl' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bannerUrl' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profilePhotoUrl' => ['sometimes', 'nullable', 'string', 'max:255'],

            'socialLinks' => ['sometimes', 'nullable', 'array'],

            'isActive' => ['sometimes', 'boolean'],

            // A SECOND, INDEPENDENT boolean — NOT a spelling of isActive.
            // `sometimes|boolean` and NOT `nullable`, deliberately: it is the
            // same presence-not-truthiness shape as isActive above (the Action
            // reads it with has() before boolean(), never boolean() alone), and
            // a null here would have no meaning the column can hold — it is
            // NOT NULL DEFAULT false (migration 000037). Blocks login ONLY;
            // it changes nothing an unauthenticated visitor can see.
            'loginBlocked' => ['sometimes', 'boolean'],

            // "زر تفعيل نظام السنتر" — same has()-before-boolean() shape as
            // isActive/loginBlocked above. Turning it off never deletes any
            // Center System row (groups/sessions/attendance/payment
            // tracking) — see migration 2026_09_10_000002's docblock.
            'centerEnabled' => ['sometimes', 'boolean'],

            'password' => ['sometimes', 'nullable', 'string'],

            // Genuinely persisted (whatsapp_support_numbers) — same [] = clear
            // all semantics as subjects/grades, applied in
            // App\Actions\Teacher\UpdateTeacher::ARRAY_PROFILE_FIELDS.
            'whatsappNumbers' => ['sometimes', 'nullable', 'array'],
            'whatsappNumbers.*' => ['string', 'max:255'],
        ];

        // DECISION #2: the policy applies only to a password that is actually
        // being SET. filled() === present and non-empty === backend/'s !empty.
        if ($this->filled('password')) {
            $rules['password'][] = new PasswordPolicy();
        }

        return $rules;
    }
}
