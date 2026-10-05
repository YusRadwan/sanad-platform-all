<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\RequiresAdminReauth;

/**
 * DELETE /api/v1/admin/users/{id}.
 *
 * STAGE 4 DECISION #1 — NEW BEHAVIOUR. backend/UserService::delete() verified
 * nothing. This is the most dangerous of the three deletes, because unlike the
 * teacher and student endpoints it can target ANY role — including another
 * administrator.
 *
 * Mechanism shared with the other two; see
 * App\Http\Requests\Concerns\RequiresAdminReauth.
 *
 * NOTE there is deliberately no "you cannot delete yourself" guard here, even
 * though decision #3 adds exactly that shape of rule to the UPDATE endpoint.
 * The two are not the same question: self-deactivation is a silent, easily
 * mistyped side effect of a routine edit, whereas self-deletion is an explicit
 * DELETE against your own id that already requires re-typing your password.
 * Adding an unrequested guard here would be a product decision this stage was
 * not asked to make — it is logged in docs/open-decisions.md instead.
 */
final class DeleteUserRequest extends ApiFormRequest
{
    use RequiresAdminReauth;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
