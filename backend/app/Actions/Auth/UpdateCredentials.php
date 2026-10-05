<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Rules\PasswordPolicy;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Self-service credential change (PATCH /auth/update-credentials).
 * Port of backend/app/Services/AuthService::updateCredentials().
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ FOUR SECURITY RULES, EACH FIXING A REAL HOLE. DO NOT RELAX ANY OF THEM.  │
 * │                                                                          │
 * │ a) currentPassword is required and verified for EVERY field — including  │
 * │    an email-only change. The original only checked it inside             │
 * │    `if (!empty(currentPassword) && !empty(newPassword))`, so a payload    │
 * │    with newPassword but NO currentPassword skipped the block silently    │
 * │    and returned 200 "updated" having changed nothing; and an email       │
 * │    change needed no re-authentication at all, which is the classic       │
 * │    account-takeover primitive for a hijacked session.                    │
 * │                                                                          │
 * │ b) newPassword goes through PasswordPolicy. It used to be hashed and     │
 * │    stored with no check whatsoever — "1" was a valid new admin password. │
 * │                                                                          │
 * │ c) email is format- AND uniqueness-checked BEFORE anything is written,   │
 * │    so a bad address cannot leave the password half-changed.              │
 * │                                                                          │
 * │ d) the session ID is rotated after a PASSWORD change (not after an       │
 * │    email-only one). If an attacker had fixed or stolen the cookie,       │
 * │    changing the password — the user's one remediation — used to leave    │
 * │    that same cookie valid.                                               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ORDERING IS A SECURITY PROPERTY, NOT A STYLE CHOICE, which is why the
 * uniqueness checks are here and not `unique:` rules on the Form Request: they
 * must run AFTER re-authentication succeeds, so that a wrong current password
 * can never be used to probe whether an email address or username is taken.
 */
final class UpdateCredentials
{
    /**
     * @param  array<string, mixed> $data
     * @return array{updated: bool, passwordChanged: bool}
     *
     * @throws ValidationException
     */
    public function __invoke(Request $request, User $user, array $data): array
    {
        $newPassword = $this->nonEmpty($data, 'newPassword');
        $newUsername = $this->nonEmpty($data, 'newUsername');
        $newEmail = $this->nonEmpty($data, 'email');
        $newFullName = $this->nonEmpty($data, 'full_name');

        $newUsername = $newUsername !== null ? trim($newUsername) : null;
        $newEmail = $newEmail !== null ? trim($newEmail) : null;

        // A value identical to the current one is a NO-OP, not a conflict: the
        // settings UI pre-fills both fields with the existing values, so the
        // most common submission resends them unchanged.
        if ($newUsername !== null && $newUsername === $user->username) {
            $newUsername = null;
        }
        if ($newEmail !== null && strcasecmp($newEmail, (string) $user->email) === 0) {
            $newEmail = null;
        }

        $wantsChange = $newPassword !== null
            || $newUsername !== null
            || $newEmail !== null
            || $newFullName !== null;

        if (!$wantsChange) {
            throw ValidationException::withMessages([
                'newPassword' => ['لم يتم إرسال أي بيانات للتحديث'],
            ]);
        }

        // ── (a) Re-authentication gate ────────────────────────────────────
        $currentPassword = $this->nonEmpty($data, 'currentPassword');

        if ($currentPassword === null) {
            SecurityLog::warning('Credential change rejected: currentPassword missing', [
                'event' => 'credentials.change_rejected',
                'reason' => 'missing_current_password',
                'username' => $user->username,
                'target_user_id' => $user->getKey(),
            ]);

            throw ValidationException::withMessages([
                'currentPassword' => ['كلمة المرور الحالية مطلوبة لتأكيد أي تغيير في بيانات الدخول'],
            ]);
        }

        if (!Hash::check($currentPassword, $user->getAuthPassword())) {
            SecurityLog::warning('Credential change rejected: wrong current password', [
                'event' => 'credentials.change_rejected',
                'reason' => 'wrong_current_password',
                'username' => $user->username,
                'target_user_id' => $user->getKey(),
            ]);

            throw ValidationException::withMessages([
                'currentPassword' => ['كلمة المرور الحالية غير صحيحة'],
            ]);
        }

        // ── (b) Password policy ───────────────────────────────────────────
        if ($newPassword !== null) {
            if (!PasswordPolicy::passes($newPassword)) {
                throw ValidationException::withMessages([
                    'newPassword' => [PasswordPolicy::describe()],
                ]);
            }

            if (Hash::check($newPassword, $user->getAuthPassword())) {
                throw ValidationException::withMessages([
                    'newPassword' => ['يجب أن تختلف كلمة المرور الجديدة عن الحالية'],
                ]);
            }
        }

        // ── (c) Identity fields, validated BEFORE anything is written ─────
        $fields = [];

        if ($newEmail !== null) {
            // Case-insensitive and scoped to live rows, matching
            // UserRepository::existsByEmail(). Made explicit rather than left
            // to the column's collation.
            $taken = User::query()
                ->whereRaw('LOWER(email) = LOWER(?)', [$newEmail])
                ->whereKeyNot($user->getKey())
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'email' => ['هذا البريد الإلكتروني مستخدم بالفعل'],
                ]);
            }

            $fields['email'] = $newEmail;
        }

        if ($newUsername !== null) {
            if (User::query()->where('username', $newUsername)->exists()) {
                throw ValidationException::withMessages([
                    'newUsername' => ['اسم المستخدم مستخدم بالفعل'],
                ]);
            }

            $fields['username'] = $newUsername;
        }

        if ($newFullName !== null) {
            $fields['full_name'] = $newFullName;
        }

        // Password + profile fields must land together or not at all.
        DB::transaction(function () use ($user, $newPassword, $fields): void {
            if ($newPassword !== null) {
                $user->forceFill(['password_hash' => Hash::make($newPassword)]);
            }
            if ($fields !== []) {
                $user->forceFill($fields);
            }
            $user->save();
        });

        // ── (d) Session fixation: rotate the identifier ───────────────────
        if ($newPassword !== null) {
            // migrate(true) is session_regenerate_id(true): new id, old server
            // -side record destroyed. The AUTH state survives because it lives
            // in the session payload, which migrate() carries over — so the
            // user stays logged in with a new cookie value, which is exactly
            // what the legacy code did and what the contract test asserts.
            $request->session()->migrate(true);

            SecurityLog::criticalOperation('user.password_changed', [
                'event' => 'credentials.password_changed',
                'target_user_id' => $user->getKey(),
                'username' => $fields['username'] ?? $user->username,
                'target_role' => $user->role->value,
                'session_rotated' => true,
                'changed_at' => now()->toIso8601String(),
                // Never log the password itself, old or new.
            ]);
        }

        if ($fields !== []) {
            SecurityLog::criticalOperation('user.credentials_updated', [
                'event' => 'credentials.profile_updated',
                'target_user_id' => $user->getKey(),
                'username' => $fields['username'] ?? $user->username,
                'changed_fields' => array_keys($fields),
                'changed_at' => now()->toIso8601String(),
            ]);
        }

        return ['updated' => true, 'passwordChanged' => $newPassword !== null];
    }

    /**
     * "Present, a string, and not the empty string" — the legacy
     * `isset($data[$k]) && $data[$k] !== ''` idiom, in one place.
     *
     * @param array<string, mixed> $data
     */
    private function nonEmpty(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
