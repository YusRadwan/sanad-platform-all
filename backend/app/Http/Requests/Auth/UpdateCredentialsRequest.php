<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Rules\PasswordPolicy;

/**
 * PATCH /api/v1/auth/update-credentials.
 *
 * Accepted payload (frontend/src/components/Admin/PlatformSettings.tsx sends
 * currentPassword + newUsername + newPassword; `email`/`full_name` are also
 * accepted for other callers):
 *
 *   currentPassword  — REQUIRED for ANY change, including an email-only one
 *   newPassword      — optional; must satisfy PasswordPolicy
 *   newUsername      — optional; must be unique
 *   email            — optional; must be a valid, unused address
 *   full_name        — optional
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ EVERY RULE HERE IS `sometimes`-SHAPED, AND THE `currentPassword`         │
 * │ REQUIREMENT IS **NOT** ENFORCED IN THIS CLASS.                           │
 * │                                                                          │
 * │ That is on purpose. "currentPassword is required" only makes sense once  │
 * │ you know the payload actually asks to change something, and "something"  │
 * │ is decided AFTER no-op collapsing (a newUsername identical to the        │
 * │ current one is not a change — the UI pre-fills that field). A blanket    │
 * │ `required` here would reject an empty payload with the WRONG message:    │
 * │ the contract says an empty payload answers                               │
 * │ 'لم يتم إرسال أي بيانات للتحديث', not the current-password message.      │
 * │ So App\Actions\Auth\UpdateCredentials owns that ordering, and this class │
 * │ owns only shape.                                                         │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Uniqueness is likewise NOT expressed as a `unique:` rule: the legacy checks
 * are case-insensitive for email, scoped to non-deleted rows, and must produce
 * their own exact Arabic messages AFTER re-authentication has succeeded — so
 * that a wrong current password never reveals whether an address is taken.
 * Ordering is a security property here, not a style preference.
 */
final class UpdateCredentialsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'currentPassword' => ['sometimes', 'nullable', 'string'],
            'newPassword' => ['sometimes', 'nullable', 'string'],
            'newUsername' => ['sometimes', 'nullable', 'string', 'min:3', 'max:100'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'full_name' => ['sometimes', 'nullable', 'string', 'min:3', 'max:255'],
        ];
    }

    /**
     * The policy check for `newPassword` deliberately does NOT live in rules():
     * it must run only after the current password has been verified. Exposed
     * here so the Action can apply the identical rule object.
     *
     * @return array<int, mixed>
     */
    public static function newPasswordRules(): array
    {
        return [new PasswordPolicy()];
    }
}
