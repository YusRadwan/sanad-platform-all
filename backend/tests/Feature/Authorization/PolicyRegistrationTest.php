<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Course;
use App\Policies\CoursePolicy;
use App\Providers\AuthServiceProvider;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * The model => policy wiring is REGISTERED, not merely hoped for.
 *
 * Laravel 12 would discover these by naming convention, and a convention that
 * silently finds nothing is the failure mode this test exists to catch: no
 * policy means no ability, which means the Gate answers false for everyone —
 * or, worse, a caller's own `if` decides instead. In a stage about tenant
 * isolation, a security control that can vanish without a red test is not
 * acceptable.
 *
 * No RefreshDatabase: nothing here touches a row.
 */
final class PolicyRegistrationTest extends TestCase
{
    public function test_every_declared_model_resolves_to_its_declared_policy(): void
    {
        $gate = $this->app->make(GateContract::class);

        foreach (AuthServiceProvider::policyMap() as $model => $policy) {
            $resolved = $gate->getPolicyFor($model);

            $this->assertNotNull($resolved, "{$model} has NO policy — it is unprotected.");
            $this->assertInstanceOf($policy, $resolved, "{$model} resolved to the wrong policy.");
        }
    }

    /**
     * NINE models are covered, and every one of them is now LIVE.
     *
     * ┌── IT WAS SEVEN UNTIL THE CENTER SYSTEM (decision 10.24). ─────────────┐
     * │ Stage 3 registered one live policy (CoursePolicy) and eight          │
     * │ skeletons. Stage 8 implemented the last skeleton                     │
     * │ (AnnouncementPolicy) and REMOVED NotificationPolicy from the map     │
     * │ entirely — approved decision 3.3. Decision 10.24 then ADDED two more │
     * │ — CenterGroupPolicy and CenterSessionPolicy, same direct-owner shape │
     * │ as QuestionGroupPolicy — bringing the live count to nine.            │
     * │                                                                      │
     * │ Routing an inbox row through the Gate would have made the answer     │
     * │ WORSE, not better: `Gate::before` returns true for an admin, so      │
     * │ `authorize('update', $notification)` would let an admin mark a       │
     * │ stranger's notification read, which backend/'s inline comparison     │
     * │ never permitted. The boundary is the relation-scoped QUERY instead,  │
     * │ where no role is exempt. Broadcasts likewise carry no policy: all    │
     * │ five routes are `role:admin` and a policy underneath middleware that │
     * │ already admits exactly one role protects nothing (decision 8.2).     │
     * │                                                                      │
     * │ THE COUNT IS ASSERTED, NOT THE ABSENCE ALONE, so that a policy       │
     * │ silently DISAPPEARING still fails here — which is the whole reason   │
     * │ this file exists.                                                    │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_the_map_covers_the_nine_authorized_models(): void
    {
        $this->assertCount(9, AuthServiceProvider::policyMap());
        $this->assertSame(CoursePolicy::class, AuthServiceProvider::policyMap()[Course::class]);

        $this->assertArrayNotHasKey(
            \App\Models\Notification::class,
            AuthServiceProvider::policyMap(),
            'Decision 3.3: mark-read is scope-based, so Notification must have NO policy.'
        );
        $this->assertArrayNotHasKey(
            \App\Models\BroadcastNotification::class,
            AuthServiceProvider::policyMap(),
            'Decision 8.2: `role:admin` middleware is the whole protection for broadcasts.'
        );
    }

    /**
     * …and Laravel 12's CONVENTION-based discovery does not quietly supply one
     * either. `getPolicyFor()` falls back to the App\Models\X ->
     * App\Policies\XPolicy guess with no registration at all, so deleting the
     * map entry is not on its own proof that no policy resolves.
     */
    public function test_no_policy_resolves_for_the_two_deliberately_unpoliced_models(): void
    {
        $gate = $this->app->make(GateContract::class);

        foreach ([\App\Models\Notification::class, \App\Models\BroadcastNotification::class] as $model) {
            $this->assertNull(
                $gate->getPolicyFor($model),
                "{$model} must resolve to NO policy — its protection is the query / the middleware."
            );
        }
    }

    /**
     * The provider is actually loaded. Without this, every assertion above
     * would still pass on Laravel's auto-discovery while Gate::before — which
     * discovery does NOT provide — was silently absent.
     */
    public function test_the_auth_service_provider_is_registered(): void
    {
        $this->assertContains(
            AuthServiceProvider::class,
            require base_path('bootstrap/providers.php'),
        );

        $this->assertNotEmpty(
            (new \ReflectionProperty(Gate::getFacadeRoot(), 'beforeCallbacks'))->getValue(Gate::getFacadeRoot()),
            'Gate::before is not registered — the admin bypass is missing.'
        );
    }
}
