<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Exceptions\UnauthorizedException;
use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\Hash;

/**
 * Re-authenticates the ACTING ADMIN against their OWN password before a
 * destructive action. Used by all three Stage 4 delete Form Requests.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ WHY THE SERVER CHECKS A PASSWORD THE UI ALREADY PROMPTED FOR.           │
 * │                                                                          │
 * │ ManageTeachers.tsx gates teacher deletion behind a "كلمة مرور الإدارة    │
 * │ العامة" prompt and renders "كلمة مرور الإدارة العامة غير صحيحة" when the │
 * │ request fails — but in backend/ NOTHING on the server ever looked at     │
 * │ that password until this check was added. DELETE succeeded with any      │
 * │ string, or with none at all, so the confirmation step was pure theatre:  │
 * │ a stolen or left-open admin session could delete instructors without    │
 * │ knowing the password the UI claims to require.                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ STAGE 4 DECISION #1 — EXTENDED TO ALL THREE DELETES.                    │
 * │                                                                          │
 * │ backend/ enforced this on the TEACHER delete only. Student and generic   │
 * │ user deletion — both equally destructive, both equally reachable from a  │
 * │ hijacked admin session — had no re-authentication at all. All three now  │
 * │ share this one mechanism. That is a deliberate behaviour change from     │
 * │ backend/, decided before implementation, and it means an existing client │
 * │ that deletes a student without sending a password now gets 401 where it  │
 * │ used to get 200.                                                         │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ 401, NOT 422 — AND THAT IS WHY THIS IS NOT `current_password`.          │
 * │                                                                          │
 * │ Laravel ships a `current_password` validation rule that does exactly the │
 * │ right comparison. It is deliberately NOT used: a failing rule produces a │
 * │ ValidationException, which App\Exceptions\Handler maps to 422 with the   │
 * │ error nested under data.errors. The contract here — the status backend/  │
 * │ returned and the status adminService.deleteTeacher()'s error branch      │
 * │ keys on — is a flat 401 carrying one of two exact Arabic sentences.      │
 * │                                                                          │
 * │ Running the check in prepareForValidation() rather than in rules() is    │
 * │ the other half of that: it happens BEFORE the validator, so a delete     │
 * │ request can never answer 422-for-a-missing-password on its way to        │
 * │ answering 401.                                                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * THE PASSWORD MAY ARRIVE TWO WAYS — body `password` or header
 * `x-admin-password` — because adminService.deleteTeacher() sends BOTH. A
 * DELETE with a JSON body is legal but awkward for some clients, which is why
 * the header exists; accepting either keeps every current caller working.
 *
 * IT IS THE ACTOR'S PASSWORD, NEVER THE TARGET'S. Comparing against the
 * account being deleted would be both useless (the admin does not know it) and
 * a disclosure oracle.
 */
trait RequiresAdminReauth
{
    /**
     * FormRequest calls this before validation runs. Every consuming request
     * therefore gets the check for free, in the right order, without
     * remembering to call anything.
     */
    protected function prepareForValidation(): void
    {
        $this->assertActorPassword();
    }

    /**
     * @throws UnauthorizedException 401 with an exact Arabic message.
     */
    protected function assertActorPassword(): void
    {
        $actor = $this->user();

        // Defensive only: `auth` + `role:admin` run ahead of this. If they
        // somehow did not, the answer is still a refusal, never a pass.
        if (!$actor instanceof User) {
            throw new UnauthorizedException('كلمة مرور الإدارة العامة مطلوبة لتأكيد الحذف');
        }

        $supplied = $this->suppliedPassword();

        if ($supplied === null || $supplied === '') {
            throw new UnauthorizedException('كلمة مرور الإدارة العامة مطلوبة لتأكيد الحذف');
        }

        if (!Hash::check($supplied, $actor->getAuthPassword())) {
            SecurityLog::warning('Deletion rejected: wrong admin password', [
                'event' => 'admin.delete_reauth_failed',
                'actor_id' => $actor->getKey(),
                'username' => $actor->getAttribute('username'),
                'path' => $this->path(),
            ]);

            throw new UnauthorizedException('كلمة مرور الإدارة العامة غير صحيحة');
        }
    }

    /**
     * Body first, header second — the order backend/'s controllers used
     * (`$request->input('password') ?? $request->header('x-admin-password')`).
     *
     * A non-string body value (an array, a number) is treated as absent rather
     * than stringified: 'Array' must not become a password attempt.
     */
    private function suppliedPassword(): ?string
    {
        $body = $this->input('password');

        if (is_string($body) && $body !== '') {
            return $body;
        }

        $header = $this->header('x-admin-password');

        return is_string($header) && $header !== '' ? $header : null;
    }
}
