<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Gate;
use Tests\Support\GateProbe;
use Tests\Support\GateProbePolicy;

/**
 * Wires the permanent Gate::before anchor into every booted test application.
 *
 * ┌── WHY A TRAIT ON THE BASE TestCase AND NOT A LINE IN THE ONE TEST. ──────┐
 * │ The registration has to happen AFTER the application boots (Gate is a    │
 * │ container binding) and BEFORE the test body runs. Putting it in the      │
 * │ probe test's own setUp() would work today and would silently stop        │
 * │ working the moment a second test wants the anchor — Layer 3's inventory  │
 * │ sweep, for one, has to be able to see the registration in                │
 * │ Gate::policies() to prove it EXCLUDES it deliberately.                   │
 * │                                                                          │
 * │ It is a separate trait rather than raw code in TestCase::setUp() so that │
 * │ `grep GateProbe` finds one file that explains the whole mechanism,       │
 * │ instead of three unrelated lines in a class about database creation.     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS REGISTRATION IS TEST-ONLY AND MUST STAY THAT WAY.                   ║
 * ║ tests/ is `autoload-dev`, so neither class exists in a production        ║
 * ║ autoloader; the mapping cannot leak into a running application even if   ║
 * ║ someone copies this line into a real provider by mistake — it would      ║
 * ║ fatal at deploy rather than quietly add an ability.                      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
trait RegistersGateProbe
{
    protected function registerGateProbe(): void
    {
        Gate::policy(GateProbe::class, GateProbePolicy::class);
    }
}
