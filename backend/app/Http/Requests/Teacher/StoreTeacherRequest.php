<?php

declare(strict_types=1);

namespace App\Http\Requests\Teacher;

use App\Http\Requests\ApiFormRequest;
use App\Rules\PasswordPolicy;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/admin/teachers.
 *
 * Payload shape is frontend/src/components/Admin/AddTeacher.tsx's, verbatim:
 * username, fullName, phone, email, subjects[], grades[], nationality,
 * whatsappNumbers[], avatarUrl, bannerUrl, profilePhotoUrl, socialLinks[],
 * password.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ STAGE 4 DECISION #2 — THE PASSWORD RULE IS THE SHARED PasswordPolicy.   │
 * │ THIS IS A DELIBERATE BEHAVIOUR CHANGE FROM backend/.                    │
 * │                                                                          │
 * │ backend/TeacherService::create() validated `password` as                 │
 * │ `required|min:8` — eight characters, no composition requirement, and no  │
 * │ maximum. Every other password surface in the application (registration,  │
 * │ self-service credential change) already went through PasswordPolicy: 12  │
 * │ characters, at least one letter AND one digit, 200 max. Teacher creation │
 * │ was the one weak spot, and it is the surface that mints ACCOUNTS WITH    │
 * │ CONTENT-OWNER PRIVILEGES.                                                │
 * │                                                                          │
 * │ App\Rules\PasswordPolicy's own docblock has carried the note since Stage │
 * │ 1: "when Teacher CRUD is built it must reuse THIS rule." This is that.   │
 * │                                                                          │
 * │ CONSEQUENCE, stated plainly: an admin script that created teachers with  │
 * │ 8-character passwords now receives 422 and the policy sentence. No       │
 * │ EXISTING account is affected — this validates input, it does not         │
 * │ re-check stored hashes.                                                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ `whatsappNumbers` USED TO BE ACCEPTED-AND-NEVER-PERSISTED. IT NO LONGER  │
 * │ IS (the "nationality" catalog that shared this shape was removed         │
 * │ platform-wide, taking its own accepted-and-dropped `nationality` field   │
 * │ with it).                                                                │
 * │                                                                          │
 * │ direct product decision added `teachers.whatsapp_support_numbers`        │
 * │ (migration 000036) specifically so these per-teacher support numbers     │
 * │ could finally be shown on the public teacher profile page. It is real    │
 * │ array validation now, not a shape-only passthrough — see below.          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * USERNAME UNIQUENESS IS SCOPED TO LIVE ROWS (`deleted_at IS NULL`), matching
 * backend/UserRepository::existsByUsername() exactly. A soft-deleted account
 * therefore does not reserve its username forever — which is the existing
 * behaviour, not a change.
 */
final class StoreTeacherRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'username' => [
                'required',
                'string',
                'min:3',
                'max:100',
                Rule::unique('users', 'username')->whereNull('deleted_at'),
            ],
            'fullName' => ['required', 'string', 'min:3', 'max:255'],

            // DECISION #2. PasswordPolicy::rules() is ['required','string',
            // <the rule object>] — the length half expressed as plain strings
            // so the constants stay the single source, plus the policy itself.
            'password' => PasswordPolicy::rules(),

            // NOT `required`. backend/ derives `username@sanad.edu` when the
            // field is absent or empty, because the admin form has no email
            // input and the client sends that same derived address. See
            // App\Actions\Teacher\CreateTeacher::resolveEmail().
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],

            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:190'],
            'bio' => ['sometimes', 'nullable', 'string'],

            // Flat arrays of NAMES, not ids — teachers.subjects/grades are
            // JSON string arrays, which is exactly why the catalog endpoints
            // have no rename action.
            'subjects' => ['sometimes', 'nullable', 'array'],
            'subjects.*' => ['string'],
            'grades' => ['sometimes', 'nullable', 'array'],
            'grades.*' => ['string'],

            'avatarUrl' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bannerUrl' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profilePhotoUrl' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Shape only. The per-entry url check and the drop-vs-reject
            // decision live in App\Actions\Teacher\NormaliseSocialLinks, which
            // runs INSIDE the create transaction — see its docblock.
            'socialLinks' => ['sometimes', 'nullable', 'array'],

            // teachers.login_blocked (migration 000037) — blocks SIGN-IN only,
            // never visibility. Allowed at CREATE time on purpose: an admin who
            // provisions an account ahead of a handover should be able to mint
            // it already locked rather than create it, then immediately issue a
            // second PUT — a window in which the credentials are live. Absent =
            // false, which is the column default, so the ordinary create path
            // is unchanged. Same `sometimes|boolean` shape as UpdateTeacher-
            // Request's rule; CreateTeacher reads it as `?? false`.
            'loginBlocked' => ['sometimes', 'boolean'],

            // teachers.center_enabled (migration 2026_09_10_000002) — "زر
            // تفعيل نظام السنتر". Absent = false: a brand-new teacher does
            // NOT get Center System access unless the admin explicitly ticks
            // the box on this form (CreateTeacher reads it as `?? false`) —
            // the OPPOSITE of the column's own schema default (`true`),
            // which exists only to keep already-live Center System teachers
            // from being locked out the moment this column shipped.
            'centerEnabled' => ['sometimes', 'boolean'],

            // Free-text "label: number" strings exactly as
            // TeacherWhatsAppSupport.tsx collects them — NOT split into
            // separate fields here. Genuinely persisted (whatsapp_support_numbers,
            // migration 000036), unlike `nationality` above.
            'whatsappNumbers' => ['sometimes', 'nullable', 'array'],
            'whatsappNumbers.*' => ['string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'username.unique' => 'اسم المستخدم مستخدم بالفعل',
        ];
    }
}
