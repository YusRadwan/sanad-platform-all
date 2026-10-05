<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE STAGE 3 ISOLATION MATRIX. 9 actors x 5 ability/resource columns,     │
 * │ each proven TWICE — once through the Gate, once over HTTP.               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 *   actor                manage  consume  hidden  view(pub)  view(draft)
 *   ───────────────────  ──────  ───────  ──────  ─────────  ───────────
 *   admin                  ✓        ✓        ✓        ✓           ✓
 *   ownerTeacher           ✓        ✓        ✓        ✓           ✓
 *   otherTeacher           ✗        ✗        ✗        ✓           ✗
 *   subscribedStudent      ✗        ✓        ✗        ✓           ✗
 *   unsubscribedStudent    ✗        ✗        ✗        ✓           ✗
 *   expiredSubscriber      ✗        ✗        ✗        ✓           ✗
 *   cancelledSubscriber    ✗        ✗        ✗        ✓           ✗
 *   guest                  ✗        ✗        ✗        ✓           ✗
 *   deactivatedOwner      401      401      401      401         401
 *
 * READ THE TWO COLUMNS THAT DISAGREE, THEY ARE THE WHOLE POINT:
 *
 *   subscribedStudent   consume=✓ but hidden=✗. One actor, one course, two
 *                       answers. Any merge of these abilities is wrong here.
 *   otherTeacher        view(pub)=✓ but everything else=✗. Ownership is not
 *                       visibility; the storefront is public.
 *
 * AND THE ROW THAT DISAGREES WITH ITSELF: deactivatedOwner is 401 on the wire
 * while the POLICY still says yes — because `is_active` is an authentication
 * fact and no policy may read it. See
 * AssertsTenantIsolation::REJECTED_AT_AUTH_GATE.
 *
 * The HTTP half runs against routes/testing.php, which exists only under
 * APP_ENV=testing (VerificationRouteIsolationTest proves it cannot leak).
 */
final class CourseIsolationMatrixTest extends TestCase
{
    use AssertsTenantIsolation;
    use RefreshDatabase;

    private const VERIFY = '/api/v1/_verify';

    private Course $draft;

    protected function setUp(): void
    {
        parent::setUp();

        // The primary fixture is PUBLISHED, so the public-catalogue branch of
        // CoursePolicy::view() is the one exercised by default.
        $this->seedTenantActors(['is_published' => true]);

        // A second course by the SAME owner, unpublished. Same tenant, same
        // actors, opposite visibility — so the view() column below isolates
        // `is_published` as the only variable.
        $this->draft = $this->makeCourseFor($this->ownerTeacher, ['is_published' => false]);
    }

    // ── column 1: manage ──────────────────────────────────────────────────

    public function test_manage_is_isolated_to_the_owning_teacher(): void
    {
        $this->assertIsolated('manage', $this->ownerCourse, [
            'admin' => true,
            'ownerTeacher' => true,
            'otherTeacher' => false,
            'subscribedStudent' => false,
            'unsubscribedStudent' => false,
            'expiredSubscriber' => false,
            'cancelledSubscriber' => false,
            // Denied by the policy AND, earlier, by `auth`. Both are asserted.
            'guest' => [false, 401],
            'deactivatedOwner' => self::REJECTED_AT_AUTH_GATE,
        ], 'PATCH', self::VERIFY . "/courses/{$this->ownerCourse->getKey()}");
    }

    // ── column 2: consumeContent ──────────────────────────────────────────

    public function test_consume_content_admits_the_owner_and_live_subscribers_only(): void
    {
        $this->assertIsolated('consumeContent', $this->ownerCourse, [
            'admin' => true,
            'ownerTeacher' => true,
            'otherTeacher' => false,
            // The paying customer. The ONLY non-owner "true" in this column.
            'subscribedStudent' => true,
            'unsubscribedStudent' => false,
            // status=active, expires_at in the past — Subscription::scopeActive
            // must not trust the stored status.
            'expiredSubscriber' => false,
            // status=cancelled with a FUTURE expiry — the SQL-precedence trap.
            'cancelledSubscriber' => false,
            'guest' => [false, 401],
            'deactivatedOwner' => self::REJECTED_AT_AUTH_GATE,
        ], 'GET', self::VERIFY . "/courses/{$this->ownerCourse->getKey()}/content");
    }

    // ── column 3: viewHidden ──────────────────────────────────────────────

    public function test_view_hidden_is_owner_only_even_for_a_paying_subscriber(): void
    {
        $this->assertIsolated('viewHidden', $this->ownerCourse, [
            'admin' => true,
            'ownerTeacher' => true,
            'otherTeacher' => false,
            /*
             * FALSE, while the very same actor is TRUE for consumeContent on
             * the very same course. Subscribing buys the published course, not
             * the instructor's workbench. This single line is what makes
             * "simplify viewHidden to canConsume()" fail the suite.
             */
            'subscribedStudent' => false,
            'unsubscribedStudent' => false,
            'expiredSubscriber' => false,
            'cancelledSubscriber' => false,
            'guest' => [false, 401],
            'deactivatedOwner' => self::REJECTED_AT_AUTH_GATE,
        ], 'GET', self::VERIFY . "/courses/{$this->ownerCourse->getKey()}/hidden");
    }

    // ── column 4: view, published ─────────────────────────────────────────

    public function test_a_published_course_is_visible_to_every_actor_including_a_rival_teacher(): void
    {
        $this->assertIsolated('view', $this->ownerCourse, [
            'admin' => true,
            'ownerTeacher' => true,
            // TRUE, deliberately: the storefront is public and a rival teacher
            // is just another visitor to it. Isolation is about the workbench
            // and the content, never about the catalogue entry.
            'otherTeacher' => true,
            'subscribedStudent' => true,
            'unsubscribedStudent' => true,
            'expiredSubscriber' => true,
            'cancelledSubscriber' => true,
            /*
             * The Gate says TRUE for a guest — `view` takes `?User` precisely
             * so it can. The 401 comes from this VERIFICATION route being the
             * authenticated course-detail read; the genuinely public
             * storefront shape is exercised by
             * test_the_public_route_degrades_instead_of_denying() below.
             */
            'guest' => [true, 401],
            'deactivatedOwner' => self::REJECTED_AT_AUTH_GATE,
        ], 'GET', self::VERIFY . "/courses/{$this->ownerCourse->getKey()}");
    }

    // ── column 5: view, draft ─────────────────────────────────────────────

    public function test_an_unpublished_course_is_visible_to_its_owner_and_nobody_else(): void
    {
        $this->assertIsolated('view', $this->draft, [
            'admin' => true,
            'ownerTeacher' => true,
            'otherTeacher' => false,
            // A subscription to the owner's OTHER course buys nothing here.
            'subscribedStudent' => false,
            'unsubscribedStudent' => false,
            'expiredSubscriber' => false,
            'cancelledSubscriber' => false,
            'guest' => [false, 401],
            'deactivatedOwner' => self::REJECTED_AT_AUTH_GATE,
        ], 'GET', self::VERIFY . "/courses/{$this->draft->getKey()}");
    }

    // ── the cross-tenant leak target ──────────────────────────────────────

    /**
     * otherTeacher owns an EQUIVALENT course. Every private ability must
     * answer symmetrically: each teacher is the owner of exactly one of the
     * two and a stranger to the other.
     *
     * This is what an "otherTeacher who owns nothing" could never detect — a
     * policy or scope that answered "any instructor may manage any course"
     * would pass a stranger test and fail here.
     */
    public function test_ownership_is_symmetric_between_two_real_tenants(): void
    {
        foreach (['manage', 'consumeContent', 'viewHidden'] as $ability) {
            $this->assertTrue(
                Gate::forUser($this->ownerTeacher)->allows($ability, $this->ownerCourse),
                "owner lost '{$ability}' on their own course"
            );
            $this->assertFalse(
                Gate::forUser($this->ownerTeacher)->allows($ability, $this->otherCourse),
                "CROSS-TENANT LEAK: owner reached '{$ability}' on the rival's course"
            );
            $this->assertTrue(
                Gate::forUser($this->otherTeacher)->allows($ability, $this->otherCourse),
                "rival lost '{$ability}' on their own course"
            );
            $this->assertFalse(
                Gate::forUser($this->otherTeacher)->allows($ability, $this->ownerCourse),
                "CROSS-TENANT LEAK: rival reached '{$ability}' on the owner's course"
            );
        }
    }

    // ── the deactivation gate, stated on its own ──────────────────────────

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ WRITTEN SO IT FAILS IF SOMEONE MOVES THE is_active CHECK INTO A      │
     * │ POLICY. That is its only job.                                        │
     * │                                                                      │
     * │ Two claims, and BOTH must hold simultaneously:                       │
     * │   1. over HTTP the deactivated owner is 401, never 403;              │
     * │   2. at the Gate the deactivated owner is still ALLOWED.             │
     * │                                                                      │
     * │ Claim 2 is the tripwire. A policy that grew `if (! $user->is_active)`│
     * │ would keep claim 1 green (the middleware still fires first) and the  │
     * │ duplication would ship unnoticed. Asserting the policy's answer is   │
     * │ the only way to see it.                                              │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_deactivated_owner_is_stopped_by_authentication_and_not_by_the_policy(): void
    {
        $deactivated = $this->actor('deactivatedOwner');

        $this->assertFalse($deactivated->is_active, 'fixture is not actually deactivated');

        foreach (['manage', 'consumeContent', 'viewHidden', 'view'] as $ability) {
            $this->assertTrue(
                Gate::forUser($deactivated)->allows($ability, $this->ownerCourse),
                "CoursePolicy::{$ability}() has started reading users.is_active. It must not: "
                . 'that is an authentication fact enforced once by EnsureUserIsActive, and '
                . 'duplicating it here answers 403 to a question whose true answer is 401.'
            );
        }

        // And the wire, on the one route whose ability is unambiguous.
        $this->loginAs($this->ownerTeacher);
        \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $this->ownerTeacher->getKey())->update(['is_active' => 0]);
        $this->startNextRequest();

        $response = $this->patchJson(self::VERIFY . "/courses/{$this->ownerCourse->getKey()}");

        $response->assertStatus(401);
        $this->assertNotSame(403, $response->getStatusCode(), 'the POLICY rejected a deactivated owner');
    }

    // ── the public-route degrade convention ───────────────────────────────

    /**
     * The mandatory shape for the ~15 permanently-public routes recorded in
     * docs/authorization-freeze-table.md.
     *
     * A guest gets 200 with every ability flag false — NOT a 403. Calling
     * Gate::authorize()/$this->authorize() on such a route would throw and
     * turn the storefront into an error page for anonymous visitors, which is
     * why the freeze table forbids it outright.
     */
    public function test_the_public_route_degrades_instead_of_denying(): void
    {
        $uri = self::VERIFY . "/public/courses/{$this->ownerCourse->getKey()}";

        $this->flushSession();
        $this->startNextRequest();

        $this->getJson($uri)
            ->assertStatus(200)
            ->assertJsonPath('data.may_view', true)      // published, and a guest may see it
            ->assertJsonPath('data.may_manage', false)
            ->assertJsonPath('data.may_consume', false)
            ->assertJsonPath('data.may_view_hidden', false);

        // The same URL, same status, richer answer — for the owner.
        $this->loginAs($this->ownerTeacher);
        $this->startNextRequest();

        $this->getJson($uri)
            ->assertStatus(200)
            ->assertJsonPath('data.may_manage', true)
            ->assertJsonPath('data.may_view_hidden', true);
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ OPEN DECISION 3.7, AS AN EXECUTABLE FACT.                            │
     * │                                                                      │
     * │ `$request->user()?->can($ability, $r) ?? false` and                  │
     * │ `Gate::forUser($request->user())->allows($ability, $r)` are NOT      │
     * │ interchangeable. For a GUEST the first never reaches the Gate — the  │
     * │ null-safe operator short-circuits on the null USER — so an ability   │
     * │ declared `?User` that legitimately admits guests silently answers    │
     * │ false.                                                               │
     * │                                                                      │
     * │ This test asserts the two spellings DISAGREE for a guest on a        │
     * │ published course. It is not a bug report; it is a lock. If a future  │
     * │ Laravel release, or a change to CoursePolicy::view()'s signature,    │
     * │ makes them agree, this fails and the freeze table's public-route     │
     * │ rule gets revisited deliberately rather than by accident.            │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_the_null_safe_can_idiom_silently_denies_a_guest_a_published_course(): void
    {
        $this->flushSession();
        $this->startNextRequest();

        $response = $this->getJson(self::VERIFY . "/public/courses/{$this->ownerCourse->getKey()}");

        $response->assertStatus(200)
            // The Gate, asked properly, says yes.
            ->assertJsonPath('data.may_view', true)
            // The null-safe idiom, on the identical question, says no.
            ->assertJsonPath('data.may_view_via_nullsafe', false);

        $this->assertNotSame(
            $response->json('data.may_view'),
            $response->json('data.may_view_via_nullsafe'),
            'the two idioms now agree for a guest — re-read open decision 3.7 before relaxing the rule'
        );
    }

    /**
     * A DRAFT course over the public route: still 200, but every flag false
     * for a guest. The degrade pattern must never become a leak — "reduced
     * payload" means reduced, not "the same payload with a flag".
     */
    public function test_the_public_route_reveals_no_ability_on_a_draft_to_a_guest(): void
    {
        $this->flushSession();
        $this->startNextRequest();

        $this->getJson(self::VERIFY . "/public/courses/{$this->draft->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('data.may_view', false)
            ->assertJsonPath('data.may_manage', false)
            ->assertJsonPath('data.may_consume', false)
            ->assertJsonPath('data.may_view_hidden', false);
    }
}
