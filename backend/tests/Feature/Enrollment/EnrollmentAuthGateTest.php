<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ACCESS MATRIX FOR ALL SEVEN ROUTES OF STAGE 7 / SESSION A.          ║
 * ║                                                                          ║
 * ║   route                              guest  student  instructor  admin  ║
 * ║   ─────────────────────────────────  ─────  ───────  ──────────  ─────  ║
 * ║   POST /admin/enrollment-codes        401     403       403        ok   ║
 * ║   GET  /admin/enrollment-codes        401     403       403        ok   ║
 * ║   GET  /admin/enrollment-codes/{c}    401     403       403        ok   ║
 * ║   GET  /admin/subscriptions           401     403       403        ok   ║
 * ║   PUT  /admin/enrollments/toggle      401     403       403        ok   ║
 * ║   GET  /admin/courses/overview        401     403       403        ok   ║
 * ║   POST /enrollment-codes/redeem       401      ok       403        403  ║
 * ║                                                                          ║
 * ║ THE LAST ROW IS THE ONE THAT IS NOT LIKE THE OTHERS, AND IT IS THE ONE  ║
 * ║ THAT MATTERS. An ADMIN must be refused there. `Gate::before` waves      ║
 * ║ admins past every Policy, so if that route's protection had been        ║
 * ║ expressed as a Policy instead of as `role:student` middleware, an admin ║
 * ║ would sail through to an INSERT on `subscriptions.student_id` — an FK   ║
 * ║ to `students.user_id`, a table they have no row in — and get a 500      ║
 * ║ instead of a 403. Same mechanism as open-decisions 3.1 and 5.13.        ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class EnrollmentAuthGateTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    /** @return list<array{0: string, 1: string}> */
    private function adminRoutes(): array
    {
        return [
            ['post', '/api/v1/admin/enrollment-codes'],
            ['get', '/api/v1/admin/enrollment-codes'],
            ['get', '/api/v1/admin/enrollment-codes/12345678'],
            ['get', '/api/v1/admin/subscriptions'],
            ['put', '/api/v1/admin/enrollments/toggle'],
            ['get', '/api/v1/admin/courses/overview'],
        ];
    }

    public function test_a_guest_gets_401_on_every_one_of_the_seven_routes(): void
    {
        $this->disableRateLimiting();

        foreach ($this->adminRoutes() as [$verb, $path]) {
            $this->json($verb, $path)->assertStatus(401);
        }

        $this->postJson('/api/v1/enrollment-codes/redeem', ['code' => '12345678'])
            ->assertStatus(401);
    }

    public function test_a_student_gets_403_on_every_admin_route(): void
    {
        $this->disableRateLimiting();
        [$student] = $this->makeStudentWithCode();
        $this->loginAs($student);

        foreach ($this->adminRoutes() as [$verb, $path]) {
            $this->json($verb, $path)->assertStatus(403);
        }
    }

    public function test_an_instructor_gets_403_on_every_admin_route(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->loginAs($instructor);

        foreach ($this->adminRoutes() as [$verb, $path]) {
            $this->json($verb, $path)->assertStatus(403);
        }
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE ADMIN-CANNOT-REDEEM TEST, AND IT ASSERTS THE **DATABASE**, NOT   │
     * │ ONLY THE STATUS.                                                      │
     * │                                                                      │
     * │ 403 alone would also be produced by a route that refused for the     │
     * │ wrong reason after having already written something. Counting        │
     * │ `subscriptions` before and after is what proves nothing happened.     │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_an_admin_cannot_redeem_a_code_and_writes_nothing(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        $code = $this->makeEnrollmentCode($course);

        $this->loginAsAdmin();

        $this->postJson('/api/v1/enrollment-codes/redeem', ['code' => $code->code])
            ->assertStatus(403);

        self::assertSame(0, Subscription::query()->count());
        self::assertFalse($code->fresh()->is_used);
    }

    public function test_an_instructor_cannot_redeem_a_code_and_writes_nothing(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        $code = $this->makeEnrollmentCode($course);

        $this->loginAs($instructor);

        $this->postJson('/api/v1/enrollment-codes/redeem', ['code' => $code->code])
            ->assertStatus(403);

        self::assertSame(0, Subscription::query()->count());
        self::assertFalse($code->fresh()->is_used);
    }

    /**
     * A SUSPENDED STUDENT IS REFUSED. `active` is in every one of the seven
     * groups; without it a deactivated account keeps its session cookie and
     * keeps redeeming codes.
     *
     * THE STATUS IS **401**, NOT 403, and that is this project's established
     * answer for a suspended session — see SuspensionTakesEffectTest, which
     * pins the same code on four other routes. A deactivated account is not
     * "authenticated but not permitted"; it is no longer authenticated.
     */
    public function test_a_deactivated_student_cannot_redeem(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        $code = $this->makeEnrollmentCode($course);
        [$student] = $this->makeStudentWithCode();

        $this->loginAs($student);
        $student->forceFill(['is_active' => false])->save();
        $this->startNextRequest();

        $this->postJson('/api/v1/enrollment-codes/redeem', ['code' => $code->code])
            ->assertStatus(401);

        self::assertSame(0, Subscription::query()->count());
    }

    /**
     * MIDDLEWARE READ FROM THE ROUTER, NOT INFERRED FROM A STATUS CODE.
     *
     * A status assertion proves a route is protected TODAY; this proves it
     * carries the specific gates the freeze table names, so a future refactor
     * that moved a route out of its group and happened to still 403 for some
     * other reason would fail here.
     */
    public function test_the_router_carries_the_exact_middleware_the_freeze_table_pins(): void
    {
        $admin = ['auth', 'active', 'role:admin'];

        $expected = [
            'admin.enrollment-codes.store' => $admin,
            'admin.enrollment-codes.index' => $admin,
            'admin.enrollment-codes.show' => $admin,
            'admin.subscriptions.index' => $admin,
            'admin.enrollments.toggle' => $admin,
            'admin.courses.overview' => $admin,
            'enrollment-codes.redeem' => ['auth', 'active', 'role:student'],
        ];

        foreach ($expected as $name => $gates) {
            $route = Route::getRoutes()->getByName($name);

            self::assertNotNull($route, "Route {$name} is not registered.");

            foreach ($gates as $gate) {
                self::assertContains(
                    $gate,
                    $route->gatherMiddleware(),
                    "Route {$name} is missing the `{$gate}` middleware."
                );
            }
        }
    }

    /**
     * THE REDEEM ROUTE EXISTS ONLY AT ITS OWN PATH — there is no
     * `/admin/enrollment-codes/redeem`, so a student cannot reach redemption
     * through the admin prefix.
     *
     * The answer is **404**, and via a route that does exist: the literal
     * 'redeem' matches `/admin/enrollment-codes/{code}`, which is registered
     * for GET only, so a POST is a MethodNotAllowedHttpException — which this
     * project's Handler deliberately maps to 404 rather than 405 (a wrong verb
     * on an existing path must not confirm the path exists). Asserted here
     * because it is the shape of the refusal, not an accident of it.
     */
    public function test_redemption_is_not_registered_under_the_admin_prefix(): void
    {
        $this->disableRateLimiting();
        [$student] = $this->makeStudentWithCode();
        $this->loginAs($student);

        $this->postJson('/api/v1/admin/enrollment-codes/redeem', ['code' => '12345678'])
            ->assertStatus(404);

        self::assertSame(0, Subscription::query()->count());
    }
}
