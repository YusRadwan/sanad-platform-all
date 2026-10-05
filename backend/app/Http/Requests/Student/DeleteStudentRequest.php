<?php

declare(strict_types=1);

namespace App\Http\Requests\Student;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\RequiresAdminReauth;

/**
 * DELETE /api/v1/admin/students/{id}.
 *
 * STAGE 4 DECISION #1 — NEW BEHAVIOUR. backend/StudentService::delete() took
 * no actor and verified no password: any admin session could soft-delete any
 * student with a bare DELETE. The teacher delete already required
 * re-authentication, and there is no principled reason the student delete is
 * less destructive — a deleted student loses their enrollments' owner.
 *
 * The mechanism is the shared trait, not a copy of the teacher request's
 * logic; see App\Http\Requests\Concerns\RequiresAdminReauth for the 401-not-422
 * reasoning and for why the check runs in prepareForValidation().
 */
final class DeleteStudentRequest extends ApiFormRequest
{
    use RequiresAdminReauth;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
