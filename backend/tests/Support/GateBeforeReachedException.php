<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Thrown by GateProbePolicy::probe(), and by NOTHING else in the codebase.
 *
 * A dedicated class rather than \LogicException or \RuntimeException matters:
 * the Layer 1 assertions are "this exact exception was / was not thrown", and a
 * generic type would also be satisfied by a genuine bug anywhere else in the
 * Gate pipeline — which would make a broken bypass look like a passing test.
 */
final class GateBeforeReachedException extends RuntimeException
{
    public const MESSAGE = 'GateProbePolicy::probe was entered — Gate::before did not short-circuit.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
