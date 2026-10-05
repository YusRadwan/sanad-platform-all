<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;

/**
 * A 401 whose Arabic message is meant for the end user.
 *
 * Equivalent of backend/app/Core/Exceptions/UnauthorizedException.php. Extends
 * Laravel's AuthenticationException so App\Exceptions\Handler's existing
 * `$e instanceof AuthenticationException => 401` mapping keeps working with no
 * new branch, and implements PublicMessage so the message survives instead of
 * being flattened to 'Unauthenticated'.
 *
 * Used for the login failures and for the "your account was disabled or
 * deleted while you were signed in" case, both of which the frontend surfaces
 * directly.
 */
final class UnauthorizedException extends AuthenticationException implements PublicMessage
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
