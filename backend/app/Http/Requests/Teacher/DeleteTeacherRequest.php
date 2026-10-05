<?php

declare(strict_types=1);

namespace App\Http\Requests\Teacher;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\RequiresAdminReauth;

/**
 * DELETE /api/v1/admin/teachers/{id}.
 *
 * The re-authentication is the whole content of this class; it lives in the
 * shared App\Http\Requests\Concerns\RequiresAdminReauth trait, which runs in
 * prepareForValidation() so the 401 precedes any 422.
 *
 * This is the one delete of the three that backend/ already protected. Its two
 * sibling requests (student, user) are new in Stage 4 per decision #1 and use
 * the identical mechanism rather than a copy of it.
 *
 * rules() IS INTENTIONALLY EMPTY. `password` is deliberately NOT declared as a
 * rule: doing so would let the validator answer 422 for the missing-password
 * case, and the contract is 401. The trait has already resolved that question
 * before the validator ever runs.
 */
final class DeleteTeacherRequest extends ApiFormRequest
{
    use RequiresAdminReauth;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
