<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\SubscriptionStatus;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;

/**
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE REUSABLE TENANT-ISOLATION CONTRACT. STAGE 3, SESSION 2.             │
 * │                                                                          │
 * │ EVERY STAGE FROM 4 TO 10 IMPORTS THIS TRAIT AND CALLS assertIsolated()  │
 * │ FOR EVERY ABILITY IT ADDS. It is not a Course test that happens to look  │
 * │ generic — Course is merely the only resource that exists today, and it   │
 * │ is passed in as a parameter like any other.                              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ══ WHY THIS EXISTS AT ALL ═════════════════════════════════════════════════
 *
 * A policy is not a security control until it is (a) logically right AND (b)
 * actually wired to the route that reads the resource. Those are two different
 * failures with two different symptoms and only one of them is visible to a
 * `Gate::allows()` unit test:
 *
 *   logically wrong, wired    -> a Gate test fails.  Caught anywhere.
 *   logically RIGHT, NOT wired-> every Gate test passes and the endpoint is
 *                               wide open. Caught ONLY by an HTTP assertion.
 *
 * The second is the one that ships. So assertIsolated() runs BOTH layers for
 * every actor, and a caller that supplies a route gets the second for free.
 *
 * ══ THE NINE ACTORS, AND WHY EACH ONE IS LOAD-BEARING ══════════════════════
 *
 *  admin              the Gate::before bypass. Proves it is reached.
 *  ownerTeacher       the tenant. The only "yes" that is unconditional.
 *  otherTeacher       ┌─ NOT "some teacher who does not own it". A SECOND
 *                     │  teacher who owns an EQUIVALENT resource of their own
 *                     │  (seedTenantActors() builds $this->otherCourse for
 *                     │  exactly this). A cross-tenant leak needs somewhere to
 *                     │  leak FROM; an abstract non-owner cannot detect a
 *                     │  scope that returns "all rows for any teacher".
 *                     └─
 *  subscribedStudent  the paying customer. The ONLY actor for which
 *                     consumeContent and viewHidden must differ — the single
 *                     assertion that keeps the four abilities from being
 *                     "simplified" into one.
 *  unsubscribedStudent a real student, zero subscription rows. Separates
 *                     "is a student" from "is entitled".
 *  guest              no session at all. Separates "public" from "logged in".
 *  expiredSubscriber  status=active, expires_at in the PAST. Pins
 *                     Subscription::scopeActive()'s refusal to trust a stored
 *                     status that a background job has not yet corrected.
 *  cancelledSubscriber status=cancelled, no expiry. The other half of that
 *                     predicate — and the actor that catches the SQL
 *                     precedence bug the scope's docblock warns about.
 *  deactivatedOwner   ┌─ THE DESIGN ASSERTION. See REJECTED_AT_AUTH_GATE.
 *                     └─
 *
 * ══ ERGONOMICS ═════════════════════════════════════════════════════════════
 *
 *   $this->seedTenantActors();
 *   $section = Section::create([... 'course_id' => $this->ownerCourse->id ...]);
 *
 *   $this->assertIsolated('manage', $section, [
 *       'admin'               => true,
 *       'ownerTeacher'        => true,
 *       'otherTeacher'        => false,
 *       'subscribedStudent'   => false,
 *       'unsubscribedStudent' => false,
 *       'expiredSubscriber'   => false,
 *       'cancelledSubscriber' => false,
 *       'guest'               => [false, 401],
 *       'deactivatedOwner'    => self::REJECTED_AT_AUTH_GATE,
 *   ], 'PATCH', "/api/v1/sections/{$section->id}");
 *
 * EVERY ACTOR MUST APPEAR. A missing key is a failed assertion, not a default
 * — "I forgot about cancelled subscribers" must never be silently equivalent
 * to "cancelled subscribers are denied".
 */
trait AssertsTenantIsolation
{
    use CreatesAuthFixtures;

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ "THE POLICY IS **NOT** WHAT REJECTED THIS ACTOR."                    │
     * │                                                                      │
     * │ Meaning, precisely:                                                  │
     * │   HTTP layer    -> the response MUST be 401.                         │
     * │   logical layer -> Gate::allows() must return THE SAME ANSWER AS THE │
     * │                    OWNER for this ability.                           │
     * │                                                                      │
     * │ The second half is the whole point and it is deliberately            │
     * │ counter-intuitive: a deactivated owner is expected to be ALLOWED by  │
     * │ the policy. `users.is_active` is an AUTHENTICATION fact, enforced    │
     * │ once by App\Http\Middleware\EnsureUserIsActive, and a policy that    │
     * │ re-checked it would be a second copy of a security rule — the exact  │
     * │ duplication Stage 3 exists to end — and would answer 403 ("you may   │
     * │ not do this") to a question whose true answer is 401 ("you are not   │
     * │ signed in"). The frontend's retry interceptor branches on that       │
     * │ distinction.                                                         │
     * │                                                                      │
     * │ So if a later stage "helpfully" adds `if (! $user->is_active)` to a  │
     * │ policy, this expectation flips the deactivated owner's Gate answer   │
     * │ to false while the owner's stays true, and the suite fails with a    │
     * │ message naming the mistake. That failure is the feature.             │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public const REJECTED_AT_AUTH_GATE = 401;

    /**
     * Ordered so a failure report reads from most-privileged to least, and so
     * the three "looks subscribed but is not" actors sit together.
     *
     * This is also the completeness checklist: assertIsolated() requires a key
     * for every entry here.
     */
    public const ACTORS = [
        'admin',
        'ownerTeacher',
        'otherTeacher',
        'subscribedStudent',
        'unsubscribedStudent',
        'expiredSubscriber',
        'cancelledSubscriber',
        'guest',
        'deactivatedOwner',
    ];

    /** @var array<string, User|null> */
    protected array $tenantActors = [];

    /** The resource-under-test's course. Stages 4-10 hang their fixture off this. */
    protected Course $ownerCourse;

    /**
     * otherTeacher's OWN course — the leak target.
     *
     * A future stage testing, say, SectionPolicy should build a section under
     * BOTH courses and assert the other teacher cannot reach ours, which is a
     * strictly stronger claim than "a stranger cannot reach ours".
     */
    protected Course $otherCourse;

    protected User $ownerTeacher;

    protected User $otherTeacher;

    /**
     * Builds all nine actors plus the two courses.
     *
     * `$courseAttributes` is passed straight through to makeCourseFor(), so a
     * caller that needs a DRAFT course to test the published/unpublished split
     * says `seedTenantActors(['is_published' => false])` and gets an otherwise
     * identical cast.
     *
     * SUBSCRIPTIONS ARE ATTACHED TO $this->ownerCourse ONLY. otherCourse
     * deliberately has no enrollments: it exists to be leaked into, not to be
     * a second tenant with its own customers.
     *
     * Rate limiting is switched off here because loginAs() drives the REAL
     * login endpoint nine times per assertIsolated() call and would otherwise
     * trip the 5/min bucket partway through a matrix.
     */
    protected function seedTenantActors(array $courseAttributes = ['is_published' => true]): void
    {
        $this->disableRateLimiting();

        $this->ownerTeacher = $this->makeInstructor();
        $this->otherTeacher = $this->makeInstructor();

        $this->ownerCourse = $this->makeCourseFor($this->ownerTeacher, $courseAttributes);
        $this->otherCourse = $this->makeCourseFor($this->otherTeacher, $courseAttributes);

        $subscribed = $this->makeStudentUser();
        $this->makeSubscription($subscribed, $this->ownerCourse, SubscriptionStatus::Active, null);

        $expired = $this->makeStudentUser();
        $this->makeSubscription(
            $expired,
            $this->ownerCourse,
            // status=ACTIVE on purpose. An 'expired' status would prove
            // nothing: the interesting row is the one no job has corrected
            // yet, where only expires_at betrays it.
            SubscriptionStatus::Active,
            now()->subDay(),
        );

        $cancelled = $this->makeStudentUser();
        $this->makeSubscription(
            $cancelled,
            $this->ownerCourse,
            SubscriptionStatus::Cancelled,
            // A FUTURE expiry, deliberately. With the orWhere ungrouped, SQL
            // precedence would turn scopeActive() into
            //   (status='active' AND expires_at IS NULL) OR expires_at > now()
            // and this row would pass. It is the regression fixture for the
            // parenthesisation Subscription::scopeActive() calls load-bearing.
            now()->addYear(),
        );

        $this->tenantActors = [
            'admin' => $this->makeAdmin(),
            'ownerTeacher' => $this->ownerTeacher,
            'otherTeacher' => $this->otherTeacher,
            'subscribedStudent' => $subscribed,
            'unsubscribedStudent' => $this->makeStudentUser(),
            'expiredSubscriber' => $expired,
            'cancelledSubscriber' => $cancelled,
            'guest' => null,
            /*
             * THE SAME DATABASE ROW AS ownerTeacher, presented to the Gate as
             * an unsaved instance carrying is_active = false.
             *
             * `clone` and not a re-query: the object must be the owner in every
             * respect the policy can observe (id, role, teacher_id linkage) and
             * differ ONLY in the attribute a policy is forbidden to consult. If
             * it were a different user the assertion would degrade into "a
             * stranger is denied", which otherTeacher already proves.
             *
             * forceFill, not fill: `is_active` is not mass-assignable, which is
             * correct and is why the fixture has to be explicit.
             *
             * NOT SAVED. The row stays active in the database so the other
             * eight actors are unaffected; the HTTP layer flips the column for
             * the duration of one request and puts it back (see
             * requestAsDeactivatedOwner()).
             */
            'deactivatedOwner' => (clone $this->ownerTeacher)->forceFill(['is_active' => false]),
        ];
    }

    /** @return User|null the guest is a real, addressable actor whose value is null */
    protected function actor(string $name): ?User
    {
        if (! array_key_exists($name, $this->tenantActors)) {
            $this->fail(
                "Unknown isolation actor '{$name}'. Call seedTenantActors() in setUp() first, "
                . 'and use one of: ' . implode(', ', self::ACTORS) . '.'
            );
        }

        return $this->tenantActors[$name];
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE CONTRACT. Two layers, nine actors, one call.                     │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * @param  string  $ability      the policy ability, e.g. 'manage'
     * @param  Model   $resource     the resource instance under test
     * @param  array<string, bool|int|array{0:bool,1:int}>  $expectations
     *         keyed by actor name; EVERY actor in self::ACTORS is required.
     *
     *         Accepted forms:
     *           true                     -> Gate allows;  HTTP 200
     *           false                    -> Gate denies;  HTTP 403
     *           404                      -> Gate denies;  HTTP 404 (DenyMode)
     *           self::REJECTED_AT_AUTH_GATE
     *                                    -> HTTP 401; Gate must answer exactly
     *                                       as the OWNER does. See that
     *                                       constant's docblock.
     *           [bool $gate, int $status]-> explicit both. This is the escape
     *                                       hatch for the common case of a
     *                                       GUEST on an auth-gated route,
     *                                       which the Gate denies (false) but
     *                                       the middleware answers 401 for,
     *                                       long before the policy is asked:
     *                                       ['guest' => [false, 401]].
     *
     * @param  string|null  $httpMethod   omit BOTH to run the logical layer only
     * @param  string|null  $routeUri     full path, e.g. "/api/v1/courses/{$id}"
     */
    protected function assertIsolated(
        string $ability,
        Model $resource,
        array $expectations,
        ?string $httpMethod = null,
        ?string $routeUri = null,
    ): void {
        $this->assertEveryActorIsAccountedFor($ability, $expectations);

        if (($httpMethod === null) !== ($routeUri === null)) {
            $this->fail(
                "assertIsolated('{$ability}'): \$httpMethod and \$routeUri must be supplied together. "
                . 'Pass neither to run the logical layer alone.'
            );
        }

        foreach (self::ACTORS as $name) {
            [$expectedGate, $expectedStatus] = $this->normaliseExpectation($ability, $name, $expectations);

            // ── Layer 1: the policy's own answer ──────────────────────────
            $actualGate = Gate::forUser($this->actor($name))->allows($ability, $resource);

            $this->assertSame(
                $expectedGate,
                $actualGate,
                sprintf(
                    "[logical] Gate::allows('%s', %s) for actor '%s' returned %s, expected %s.%s",
                    $ability,
                    class_basename($resource),
                    $name,
                    $actualGate ? 'true' : 'false',
                    $expectedGate ? 'true' : 'false',
                    $name === 'deactivatedOwner'
                        ? "\n  >> A deactivated owner must be rejected by EnsureUserIsActive (401), NOT by the"
                          . "\n  >> policy. If this line is failing, a policy has started reading is_active."
                          . "\n  >> Remove it: that check belongs to authentication, not authorization."
                        : ''
                )
            );

            // ── Layer 2: the wire ─────────────────────────────────────────
            if ($httpMethod === null || $routeUri === null) {
                continue;
            }

            $response = $this->isolationRequest($name, $httpMethod, $routeUri);

            $this->assertSame(
                $expectedStatus,
                $response->getStatusCode(),
                sprintf(
                    "[http] %s %s as actor '%s' answered %d, expected %d (ability '%s').%s\n  body: %s",
                    strtoupper($httpMethod),
                    $routeUri,
                    $name,
                    $response->getStatusCode(),
                    $expectedStatus,
                    $ability,
                    $expectedStatus === 401 && $response->getStatusCode() === 403
                        ? "\n  >> 403 means a POLICY denied this actor. 401 was owed, which means the"
                          . "\n  >> rejection was supposed to happen at the authentication gate first."
                        : '',
                    $response->getContent() ?: '(empty)'
                )
            );
        }
    }

    /**
     * Issues one authenticated request as the named actor.
     *
     * ┌── WHY A REAL LOGIN AND NOT actingAs() ───────────────────────────────┐
     * │ actingAs() sets the user ON the guard object and writes NO session.  │
     * │ EnsureUserIsActive re-reads the user through the guard, so under     │
     * │ actingAs() it would inspect the in-memory instance the test just     │
     * │ handed it — including a hand-set is_active — instead of the database │
     * │ row. The deactivatedOwner case would then pass for the wrong reason  │
     * │ and would keep passing if the middleware were deleted.               │
     * │                                                                      │
     * │ A genuine POST /auth/login puts only a session id in play, exactly   │
     * │ as production does, so every request re-hydrates from MySQL.         │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    private function isolationRequest(string $actor, string $method, string $uri): TestResponse
    {
        // Each actor starts from zero: no cookie, no cached guard. Without the
        // flush, actor N+1 inherits actor N's session and the whole matrix
        // silently measures one user nine times.
        $this->flushSession();
        $this->startNextRequest();

        if ($actor === 'deactivatedOwner') {
            return $this->requestAsDeactivatedOwner($method, $uri);
        }

        $user = $this->actor($actor);

        if ($user !== null) {
            $this->loginAs($user);
            $this->startNextRequest();
        }

        return $this->json(strtoupper($method), $uri);
    }

    /**
     * Sign in while the account is healthy, then disable it mid-session.
     *
     * This is the ONLY honest way to produce the state: the login endpoint
     * rejects an inactive account outright, so an account deactivated before
     * login could never reach the middleware under test — the assertion would
     * be about /auth/login, not about EnsureUserIsActive.
     *
     * The column is written with the query builder and restored in a finally,
     * so a failed assertion cannot leave the owner disabled for whatever the
     * test does next.
     */
    private function requestAsDeactivatedOwner(string $method, string $uri): TestResponse
    {
        $this->loginAs($this->ownerTeacher);

        DB::table('users')->where('id', $this->ownerTeacher->getKey())->update(['is_active' => 0]);

        try {
            // Mandatory: the guard caches the resolved user for the life of the
            // container, so without this the next request re-uses the ACTIVE
            // instance read a moment ago and answers 200.
            $this->startNextRequest();

            return $this->json(strtoupper($method), $uri);
        } finally {
            DB::table('users')->where('id', $this->ownerTeacher->getKey())->update(['is_active' => 1]);
            $this->startNextRequest();
        }
    }

    /**
     * @param  array<string, mixed>  $expectations
     * @return array{0:bool,1:int}
     */
    private function normaliseExpectation(string $ability, string $actor, array $expectations): array
    {
        $raw = $expectations[$actor];

        if (is_array($raw)) {
            return [(bool) $raw[0], (int) $raw[1]];
        }

        if ($raw === true) {
            return [true, 200];
        }

        if ($raw === false) {
            return [false, 403];
        }

        if ($raw === self::REJECTED_AT_AUTH_GATE) {
            // "Same answer as the owner" — resolved from the caller's own
            // expectation for ownerTeacher so the two can never drift apart.
            [$ownerGate] = $this->normaliseExpectation($ability, 'ownerTeacher', $expectations);

            return [$ownerGate, 401];
        }

        if (is_int($raw)) {
            return [false, $raw];
        }

        $this->fail(
            "assertIsolated('{$ability}'): actor '{$actor}' has an unreadable expectation. "
            . 'Use true, false, an int status, self::REJECTED_AT_AUTH_GATE, or [bool, int].'
        );
    }

    /** @param array<string, mixed> $expectations */
    private function assertEveryActorIsAccountedFor(string $ability, array $expectations): void
    {
        $missing = array_diff(self::ACTORS, array_keys($expectations));
        $unknown = array_diff(array_keys($expectations), self::ACTORS);

        if ($missing !== []) {
            $this->fail(
                "assertIsolated('{$ability}') is missing an expectation for: "
                . implode(', ', $missing)
                . ".\n  >> Every actor must be stated explicitly. An omission must never be"
                . "\n  >> readable as 'denied by default' — that is how a forgotten actor"
                . "\n  >> becomes a silently permitted one when a policy is later widened."
            );
        }

        if ($unknown !== []) {
            $this->fail(
                "assertIsolated('{$ability}') names actors that do not exist: "
                . implode(', ', $unknown) . '. Valid: ' . implode(', ', self::ACTORS) . '.'
            );
        }
    }
}
