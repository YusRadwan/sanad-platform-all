<?php

declare(strict_types=1);

namespace Tests\Feature\ContentItem;

use App\Models\ContentItem;
use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE "IMPOSSIBLE BY CONSTRUCTION" PROOF FOR STAGE 5 / SESSION B.         ║
 * ║                                                                          ║
 * ║ The session's scope restriction is that NO content-item route may be     ║
 * ║ reachable without a session, because the public-safe DTO does not exist  ║
 * ║ yet. This file asserts that in both directions:                          ║
 * ║                                                                          ║
 * ║   1. Every REGISTERED content-item route answers 401 to a guest.         ║
 * ║   2. Every registered content-item route CARRIES the `auth` middleware   ║
 * ║      — read off the router, not inferred from a status code, because a   ║
 * ║      401 could in principle come from somewhere else.                    ║
 * ║   3. The two PUBLIC routes of the final contract (rows 20 and 21) are    ║
 * ║      NOT REGISTERED AT ALL. A 404 from the router is a stronger          ║
 * ║      guarantee than a 401 from a guard: there is no handler to leak      ║
 * ║      through, so no payload decision can be got wrong.                   ║
 * ║   4. A student — authenticated, even subscribed — cannot reach any of    ║
 * ║      them either, because `role:admin,instructor` sits in front.         ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class ContentItemAuthGateTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    /** The six routes this session registers, as method => path templates. */
    private const REGISTERED = [
        'sections.content-items.store' => ['POST', 'api/v1/sections/{sectionId}/content-items'],
        'sections.content-items.reorder' => ['PATCH', 'api/v1/sections/{sectionId}/content-items/reorder'],
        'content-items.update' => ['PUT', 'api/v1/content-items/{id}'],
        'content-items.destroy' => ['DELETE', 'api/v1/content-items/{id}'],
        'groups.content-items.index' => ['GET', 'api/v1/groups/{groupId}/content-items'],
        'groups.content-items.store' => ['POST', 'api/v1/groups/{groupId}/content-items'],
    ];

    private User $owner;

    private Course $course;

    private Section $section;

    private CourseGroup $group;

    private ContentItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->owner] = $this->makeTeacherWithProfile();
        // PUBLISHED on purpose: if visibility were what protected these routes,
        // a published course would expose them. It is `auth` that protects them.
        $this->course = $this->makeCourseFor($this->owner, ['is_published' => true]);
        $this->section = $this->makeSection($this->course);
        $this->group = $this->makeCourseGroup($this->course);
        $this->item = $this->makeContentItem($this->section);
    }

    /** @return list<array{0:string,1:string}> concrete method+URL pairs */
    private function liveRoutes(): array
    {
        return [
            ['POST', "/api/v1/sections/{$this->section->getKey()}/content-items"],
            ['PATCH', "/api/v1/sections/{$this->section->getKey()}/content-items/reorder"],
            ['PUT', "/api/v1/content-items/{$this->item->getKey()}"],
            ['DELETE', "/api/v1/content-items/{$this->item->getKey()}"],
            ['GET', "/api/v1/groups/{$this->group->getKey()}/content-items"],
            ['POST', "/api/v1/groups/{$this->group->getKey()}/content-items"],
        ];
    }

    // ── 1. GUESTS GET 401 ON ALL SIX ──────────────────────────────────────

    public function test_a_guest_is_401_on_every_route_in_this_session(): void
    {
        foreach ($this->liveRoutes() as [$method, $url]) {
            $this->flushSession();
            $this->startNextRequest();

            $this->json($method, $url, ['title' => 'x', 'type' => 'note'])
                ->assertStatus(401, "{$method} {$url} must be 401 for a guest.");
        }
    }

    /** …and nothing was written on the way to that 401. */
    public function test_a_guest_changes_nothing(): void
    {
        $before = ContentItem::query()->count();

        foreach ($this->liveRoutes() as [$method, $url]) {
            $this->flushSession();
            $this->startNextRequest();
            $this->json($method, $url, ['title' => 'x', 'type' => 'note', 'order' => []]);
        }

        $this->assertSame($before, ContentItem::query()->count());
        $this->assertDatabaseHas('content_items', ['id' => $this->item->getKey()]);
    }

    // ── 2. THE MIDDLEWARE IS ON THE ROUTE, NOT JUST THE OUTCOME ───────────

    /**
     * Read off the ROUTER. A 401 proves the request was refused; this proves
     * WHY, and it keeps proving it if some future change makes an unauthorised
     * request 401 for an unrelated reason.
     */
    public function test_every_route_in_this_session_declares_auth_active_and_the_role_gate(): void
    {
        foreach (self::REGISTERED as $name => [$method, $uri]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route '{$name}' is not registered at all.");
            $this->assertSame($uri, $route->uri(), "Route '{$name}' has moved.");
            $this->assertContains($method, $route->methods(), "Route '{$name}' has changed verb.");

            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth', $middleware, "Route '{$name}' MUST carry `auth`.");
            $this->assertContains('active', $middleware, "Route '{$name}' MUST carry `active`.");
            $this->assertContains(
                'role:admin,instructor',
                $middleware,
                "Route '{$name}' MUST carry `role:admin,instructor` — no content-item route is "
                . 'reachable by a student in this session.'
            );
        }
    }

    /**
     * The COMPLETE content-item route inventory — NINE after Stage 9.
     *
     * Exhaustive on purpose: an extra route cannot appear without this list
     * being updated, which is the moment somebody has to state out loud
     * whether the new path is public. Session B's version asserted six;
     * Session C added `GET /sections/{sectionId}/content-items` and
     * `GET /content-items/{id}`. `GET /courses/{courseId}/videos` is a further
     * content-item route but does not contain the substring, so it is pinned
     * by name in PUBLIC_READS above instead.
     *
     * ┌── STAGE 9 ADDED `GET /content-items/{id}/file` — ROW 73. ────────────┐
     * │ This tripwire fired on it, which is exactly what it is for, and the  │
     * │ statement it demanded is: the route is NOT public and NOT            │
     * │ role-gated. It carries `auth` + `active` and NO `role:` middleware,  │
     * │ because a STUDENT is its primary caller — a subscriber downloading   │
     * │ the note attachment they paid for. The entitlement decision is       │
     * │ ContentItemPolicy::downloadFile, per resource, which is why a        │
     * │ coarse role gate would be both wrong and useless here.               │
     * │                                                                       │
     * │ It is deliberately absent from REGISTERED above (which asserts       │
     * │ `role:admin,instructor` on every member) for that reason, and is     │
     * │ covered instead by tests/Feature/File/ContentItemDownload*Test.      │
     * └───────────────────────────────────────────────────────────────────────┘
     */
    public function test_the_content_item_route_inventory_is_exactly_these_ten(): void
    {
        $registered = [];

        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'content-items')) {
                foreach ($route->methods() as $method) {
                    if ($method !== 'HEAD') {
                        $registered[] = "{$method} {$route->uri()}";
                    }
                }
            }
        }

        sort($registered);

        $this->assertSame([
            'DELETE api/v1/content-items/{id}',
            // ── PUBLIC (Session C) ───────────────────────────────────────
            'GET api/v1/content-items/{id}',
            // ── AUTH, NO ROLE GATE (Stage 9, row 73) ─────────────────────
            'GET api/v1/content-items/{id}/file',
            // ── OWNER-ONLY, decision 10.36 (row 152) — "إحصائيات المشاهدات" ─
            'GET api/v1/content-items/{id}/watch-statistics',
            'GET api/v1/groups/{groupId}/content-items',
            'GET api/v1/sections/{sectionId}/content-items',
            // ─────────────────────────────────────────────────────────────
            'PATCH api/v1/sections/{sectionId}/content-items/reorder',
            'POST api/v1/groups/{groupId}/content-items',
            'POST api/v1/sections/{sectionId}/content-items',
            'PUT api/v1/content-items/{id}',
        ], $registered);
    }

    // ── 3. THE THREE PUBLIC ROUTES NOW EXIST, AND CARRY NO GUARD ──────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE SESSION C HAND-OFF, INVERTED.                                    ║
     * ║                                                                      ║
     * ║ Session B asserted rows 20, 21 and 22 were **404 from the router** — ║
     * ║ no handler, therefore no payload decision that could be got wrong.   ║
     * ║ That test was written to FAIL when Session C landed, and it did.     ║
     * ║ These three replace it, and they assert the opposite property with   ║
     * ║ the same rigour: the routes exist, they answer a GUEST with 200, and ║
     * ║ they carry NO `auth`, NO `active` and NO `role:` middleware.         ║
     * ║                                                                      ║
     * ║ Read off the ROUTER, not inferred from a status code — a 200 proves  ║
     * ║ this request got through; this proves no future middleware quietly   ║
     * ║ closed the public catalogue.                                         ║
     * ║                                                                      ║
     * ║ The guarantee that replaced "there is no route" is                    ║
     * ║ PublicContentItemResource being a structurally separate class, and   ║
     * ║ it is asserted in PublicPayloadLeakTest across all six content       ║
     * ║ types. Neither file is sufficient alone.                             ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    private const PUBLIC_READS = [
        'sections.content-items.index' => ['GET', 'api/v1/sections/{sectionId}/content-items'],
        'content-items.show' => ['GET', 'api/v1/content-items/{id}'],
        'courses.videos.index' => ['GET', 'api/v1/courses/{courseId}/videos'],
    ];

    public function test_the_three_public_reads_carry_no_auth_no_active_and_no_role_gate(): void
    {
        foreach (self::PUBLIC_READS as $name => [$method, $uri]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route '{$name}' must be registered — Session C owns it.");
            $this->assertSame($uri, $route->uri(), "Route '{$name}' has moved.");
            $this->assertContains($method, $route->methods());

            $middleware = $route->gatherMiddleware();

            foreach (['auth', 'active'] as $guard) {
                $this->assertNotContains(
                    $guard,
                    $middleware,
                    "Route '{$name}' MUST NOT carry `{$guard}` — it renders on the logged-out "
                    . 'storefront, and adding a guard is an outage, not a tightening.'
                );
            }

            foreach ($middleware as $layer) {
                $this->assertStringStartsNotWith(
                    'role:',
                    (string) $layer,
                    "Route '{$name}' MUST NOT carry a role gate."
                );
            }
        }
    }

    /** A guest reaches all three and gets a 200 — the DEGRADE contract, live. */
    public function test_a_guest_gets_200_on_all_three_public_reads(): void
    {
        $urls = [
            "/api/v1/sections/{$this->section->getKey()}/content-items",
            "/api/v1/content-items/{$this->item->getKey()}",
            "/api/v1/courses/{$this->course->getKey()}/videos",
        ];

        foreach ($urls as $url) {
            $this->flushSession();
            $this->startNextRequest();

            $this->getJson($url)->assertStatus(200, "GET {$url} must be 200 for a guest.");
        }
    }

    /**
     * …and the SIX WRITES are still exactly as locked as Session B left them.
     *
     * This is the regression that matters most about opening public routes:
     * the read surface widened, the write surface did not move an inch.
     */
    public function test_opening_the_public_reads_did_not_widen_any_write_route(): void
    {
        foreach (array_keys(self::REGISTERED) as $name) {
            $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

            $this->assertContains('auth', $middleware, "Route '{$name}' lost `auth`.");
            $this->assertContains('role:admin,instructor', $middleware, "Route '{$name}' lost its role gate.");
        }
    }

    // ── 4. A STUDENT IS STOPPED BY THE ROLE GATE ──────────────────────────

    /**
     * A SUBSCRIBED student — the most privileged non-staff actor there is —
     * still gets 403 on every route. `role:admin,instructor` refuses before
     * any policy is consulted, so the entitlement question the subscription
     * would raise is never even asked.
     */
    public function test_even_a_subscribed_student_is_refused_on_every_route(): void
    {
        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $this->course);

        foreach ($this->liveRoutes() as [$method, $url]) {
            $this->flushSession();
            $this->startNextRequest();
            $this->loginAs($student);
            $this->startNextRequest();

            $this->json($method, $url, ['title' => 'x', 'type' => 'note'])
                ->assertStatus(403, "{$method} {$url} must be 403 for a student.");
        }

        $this->assertDatabaseHas('content_items', ['id' => $this->item->getKey()]);
    }
}
