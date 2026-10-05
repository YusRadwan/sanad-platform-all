<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;

/**
 * The policy half of the permanent probe. See Tests\Support\GateProbe.
 *
 * ┌── THE BODY THROWS UNCONDITIONALLY, AND THAT IS THE MEASUREMENT. ─────────┐
 * │ "Gate::before short-circuited" is not directly observable — a `true`     │
 * │ coming out of Gate::allows() looks identical whether the bypass produced │
 * │ it or the policy did. Making the policy body IMPOSSIBLE to survive turns │
 * │ the invisible into the observable:                                       │
 * │                                                                          │
 * │   admin      -> allows() returns true, NO exception  => policy not entered│
 * │   instructor -> GateBeforeReachedException           => policy entered   │
 * │   student    -> GateBeforeReachedException           => policy entered   │
 * │                                                                          │
 * │ The instructor/student half is the one that actually protects the        │
 * │ product: it proves the before-callback returned **null** and not `false`.│
 * │ A callback returning `false` would deny every non-admin on every ability │
 * │ across the whole application without a single policy running — and a     │
 * │ test that only checked booleans could not tell that apart from a correct │
 * │ denial, because a correct denial is also `false`.                        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NEVER give this method a real body. Its uselessness is its function.
 */
final class GateProbePolicy
{
    public function probe(User $user, GateProbe $probe): bool
    {
        throw new GateBeforeReachedException();
    }
}
