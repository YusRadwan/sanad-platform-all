<?php

declare(strict_types=1);

namespace Tests\Feature\Revenue;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ACCESS MATRIX FOR ALL 14 ROUTES OF STAGE 7 / SESSION B.             ║
 * ║                                                                          ║
 * ║   route                                    guest student instructor admin║
 * ║   ───────────────────────────────────────  ───── ─────── ────────── ─────║
 * ║   GET  /admin/dashboard                     401    403      403      ok  ║
 * ║   GET  /revenue/platform                    401    403      403      ok  ║
 * ║   GET  /revenue/transactions                401    403      403      ok  ║
 * ║   GET  /revenue/teachers                    401    403      403      ok  ║
 * ║   GET  /revenue/statement/{id}              401    403      403      ok  ║
 * ║   GET  /revenue/transactions/{id}           401    403      403      ok  ║
 * ║   GET  /revenue/payouts/{id}                401    403      403      ok  ║
 * ║   PUT  /revenue/teachers/{id}/percentage    401    403      403      ok  ║
 * ║   POST /revenue/payouts                     401    403      403      ok  ║
 * ║   GET  /revenue/refunds                     401    403      403      ok  ║
 * ║   POST /revenue/refunds                     401    403      403      ok  ║
 * ║   GET  /revenue/my-statement                401    403       ok      403 ║
 * ║   GET  /revenue/my-transactions             401    403       ok      403 ║
 * ║   GET  /revenue/my-payouts                  401    403       ok      403 ║
 * ║                                                                          ║
 * ║ THE LAST THREE ROWS ARE THE ONES THAT ARE NOT LIKE THE OTHERS: **THE    ║
 * ║ ADMIN IS REFUSED**. That is deliberate, it matches backend/'s own route ║
 * ║ group, and it is the self-service pattern Stage 4 established for       ║
 * ║ /teachers/statistics and /students/dashboard. An admin has no revenue   ║
 * ║ of their own, so "my statement" has no meaning for them — and rows      ║
 * ║ 64/65/66 already read ANY teacher's figures by id, so nothing is out of ║
 * ║ reach. `Gate::before`'s admin bypass cannot reopen it: middleware runs  ║
 * ║ before any Gate is consulted, and no Policy is consulted here at all.   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class RevenueAuthGateTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    /** @return list<array{0: string, 1: string}> */
    private function adminRoutes(): array
    {
        return [
            ['get', '/api/v1/admin/dashboard'],
            ['get', '/api/v1/revenue/platform'],
            ['get', '/api/v1/revenue/transactions'],
            ['get', '/api/v1/revenue/teachers'],
            ['get', '/api/v1/revenue/statement/some-id'],
            ['get', '/api/v1/revenue/transactions/some-id'],
            ['get', '/api/v1/revenue/payouts/some-id'],
            ['put', '/api/v1/revenue/teachers/some-id/percentage'],
            ['post', '/api/v1/revenue/payouts'],
            ['get', '/api/v1/revenue/refunds'],
            ['post', '/api/v1/revenue/refunds'],
        ];
    }

    /** @return list<string> */
    private function selfServiceRoutes(): array
    {
        return [
            '/api/v1/revenue/my-statement',
            '/api/v1/revenue/my-transactions',
            '/api/v1/revenue/my-payouts',
        ];
    }

    public function test_a_guest_is_401_on_every_one_of_the_fourteen_routes(): void
    {
        $this->disableRateLimiting();

        foreach ($this->adminRoutes() as [$method, $path]) {
            $this->json($method, $path)->assertStatus(401, "{$method} {$path}");
            $this->startNextRequest();
        }

        foreach ($this->selfServiceRoutes() as $path) {
            $this->getJson($path)->assertStatus(401, $path);
            $this->startNextRequest();
        }
    }

    public function test_a_student_is_403_on_every_one_of_the_fourteen_routes(): void
    {
        $this->disableRateLimiting();
        [$student] = $this->makeStudentWithCode();
        $this->loginAs($student);

        foreach ($this->adminRoutes() as [$method, $path]) {
            $this->json($method, $path)->assertStatus(403, "{$method} {$path}");
        }

        foreach ($this->selfServiceRoutes() as $path) {
            $this->getJson($path)->assertStatus(403, $path);
        }
    }

    /**
     * An INSTRUCTOR is refused every admin route — including the per-teacher
     * ones, EVEN FOR THEIR OWN ID. Row 64 reads any teacher by id and is
     * admin-only; the instructor's door to the same number is row 99.
     */
    public function test_an_instructor_is_403_on_every_admin_revenue_route(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->loginAs($instructor);

        foreach ($this->adminRoutes() as [$method, $path]) {
            $this->json($method, $path)->assertStatus(403, "{$method} {$path}");
        }

        // Their OWN id on the admin-only per-teacher routes is still 403.
        $own = (string) $instructor->getKey();
        $this->getJson('/api/v1/revenue/statement/' . $own)->assertStatus(403);
        $this->getJson('/api/v1/revenue/transactions/' . $own)->assertStatus(403);
        $this->getJson('/api/v1/revenue/payouts/' . $own)->assertStatus(403);
    }

    /**
     * ⚠ THE ADMIN IS REFUSED THE THREE `my-*` ROUTES. No override, by design.
     * If this ever starts answering 200, someone has added `role:admin` to
     * that group or moved the protection into a Policy where Gate::before can
     * wave it through — both of which change what "my" means.
     */
    public function test_an_admin_is_403_on_the_three_self_service_routes(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        foreach ($this->selfServiceRoutes() as $path) {
            $this->getJson($path)->assertStatus(403, $path);
        }
    }

    public function test_an_instructor_is_200_on_all_three_self_service_routes(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->loginAs($instructor);

        foreach ($this->selfServiceRoutes() as $path) {
            $this->getJson($path)->assertStatus(200, $path);
        }
    }

    public function test_an_admin_is_200_on_the_read_only_admin_routes(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->loginAsAdmin();

        foreach ([
            '/api/v1/admin/dashboard',
            '/api/v1/revenue/platform',
            '/api/v1/revenue/transactions',
            '/api/v1/revenue/teachers',
            '/api/v1/revenue/refunds',
            '/api/v1/revenue/statement/' . $instructor->getKey(),
            '/api/v1/revenue/transactions/' . $instructor->getKey(),
            '/api/v1/revenue/payouts/' . $instructor->getKey(),
        ] as $path) {
            $this->getJson($path)->assertStatus(200, $path);
        }
    }

    /**
     * READ FROM THE ROUTER, NOT FROM A STATUS CODE. A 403 could come from a
     * Policy, a Form Request's authorize(), or the middleware — and only the
     * middleware is what the freeze table pins. This asserts the actual
     * middleware stack on all 14 registered routes.
     */
    public function test_the_middleware_stacks_are_exactly_what_the_freeze_table_says(): void
    {
        $byUri = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $byUri[strtolower(implode('|', $route->methods())) . ' ' . $route->uri()] = $route->gatherMiddleware();
        }

        $admin = ['auth', 'active', 'role:admin'];
        $instructor = ['auth', 'active', 'role:instructor'];

        $expected = [
            'get|head api/v1/admin/dashboard' => $admin,
            'get|head api/v1/revenue/platform' => $admin,
            'get|head api/v1/revenue/transactions' => $admin,
            'get|head api/v1/revenue/teachers' => $admin,
            'get|head api/v1/revenue/statement/{teacherId}' => $admin,
            'get|head api/v1/revenue/transactions/{teacherId}' => $admin,
            'get|head api/v1/revenue/payouts/{teacherId}' => $admin,
            'put api/v1/revenue/teachers/{teacherId}/percentage' => $admin,
            'post api/v1/revenue/payouts' => $admin,
            'get|head api/v1/revenue/refunds' => $admin,
            'post api/v1/revenue/refunds' => $admin,
            'get|head api/v1/revenue/my-statement' => $instructor,
            'get|head api/v1/revenue/my-transactions' => $instructor,
            'get|head api/v1/revenue/my-payouts' => $instructor,
        ];

        self::assertCount(14, $expected, 'Stage 7 / Session B is exactly 14 routes.');

        foreach ($expected as $key => $middleware) {
            self::assertArrayHasKey($key, $byUri, "Route not registered: {$key}");

            foreach ($middleware as $name) {
                self::assertContains($name, $byUri[$key], "{$key} is missing '{$name}'");
            }
        }
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THIS TEST WAS INVERTED BY STAGE 10, DELIBERATELY.                    ║
     * ║                                                                      ║
     * ║ Stage 7 / Session B asserted the OPPOSITE — that row 56 (GET          ║
     * ║ /admin/login-activity) was NOT registered — and its docblock said     ║
     * ║ why: "THIS TEST IS EXPECTED TO FAIL WHEN STAGE 10 LANDS. That is its  ║
     * ║ purpose: deleting it must be a deliberate act accompanied by the      ║
     * ║ session-lifecycle work."                                              ║
     * ║                                                                      ║
     * ║ Stage 10 landed. The route is built, and the deliberate act is        ║
     * ║ recorded HERE rather than by silently deleting the method — a         ║
     * ║ vanished test leaves no trace in a diff review, while an inverted     ║
     * ║ one names the stage that inverted it and keeps asserting something.   ║
     * ║                                                                      ║
     * ║ Same protocol open decision 5.12 used for Stage 5's content-item      ║
     * ║ rows. The endpoint's real behaviour is covered by                     ║
     * ║ Tests\Feature\Admin\LoginActivityTest (13 tests); what is asserted    ║
     * ║ here is only the ROUTER fact this file has always owned.              ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_login_activity_route_is_now_registered_by_stage_10(): void
    {
        $byUri = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $byUri[strtolower(implode('|', $route->methods())) . ' ' . $route->uri()] = $route->gatherMiddleware();
        }

        $key = 'get|head api/v1/admin/login-activity';

        self::assertArrayHasKey($key, $byUri, 'Row 56 must be registered from Stage 10 onward.');

        // It reads the PLATFORM-WIDE login history — every account's IPs and
        // user agents — so the role gate is the whole rule and is asserted
        // here, not just its existence.
        foreach (['auth', 'active', 'role:admin'] as $name) {
            self::assertContains($name, $byUri[$key], "Row 56 is missing '{$name}'");
        }
    }
}
