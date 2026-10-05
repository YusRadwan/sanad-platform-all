<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A 403 whose message is meant for the caller.
 *
 * Port of backend/app/Core/Exceptions/ForbiddenException.php as thrown by
 * RoleMiddleware — which answered the literal string below. Handler otherwise
 * flattens every 403 to 'Forbidden'; implementing PublicMessage keeps the
 * legacy wording so anything written against it (an admin-console error
 * banner, a log grep) sees what it saw before.
 *
 * The message is deliberately English and deliberately vague: unlike the
 * Arabic 404/409 texts next door it is not rendered to an end user, and a role
 * refusal must not describe which role would have been sufficient.
 */
final class ForbiddenException extends AccessDeniedHttpException implements PublicMessage
{
    public function __construct(string $message = 'You do not have access to this resource')
    {
        parent::__construct($message);
    }
}
