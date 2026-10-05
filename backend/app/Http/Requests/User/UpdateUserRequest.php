<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;
use App\Models\User;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/v1/admin/users/{id}.
 *
 * The simplest of the three update surfaces: backend/UserService::update()
 * allow-lists three fields with `array_intersect_key` and writes whatever is
 * present, with NO per-field clear-vs-ignore distinction. That flatness is
 * preserved — see App\Actions\User\UpdateUser for why fill()+save() is
 * acceptable HERE and forbidden for Teacher.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ BOTH `is_active` AND `isActive` ARE ACCEPTED.                           │
 * │                                                                          │
 * │ backend/'s allow-list is snake_case (`['full_name','email','is_active']`)│
 * │ because this endpoint has no frontend caller — grepping frontend/src     │
 * │ finds only the CLIENT-SIDE route '/admin/users', never the API path — so │
 * │ nothing ever forced it into the camelCase convention the teacher and     │
 * │ student endpoints use. Rather than pick one and break the other, both    │
 * │ spellings are accepted: the legacy one for contract compatibility, the   │
 * │ camelCase one because decision #3 is written in those terms and because  │
 * │ every sibling endpoint in this stage speaks it.                          │
 * │                                                                          │
 * │ App\Actions\User\UpdateUser collapses them, snake_case winning when both │
 * │ are sent — the legacy spelling is the one with an existing contract.     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ STAGE 4 DECISION #3 — AN ADMIN MAY NOT DEACTIVATE THEMSELVES.           │
 * │ NEW BEHAVIOUR; backend/ ALLOWED IT, AND THAT WAS A REAL SELF-LOCKOUT.   │
 * │                                                                          │
 * │ backend/UserService::update() compared nothing about the actor. An admin │
 * │ PATCHing their OWN id with is_active:false wrote the column, and         │
 * │ AuthMiddleware then killed their session on the very next request — with │
 * │ no route back in, because the only endpoint that could re-enable the     │
 * │ account requires an admin session. On a platform with one admin that is  │
 * │ an unrecoverable lockout requiring direct database access.               │
 * │                                                                          │
 * │ WHY 422 AND NOT 403. The caller IS authorised — they may deactivate      │
 * │ users, and this very request would succeed against any other id. What is │
 * │ wrong is the VALUE of a field relative to the target, which is the       │
 * │ definition of a validation failure. 403 would also be misread by a       │
 * │ client as "your session is bad" and could trigger a logout, which is     │
 * │ precisely the outcome the guard exists to prevent.                       │
 * │                                                                          │
 * │ WHY IT IS HERE AND NOT IN THE ACTION. A validation-shaped rejection      │
 * │ belongs in the validator, and running it here guarantees the refusal     │
 * │ happens BEFORE any write — the ordering the decision requires.           │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class UpdateUserRequest extends ApiFormRequest
{
    /** The exact sentence an admin sees when they try to disable themselves. */
    public const SELF_DEACTIVATION_MESSAGE = 'لا يمكنك إلغاء تفعيل حسابك الخاص';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * DECISION #3. Runs as an after-validation hook so it sees a value already
     * coerced by the `boolean` rule, and so a malformed `isActive` reports its
     * own type error rather than being silently read as false here.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (!$this->wantsDeactivation()) {
                return;
            }

            $actor = $this->user();
            $targetId = (string) $this->route('id');

            if ($actor instanceof User && $actor->getKey() === $targetId) {
                // Reported against whichever spelling the caller actually
                // sent, so the error lands on the field they can see.
                $validator->errors()->add(
                    $this->has('is_active') ? 'is_active' : 'isActive',
                    self::SELF_DEACTIVATION_MESSAGE
                );
            }
        });
    }

    /**
     * TRUE only when the request explicitly asks for is_active = false.
     *
     * has() first, boolean() second — never boolean() alone. `boolean()`
     * returns false for an ABSENT key, so a payload that merely renames a user
     * would otherwise look like a deactivation attempt and be rejected.
     */
    private function wantsDeactivation(): bool
    {
        foreach (['is_active', 'isActive'] as $key) {
            if ($this->has($key) && $this->boolean($key) === false) {
                return true;
            }
        }

        return false;
    }
}
