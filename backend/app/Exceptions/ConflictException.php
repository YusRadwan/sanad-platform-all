<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A 409 raised by a referential-integrity guard, carrying the Arabic sentence
 * the admin UI displays verbatim.
 *
 * Equivalent of backend/'s `throw new ApiException('…', 409)`. Handler already
 * routes anything implementing HttpExceptionInterface to its own status code
 * and, for a status outside {401,403,404}, returns the exception's message —
 * so no new mapping branch was needed. PublicMessage is implemented anyway, to
 * state the intent explicitly rather than relying on that default branch
 * staying open.
 *
 * WHY 409 AND NOT 422. These are not input-validation failures — the request
 * is well-formed and the id exists. It is the CURRENT STATE OF THE DATA that
 * forbids the delete, and the admin's next step is to change that data (unassign
 * the grade, delete the child rows), not to correct a field. backend/ made the
 * same distinction and frontend/src/services/{catalog,curriculum}Service.ts
 * documents 409 as the "still in use" answer on every remove() method.
 */
final class ConflictException extends ConflictHttpException implements PublicMessage
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
