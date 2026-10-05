<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * The middleware table for Stage 6 / Session A's routes — read from the
 * ROUTER, not inferred from status codes.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ SECTION 2 WAS INVERTED IN STAGE 6 / SESSION B.                          ║
 * ║                                                                          ║
 * ║ It used to assert that rows 90, 91 and 92 DID NOT EXIST                 ║
 * ║ — Session A's "impossible by construction" guarantee, which was the same ║
 * ║ technique Session 5B used for the public content-item reads. That test   ║
 * ║ was EXPECTED to fail when Session B landed, and its replacement was a    ║
 * ║ documented condition of the hand-off: the routes with their frozen      ║
 * ║ middleware here, the behaviour of each in the sibling Feature files, and ║
 * ║ REAL parallel-process race proofs in tests/Concurrency/.                 ║
 * ║                                                                          ║
 * ║ Section 3 was inverted too: the deferred abilities are now asserted     ║
 * ║ NOT to throw, and `submit` is asserted NOT TO EXIST on ExamPolicy        ║
 * ║ (open decision 6.7).                                                     ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class AssessmentAuthGateTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    /** The eight question-bank routes: name => [method, path]. */
    private const BANK_ROUTES = [
        'question-groups.index' => ['getJson', '/api/v1/question-groups'],
        'question-groups.store' => ['postJson', '/api/v1/question-groups'],
        'question-groups.update' => ['patchJson', '/api/v1/question-groups/x'],
        'question-groups.destroy' => ['deleteJson', '/api/v1/question-groups/x'],
        'question-groups.questions.index' => ['getJson', '/api/v1/question-groups/x/questions'],
        'question-groups.questions.store' => ['postJson', '/api/v1/question-groups/x/questions'],
        'questions.update' => ['patchJson', '/api/v1/questions/x'],
        'questions.destroy' => ['deleteJson', '/api/v1/questions/x'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();
    }

    // ══ 1. THE EIGHT BANK ROUTES ARE auth + active + role ════════════════

    public function test_every_question_bank_route_carries_auth_active_and_the_role_gate(): void
    {
        foreach (array_keys(self::BANK_ROUTES) as $name) {
            $middleware = app('router')->getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

            $this->assertContains('auth', $middleware, "Route {$name} must be behind `auth`.");
            $this->assertContains('active', $middleware, "Route {$name} must be behind `active`.");
            $this->assertContains(
                'role:admin,instructor',
                $middleware,
                "Route {$name} must be behind `role:admin,instructor` — a question bank has no "
                . 'public surface, and unlike content items that is true of the FINAL contract too.'
            );
        }
    }

    public function test_a_guest_gets_401_on_every_question_bank_route(): void
    {
        foreach (self::BANK_ROUTES as $name => [$verb, $url]) {
            $this->{$verb}($url)->assertStatus(401);
        }
    }

    public function test_a_student_gets_403_on_every_question_bank_route(): void
    {
        $this->loginAs($this->makeStudentUser());

        foreach (self::BANK_ROUTES as $name => [$verb, $url]) {
            $this->{$verb}($url)->assertStatus(403);
        }
    }

    // ══ 2. THE SESSION-B ROUTES NOW EXIST, WITH THE RIGHT GATES ══════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THIS REPLACES SESSION A's "THESE MUST NOT EXIST" ASSERTIONS.         ║
     * ║ THE SWAP WAS THE HAND-OFF CONDITION, AND IT IS DELIBERATE.           ║
     * ║                                                                      ║
     * ║ Session A's guarantee was ABSENCE: no handler existed, so no locking  ║
     * ║ could be got wrong. That is spent. What stands in its place is        ║
     * ║   * this test — the routes exist with exactly the middleware          ║
     * ║     the freeze table pins;                                            ║
     * ║   * tests/Feature/Assessment/* — the behaviour of each;                ║
     * ║   * tests/Concurrency/*.php — 8 REAL parallel OS processes against    ║
     * ║     the live locking code, PLUS an unlocked control that must BREACH  ║
     * ║     the cap (a passing race test with no failing control proves       ║
     * ║     nothing but that the harness serialised itself).                  ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    private const SESSION_B_ROUTES = [
        // name => [method, path, middleware that MUST be present]
        'exams.questions' => ['getJson', '/api/v1/exams/x/questions', ['auth', 'active']],
        'exams.results' => ['getJson', '/api/v1/exams/x/results', ['auth', 'active']],
        'exams.submit' => ['postJson', '/api/v1/exams/x/submit', ['auth', 'active', 'throttle.lockout:submit']],
    ];

    public function test_all_session_b_routes_are_registered_with_their_frozen_middleware(): void
    {
        foreach (self::SESSION_B_ROUTES as $name => [, , $required]) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route {$name} must be registered — Stage 6 / Session B.");

            $middleware = $route->gatherMiddleware();

            foreach ($required as $layer) {
                $this->assertContains($layer, $middleware, "Route {$name} must be behind `{$layer}`.");
            }
        }
    }

    /**
     * THE SUBMIT ROUTE MAY NOT CARRY `role:student`, AND THAT IS NOT AN
     * OVERSIGHT.
     *
     * The identity rule ("only a real `students` row may record an attempt")
     * deliberately does NOT live in middleware or in a policy — `Gate::before`
     * waves an admin past any policy, and a role gate would additionally break
     * the instructor preview on rows 90/91. It lives in the Action that
     * performs the write, which is the one layer nothing bypasses.
     */
    public function test_the_submit_route_does_not_use_a_role_gate(): void
    {
        foreach (['exams.submit', 'exams.questions', 'exams.results'] as $name) {
            $middleware = app('router')->getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

            foreach ($middleware as $layer) {
                $this->assertStringStartsNotWith(
                    'role:',
                    $layer,
                    "Route {$name} must not carry a role gate — see App\\Actions\\Exam\\SubmitExamAttempt."
                );
            }
        }
    }

    public function test_a_guest_gets_401_on_every_session_b_route(): void
    {
        foreach (self::SESSION_B_ROUTES as [$verb, $url]) {
            $this->{$verb}($url)->assertStatus(401);
        }
    }

    // ══ 3. THE ABILITIES SESSION B OWED ══════════════════════════════════

    /**
     * The deferred abilities are now REAL — asserted through the GATE
     * (not by reflection, as PolicySkeletonTest does) so that the registration
     * in App\Providers\AuthServiceProvider is part of the claim.
     *
     * A LogicException escaping Gate::allows() would be a 500 on a live route,
     * so "does not throw" is the assertion that matters here.
     */
    public function test_the_session_b_abilities_are_registered_and_no_longer_throw(): void
    {
        [$owner] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($owner);
        $section = $this->makeSection($course);

        [, $exam] = $this->makeExam($section);

        $gate = \Illuminate\Support\Facades\Gate::forUser($owner);

        $this->assertTrue($gate->allows('viewAnswerKey', $exam));
        $this->assertTrue($gate->allows('viewResultsOf', [$exam, (string) $owner->getKey()]));
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ …AND `submit` IS **NOT** AN ABILITY ON ExamPolicy.                   ║
     * ║                                                                      ║
     * ║ Asked through the GATE rather than by reflection (PolicySkeletonTest  ║
     * ║ does the reflection half): Laravel resolves an unknown ability to     ║
     * ║ false for a non-admin, so this also proves nobody has quietly         ║
     * ║ registered a closure-based `submit` in AuthServiceProvider. The       ║
     * ║ actor is a STUDENT, because `Gate::before` would answer true for an   ║
     * ║ admin regardless and prove nothing.                                   ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_submit_is_not_an_ability_on_the_exam_policy(): void
    {
        [$owner] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($owner);
        $section = $this->makeSection($course);

        [, $exam] = $this->makeExam($section);

        $student = $this->makeStudentUser();
        $gate = \Illuminate\Support\Facades\Gate::forUser($student);

        $this->assertFalse($gate->allows('submit', $exam));

        $this->assertFalse(method_exists(\App\Policies\ExamPolicy::class, 'submit'));
    }

    /**
     * THE DATABASE-LEVEL PROOF, INVERTED FROM SESSION A's.
     *
     * Session A swept every route and asserted the table stayed EMPTY. That
     * claim is now false by design, so the equivalent claim is made about the
     * READ surface alone: no route other than the submit can write the
     * table. Counting rows rather than reading responses, for the same reason
     * open decision 5.13's test counts `lesson_progress`: a write and a no-op
     * can be indistinguishable in the body.
     */
    public function test_no_read_route_can_write_an_attempt(): void
    {
        [$owner] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($owner, ['is_published' => true]);
        $section = $this->makeSection($course);

        [$item, $exam] = $this->makeExam($section);

        $student = $this->makeStudentUser();
        $this->makeSubscription($student, $course);
        $this->loginAs($student);

        foreach ([
            "/api/v1/courses/{$course->getKey()}/exams",
            "/api/v1/exams/{$exam->getKey()}",
            "/api/v1/exams/{$exam->getKey()}/questions",
            "/api/v1/exams/{$exam->getKey()}/results",
            "/api/v1/content-items/{$item->getKey()}",
            "/api/v1/sections/{$section->getKey()}/content-items",
        ] as $url) {
            $this->getJson($url);
        }

        $this->assertSame(0, DB::table('exam_attempts')->count());
    }
}
