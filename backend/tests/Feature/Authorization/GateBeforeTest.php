<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Course;
use App\Models\User;
use App\Providers\AuthServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\Support\GateBeforeReachedException;
use Tests\Support\GateProbe;
use Tests\Support\GateProbePolicy;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ Gate::before — THE ADMIN BYPASS, AND THE `null` THAT MAKES EVERYONE      ║
 * ║ ELSE'S POLICY RUN. REDESIGNED IN STAGE 8 INTO THREE INDEPENDENT LAYERS.  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── WHY IT HAD TO BE REDESIGNED, AND NOT JUST MOVED AGAIN. ────────────────┐
 * │ From Stage 3 the probe proved "an admin never enters the policy" by      │
 * │ calling an ability whose body was still a `LogicException` placeholder:  │
 * │ if the call did NOT blow up, the policy had genuinely not been entered.  │
 * │ Sound — but PARASITIC on unfinished work. It moved three times as its    │
 * │ host graduated (Section -> Exam -> Announcement, open decisions 5.7,     │
 * │ 5.9, 5.16, 7.16), and 7.16 warned Stage 8 that the technique would run   │
 * │ out here: Stage 8 implements AnnouncementPolicy and deletes              │
 * │ NotificationPolicy, so no borrowable skeleton is left.                    │
 * │                                                                          │
 * │ The two obvious "fixes" are both silent failures: DELETING the test      │
 * │ removes the only proof the bypass short-circuits, and RE-POINTING it at  │
 * │ an ability that no longer throws makes it assert `true === true` forever.│
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * The three layers are independent — each would catch a broken bypass on its
 * own, and none can go stale for the reason the old probe did:
 *
 *   LAYER 1  A PERMANENT SYNTHETIC ANCHOR. Tests\Support\GateProbe is a plain
 *            PHP object owned by no stage, with a policy whose body always
 *            throws. Behavioural, end-to-end through the real Gate. It can
 *            never graduate, because there is nothing to graduate it into.
 *
 *   LAYER 2  THE REGISTERED CLOSURE ITSELF, read back out of the Gate by
 *            reflection and invoked directly. This is the only layer that can
 *            see the difference between `null` and `false` AS A RETURN VALUE
 *            rather than by its downstream effect.
 *
 *   LAYER 3  A FULL INVENTORY SWEEP over every registered policy and every
 *            ability on it. Grows by itself as policies are added, and FAILS
 *            LOUDLY if anyone ever carves an ability out of the bypass without
 *            recording it in EXPECTED_BYPASS_EXCEPTIONS below.
 *
 * All three go through / read the REAL Gate. None calls a policy method by
 * hand: the behaviour under test belongs to the framework's before-callback
 * pipeline and disappears entirely if you bypass it.
 */
final class GateBeforeTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $owner;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();

        $this->owner = $this->makeInstructor();
        $this->course = $this->makeCourseFor($this->owner);
    }

    // ══ LAYER 1 — THE PERMANENT SYNTHETIC ANCHOR ═════════════════════════

    /**
     * An admin reaches `true` WITHOUT the policy body executing.
     *
     * GateProbePolicy::probe() throws unconditionally, so "no exception" is the
     * observable that proves short-circuiting. `true` alone would not: a
     * before-callback that wrongly returned `true` for everyone, and a policy
     * that legitimately returned `true`, are indistinguishable by return value.
     */
    public function test_layer1_an_admin_short_circuits_before_the_probe_policy_runs(): void
    {
        $allowed = Gate::forUser($this->makeAdmin())->allows('probe', new GateProbe());

        $this->assertTrue($allowed, 'Gate::before must answer true for an admin.');
    }

    /**
     * …and an INSTRUCTOR really does reach the throwing body.
     *
     * This is the half that proves the callback returned **null**. Had it
     * returned `false`, Gate would have stopped at the callback, answered
     * `false`, and nothing would have thrown — a total silent outage of every
     * instructor and student surface that no boolean assertion could detect.
     */
    public function test_layer1_an_instructor_reaches_the_probe_policy_body(): void
    {
        $this->expectException(GateBeforeReachedException::class);

        Gate::forUser($this->owner)->allows('probe', new GateProbe());
    }

    /** A student is not an admin either — no role but `admin` bypasses. */
    public function test_layer1_a_student_reaches_the_probe_policy_body(): void
    {
        $this->expectException(GateBeforeReachedException::class);

        Gate::forUser($this->makeStudentUser())->allows('probe', new GateProbe());
    }

    /**
     * The anchor is genuinely synthetic: nothing in app/ knows it exists, so no
     * future stage can "implement" it and quietly stop the layer above proving
     * anything. Asserted rather than trusted, because the whole point of the
     * redesign is that the previous anchor's staleness was invisible.
     */
    public function test_layer1_the_anchor_is_not_tied_to_any_application_class(): void
    {
        $this->assertStringStartsWith('Tests\\', GateProbe::class);
        $this->assertStringStartsWith('Tests\\', GateProbePolicy::class);

        $this->assertFalse(
            is_subclass_of(GateProbe::class, \Illuminate\Database\Eloquent\Model::class),
            'GateProbe must stay a plain object — an Eloquent model would need a table and could acquire a stage owner.'
        );

        $this->assertNotEmpty(
            array_filter(
                Gate::policies(),
                static fn (string $policy, string $model): bool => $model === GateProbe::class,
                ARRAY_FILTER_USE_BOTH
            ),
            'The probe policy must be registered by Tests\Concerns\RegistersGateProbe for every test.'
        );
    }

    // ══ LAYER 2 — THE REGISTERED CLOSURE, INVOKED DIRECTLY ═══════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ `assertNull`, NOT `assertFalse`. THE DISTINCTION IS THE WHOLE TEST.  ║
     * ║                                                                      ║
     * ║ Gate::callBeforeCallbacks() (vendor/laravel/framework/src/Illuminate/ ║
     * ║ Auth/Access/Gate.php) stops at the first callback returning anything  ║
     * ║ other than null:                                                      ║
     * ║                                                                      ║
     * ║     if (! is_null($result = $before(...))) { return $result; }        ║
     * ║                                                                      ║
     * ║ `false` is not null. A callback returning `false` for non-admins      ║
     * ║ would DENY every instructor and student on EVERY ability of EVERY     ║
     * ║ model before a single policy ran. `null` means "no opinion, carry on  ║
     * ║ to the policy".                                                       ║
     * ║                                                                      ║
     * ║ Layers 1 and 3 can only observe the CONSEQUENCE of that difference.   ║
     * ║ This layer observes the VALUE, by pulling the closure back out of the ║
     * ║ Gate's own `$beforeCallbacks` and calling it. It is therefore the     ║
     * ║ only layer that still means something if the policy map is empty.     ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_layer2_the_registered_closure_returns_true_for_admin_and_null_for_everyone_else(): void
    {
        $before = $this->registeredBeforeCallbacks();

        $this->assertCount(
            1,
            $before,
            'There must be exactly ONE before-callback. A second one is a second admin bypass, '
            . 'which is precisely what AuthServiceProvider exists to prevent.'
        );

        $callback = $before[0];

        $this->assertTrue(
            $callback($this->makeAdmin(), 'any-ability-at-all'),
            'An admin must be waved through by the callback itself.'
        );

        $this->assertNull(
            $callback($this->owner, 'any-ability-at-all'),
            'An instructor must get NULL — `false` here would deny every instructor on every ability.'
        );

        $this->assertNull(
            $callback($this->makeStudentUser(), 'any-ability-at-all'),
            'A student must get NULL — `false` here would deny every student on every ability.'
        );
    }

    /**
     * The callback is ABILITY-AGNOSTIC. Invoked directly with a spread of
     * ability names — real, deleted and imaginary — it must give the same
     * answer to all of them.
     *
     * This is the structural guarantee behind open decision 3.1: an admin
     * arrives at the ACTION with no policy having run, whatever the ability was
     * called, which is exactly why the "only a real student may submit" and
     * "only a real student may record progress" rejections cannot live in a
     * policy body.
     */
    public function test_layer2_the_closure_does_not_special_case_any_ability(): void
    {
        $callback = $this->registeredBeforeCallbacks()[0];
        $admin = $this->makeAdmin();
        $instructor = $this->owner;

        foreach (['manage', 'view', 'watch', 'submit', 'downloadFile', 'probe', 'not-a-real-ability'] as $ability) {
            $this->assertTrue($callback($admin, $ability), "Admin must bypass '{$ability}'.");
            $this->assertNull($callback($instructor, $ability), "Instructor must defer on '{$ability}'.");
        }
    }

    /**
     * The `User $user` type hint (NOT `?User`) is a GUARD, not a formality:
     * Gate::canBeCalledWithUser() skips any before-callback whose parameter is
     * non-nullable when the user is null, which makes a guest STRUCTURALLY
     * incapable of tripping the bypass. Asserted on the signature, because a
     * later "tidy-up" widening it to `?User` would compile, pass every other
     * test here, and hand every guest an admin's answer.
     */
    public function test_layer2_the_closure_refuses_a_null_user_by_its_signature(): void
    {
        $parameter = (new \ReflectionFunction($this->registeredBeforeCallbacks()[0]))->getParameters()[0];
        $type = $parameter->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(User::class, $type->getName());
        $this->assertFalse(
            $type->allowsNull(),
            'Widening this to ?User would let a logged-out caller reach the admin bypass.'
        );
    }

    // ══ LAYER 3 — THE FULL INVENTORY SWEEP ═══════════════════════════════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE ESCAPE-HATCH LEDGER — AND IT IS EMPTY ON PURPOSE.                ║
     * ║                                                                      ║
     * ║ An entry here would mean "this ability is NOT covered by the admin    ║
     * ║ bypass", i.e. someone carved an ability name out of Gate::before.     ║
     * ║ THAT HAS NEVER BEEN DONE AND MUST NOT BE: the whole argument for the  ║
     * ║ callback is that backend/ repeated `if ($role === 'admin') return;`   ║
     * ║ in nine places and this replaces all nine with one rule. A per-       ║
     * ║ ability exception list inside it would put a SECOND authorization     ║
     * ║ rule in the one place designed to hold exactly one.                   ║
     * ║                                                                      ║
     * ║ FOUR genuine escape hatches ARE documented across Stages 3-7 — and    ║
     * ║ every one of them lives OUTSIDE the Gate, which is why this array is  ║
     * ║ still empty. They are listed in ESCAPE_HATCHES_OUTSIDE_THE_GATE below ║
     * ║ so a reader who greps for "escape hatch" lands on the real ones.      ║
     * ║                                                                      ║
     * ║ IF YOU ARE ADDING AN ENTRY HERE: stop. Write the rule in the Action,  ║
     * ║ the Form Request or the Support class that performs the write, the    ║
     * ║ way all four precedents did — then this array stays empty and the     ║
     * ║ sweep below stays a real assertion instead of a list of exemptions.   ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * @var array<class-string, list<string>>
     */
    private const EXPECTED_BYPASS_EXCEPTIONS = [];

    /**
     * The four documented precedents, each a rejection an ADMIN must still
     * receive even though `Gate::before` waved them past every policy. All four
     * are enforced in a layer the Gate does not reach.
     *
     * @var array<string, string>
     */
    private const ESCAPE_HATCHES_OUTSIDE_THE_GATE = [
        // 3.1 — exam_attempts.student_id is a FK into students.user_id and an
        // admin has no students row. Enforced in the Action, 403 with a literal
        // message. The ability itself was DELETED in 6B (decision 6.13).
        'App\Actions\Exam\SubmitExamAttempt' => 'Only an enrolled student may submit an exam attempt',
        // 5.13 — the same FK mechanism, but the contract owes 200-with-no-write
        // rather than 403: progress is fire-and-forget from a throttled
        // timeupdate handler. Proven by counting rows, not by reading a body.
        'App\Actions\Lesson\SaveLessonProgress' => 'no-op for a non-student',
        // 6.2 — an exam under a soft-deleted course was 404 for everyone and
        // 200 for the admin, because the policy's fail-closed branch on a null
        // course is never entered for them. Existence is checked BEFORE the gate.
        'App\Support\AssessmentAccess::assertParentCourseExists' => 'Course not found',
        // Course writes — the grade/subject "assigned to this teacher" rule is
        // deliberately NOT attached for an admin, rather than re-deriving the
        // bypass inside a Rule the Gate never touches.
        'App\Http\Requests\Course\CourseWriteRequest' => 'AssignedToTeacher not attached for admins',
    ];

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE SECURITY PAYOFF OF THE WHOLE REDESIGN.                           ║
     * ║                                                                      ║
     * ║ Enumerate EVERY policy the Gate actually resolves and EVERY public    ║
     * ║ ability on it, and assert an admin is authorized for all of them.     ║
     * ║ The list is read from Gate::policies() — the live registry — so it    ║
     * ║ GROWS BY ITSELF as later stages add policies. No stage has to          ║
     * ║ remember to extend it, which is the failure mode every hand-maintained║
     * ║ inventory eventually hits.                                            ║
     * ║                                                                      ║
     * ║ If a sixth escape hatch is ever introduced by carving an ability out  ║
     * ║ of Gate::before, this fails LOUDLY and names the ability, demanding   ║
     * ║ EXPECTED_BYPASS_EXCEPTIONS be updated — i.e. demanding the decision   ║
     * ║ be documented rather than merged.                                     ║
     * ║                                                                      ║
     * ║ The abilities are invoked with UNSAVED model instances on purpose:    ║
     * ║ a correct bypass never dereferences them, and several bodies would    ║
     * ║ throw or answer nonsense if they were entered. Anything other than a  ║
     * ║ clean `true` here IS the bug being hunted.                            ║
     * ║                                                                      ║
     * ║ NOTE `Gate::allows()` — not `authorize()`. Since Stage 9 an ability   ║
     * ║ may return an `Illuminate\Auth\Access\Response` rather than a bool    ║
     * ║ (ContentItemPolicy::downloadFile does). allows() reads either one as  ║
     * ║ a boolean, so the sweep is agnostic to which form a policy chose and  ║
     * ║ needs no maintenance when one changes.                                ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_layer3_an_admin_is_authorized_for_every_ability_of_every_registered_policy(): void
    {
        $admin = $this->makeAdmin();
        $swept = 0;

        foreach (Gate::policies() as $model => $policy) {
            foreach ($this->abilitiesOf($policy) as $ability) {
                $swept++;

                if (\in_array($ability, self::EXPECTED_BYPASS_EXCEPTIONS[$policy] ?? [], true)) {
                    continue;
                }

                $arguments = $this->argumentsFor($policy, $ability, $model);

                $this->assertTrue(
                    Gate::forUser($admin)->allows($ability, $arguments),
                    "Gate::before must exempt an admin from {$policy}::{$ability}(). If this refusal is "
                    . 'DELIBERATE, it is a sixth escape hatch: record it in EXPECTED_BYPASS_EXCEPTIONS '
                    . 'with its argument, and in docs/open-decisions.md. If it is not deliberate, the '
                    . 'admin bypass has been broken.'
                );
            }
        }

        // A registry that silently resolved to nothing would make every
        // assertion above vacuous — the exact way an inventory test rots.
        $this->assertGreaterThanOrEqual(
            20,
            $swept,
            'The sweep found implausibly few abilities; Gate::policies() is probably not populated.'
        );
    }

    /**
     * The ledger is empty, and that fact is asserted rather than left implicit.
     *
     * Without this, someone could add an exception, watch the sweep above go
     * green, and never be asked why — which is exactly the review conversation
     * the ledger exists to force.
     */
    public function test_layer3_no_ability_has_been_carved_out_of_the_bypass(): void
    {
        $this->assertSame(
            [],
            self::EXPECTED_BYPASS_EXCEPTIONS,
            'Gate::before holds exactly one rule. An ability-name exception inside it is a second '
            . 'authorization rule in the one place designed to hold one. All four documented escape '
            . 'hatches live outside the Gate — see ESCAPE_HATCHES_OUTSIDE_THE_GATE.'
        );

        $this->assertCount(
            4,
            self::ESCAPE_HATCHES_OUTSIDE_THE_GATE,
            'Four escape hatches are documented across Stages 3-7. A fifth must be added here AND to '
            . 'docs/open-decisions.md before its enforcement layer is written.'
        );
    }

    /**
     * The registry the sweep reads is the one AuthServiceProvider declares —
     * no more, no less. A policy resolved by Laravel 12's NAMING CONVENTION but
     * absent from the explicit map would be invisible to the audit the map
     * exists to enable, and a map entry the Gate does not actually resolve
     * would be a control that has silently disappeared.
     */
    public function test_layer3_the_swept_registry_is_the_declared_policy_map(): void
    {
        $registered = Gate::policies();

        // The synthetic anchor is registered by the test harness, not by the
        // application, so it is removed before comparing.
        unset($registered[GateProbe::class]);

        $this->assertSame(AuthServiceProvider::policyMap(), $registered);
    }

    // ══ THE ORIGINAL BEHAVIOURAL ASSERTIONS — KEPT, NOT REPLACED ═════════

    /** An admin manages a course they do not own, and never will own. */
    public function test_an_admin_may_manage_a_course_they_do_not_own(): void
    {
        $admin = $this->makeAdmin();

        $this->assertFalse(
            (string) $this->course->teacher_id === (string) $admin->getKey(),
            'Precondition: the admin must genuinely not be the owner.'
        );

        $this->assertTrue(Gate::forUser($admin)->allows('manage', $this->course));
    }

    /** The bypass is ability-agnostic on a REAL model too, not only in Layer 2. */
    public function test_an_admin_passes_every_course_ability(): void
    {
        $admin = $this->makeAdmin();

        foreach (['view', 'manage', 'consumeContent', 'viewHidden'] as $ability) {
            $this->assertTrue(
                Gate::forUser($admin)->allows($ability, $this->course),
                "Admin should pass '{$ability}'."
            );
        }
    }

    /**
     * The owner and a stranger get DIFFERENT answers for the same ability on
     * the same course. Only CoursePolicy::manage() can distinguish them, so a
     * true/false split proves it executed — something a short-circuited gate
     * cannot fake in either direction.
     */
    public function test_a_non_admin_actually_reaches_the_policy_method(): void
    {
        $stranger = $this->makeInstructor();

        $ownerAnswer = Gate::forUser($this->owner)->allows('manage', $this->course);
        $strangerAnswer = Gate::forUser($stranger)->allows('manage', $this->course);

        $this->assertTrue($ownerAnswer, 'The owner must be allowed — a gate returning false for all non-admins would fail here.');
        $this->assertFalse($strangerAnswer, 'A stranger must be denied — a gate returning true for all non-admins would fail here.');
        $this->assertNotSame(
            $ownerAnswer,
            $strangerAnswer,
            'Two different answers for the same ability can only come from the policy having run.'
        );
    }

    /**
     * The same proof from the other direction, using Gate::inspect(), whose
     * Response object records HOW the decision was reached rather than only
     * what it was.
     */
    public function test_a_non_admin_denial_is_a_policy_denial(): void
    {
        $response = Gate::forUser($this->makeInstructor())->inspect('manage', $this->course);

        $this->assertTrue($response->denied());
    }

    /** A student is not an admin either — no role but `admin` bypasses. */
    public function test_a_student_does_not_bypass(): void
    {
        $this->assertFalse(
            Gate::forUser($this->makeStudentUser())->allows('manage', $this->course)
        );
    }

    /**
     * The callback type-hints `User $user`, so Gate::canBeCalledWithUser()
     * skips it entirely for a null user — the behavioural twin of the
     * signature assertion in Layer 2.
     */
    public function test_a_guest_does_not_reach_the_before_callback(): void
    {
        // `view` on a PUBLISHED course allows guests via CoursePolicy's ?User
        // hint; `manage` does not. Neither may be decided by the admin bypass.
        $published = $this->makeCourseFor($this->owner, ['is_published' => true]);

        $this->assertTrue(Gate::forUser(null)->allows('view', $published));
        $this->assertFalse(Gate::forUser(null)->allows('manage', $published));
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ **ZERO. NOT "ZERO EXCEPT ONE". THE EXCEPTION LIST IS GONE, NOT      ║
     * ║ EMPTIED — AND THAT DELETION IS WHAT CLOSES OPEN DECISION 8.4.**      ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ┌── WHAT THIS TEST USED TO SAY, AND WHY THE OLD SHAPE HAD TO GO. ──────┐
     * │ Through Stage 8 the assertion read                                    │
     * │                                                                       │
     * │     assertSame(['ContentItemPolicy.php'], $offenders, …)              │
     * │                                                                       │
     * │ because ContentItemPolicy::downloadFile() was still a LogicException  │
     * │ and its route (freeze-table row 73) was registered nowhere. The count  │
     * │ was PINNED at one so the test would fail both if a NEW placeholder    │
     * │ appeared and when the files stage finally removed the last one.       │
     * │                                                                       │
     * │ Stage 9 built the body AND the route, so the pin is spent. It is      │
     * │ REMOVED rather than rewritten to `assertSame([], …)` with an empty    │
     * │ literal left in place: an empty-but-present exception list is dead    │
     * │ scaffolding, and dead scaffolding gets REUSED. The next engineer who  │
     * │ wants to ship a placeholder finds no slot to drop a filename into and │
     * │ has to add the list back — a diff a reviewer can see and ask about,   │
     * │ which is the whole point. There is now NO WAY to name an exception    │
     * │ here at all.                                                          │
     * │                                                                       │
     * │ ORDER MATTERED WHEN THIS LANDED: body first, route second, this test  │
     * │ third. Tightening it before the body existed would have failed        │
     * │ spuriously; doing it after the integration tests risked forgetting it │
     * │ altogether, since it is the stated purpose of the stage.              │
     * └───────────────────────────────────────────────────────────────────────┘
     *
     * The claim is at METHOD granularity, not file granularity as before, so
     * the failure message names the ability rather than just the class.
     */
    public function test_layer3_not_one_policy_placeholder_throw_remains(): void
    {
        $this->assertSame(
            [],
            $this->policyMethodsThrowingPlaceholder(),
            'A policy ability whose body is a placeholder throw answers a LIVE REQUEST with a 500 '
            . 'instead of an authorization decision, and escapes Gate::allows() rather than returning '
            . 'false. There is deliberately no exception list on this assertion: if an ability genuinely '
            . 'cannot be implemented yet, do not register a route for it, and record the deferral in '
            . 'docs/open-decisions.md — the way decision 8.4 did, before Stage 9 discharged it.'
        );

        $this->assertFileDoesNotExist(
            app_path('Policies/NotificationPolicy.php'),
            'Decision 3.3: mark-read is scope-based. A NotificationPolicy would let Gate::before hand an '
            . "admin another user's inbox row — something backend/ never permitted."
        );
    }

    /**
     * ┌── THE NEGATIVE CONTROL. WITHOUT IT THE ASSERTION ABOVE IS A GUESS. ──┐
     * │ `assertSame([], …)` passes just as happily when the scanner is       │
     * │ broken — a bad glob, a renamed directory, a regex that matches       │
     * │ nothing — as when the codebase is genuinely clean. Those two states  │
     * │ are indistinguishable from the green tick, and the second one is a   │
     * │ control that has silently disappeared.                               │
     * │                                                                      │
     * │ So a placeholder is REINTRODUCED, on purpose, into a throwaway       │
     * │ directory shaped like app/Policies, and the scanner is required to   │
     * │ find it and to NAME it. This is the same reasoning as the            │
     * │ `assertGreaterThanOrEqual(20, $swept)` guard on the sweep above.     │
     * │                                                                      │
     * │ The fixture lives under storage/framework/testing and is removed in  │
     * │ a finally, so a failing assertion cannot leave a fake policy behind. │
     * │ It is never in app/, never autoloaded, and never registered.         │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_layer3_the_placeholder_sweep_actually_detects_a_placeholder(): void
    {
        $directory = storage_path('framework/testing/placeholder-probe-' . bin2hex(random_bytes(4)));

        mkdir($directory, 0755, true);

        try {
            file_put_contents($directory . '/FakeProbePolicy.php', <<<'PHP'
                <?php

                class FakeProbePolicy
                {
                    public function healthy(): bool
                    {
                        return true;
                    }

                    public function deferredAbility(): bool
                    {
                        throw new \LogicException('FakeProbePolicy::deferredAbility — a later stage');
                    }
                }
                PHP);

            $this->assertSame(
                ['FakeProbePolicy::deferredAbility'],
                $this->policyMethodsThrowingPlaceholder($directory),
                'The placeholder scanner failed to see a placeholder that is definitely there. Until '
                . 'this passes, the zero-placeholders assertion above proves nothing.'
            );
        } finally {
            array_map('unlink', glob($directory . '/*') ?: []);
            rmdir($directory);
        }
    }

    /**
     * Every policy ability whose body throws a LogicException placeholder,
     * as "ClassName::method", sorted.
     *
     * Source scanning rather than reflection ON PURPOSE: invoking a method to
     * see whether it throws means CONSTRUCTING its arguments, which for a
     * policy means models, which for some policies means database rows — and
     * an ability that threw for an unrelated reason would be indistinguishable
     * from a placeholder. Reading the source has no such ambiguity, costs
     * nothing, and cannot be defeated by a method that happens not to be
     * reachable with the arguments a test can build.
     *
     * @param  string|null  $directory  test-only override; defaults to app/Policies
     * @return list<string>
     */
    private function policyMethodsThrowingPlaceholder(?string $directory = null): array
    {
        $offenders = [];

        foreach (glob(($directory ?? app_path('Policies')) . '/*.php') ?: [] as $file) {
            $class = basename($file, '.php');
            $method = null;

            foreach (file($file) ?: [] as $line) {
                if (preg_match('/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $matches) === 1) {
                    $method = $matches[1];
                }

                if (preg_match('/throw\s+new\s+\\\\?LogicException/', $line) === 1) {
                    $offenders[] = $class . '::' . ($method ?? '(file scope)');
                }
            }
        }

        sort($offenders);

        return $offenders;
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * The Gate's own `$beforeCallbacks`, read back out by reflection.
     *
     * There is no public accessor — `Gate::before()` only appends — so
     * reflection is the only way to assert on the closure that is ACTUALLY
     * registered rather than on a copy re-typed into the test, which would
     * prove nothing about the running application.
     *
     * @return list<\Closure>
     */
    private function registeredBeforeCallbacks(): array
    {
        $gate = Gate::getFacadeRoot();

        $property = (new ReflectionClass($gate))->getProperty('beforeCallbacks');
        $property->setAccessible(true);

        /** @var list<\Closure> $callbacks */
        $callbacks = array_values((array) $property->getValue($gate));

        return $callbacks;
    }

    /**
     * Every public, non-static ability on a policy class: declared by the class
     * itself, not the constructor, and taking a User (or ?User) first.
     *
     * The User-first filter is what separates an ABILITY from a helper a policy
     * happens to expose — Gate only ever calls the former.
     *
     * @return list<string>
     */
    private function abilitiesOf(string $policy): array
    {
        $abilities = [];

        foreach ((new ReflectionClass($policy))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()
                || $method->isConstructor()
                || $method->getDeclaringClass()->getName() !== $policy) {
                continue;
            }

            $first = $method->getParameters()[0] ?? null;
            $type = $first?->getType();

            if (!$type instanceof ReflectionNamedType || $type->getName() !== User::class) {
                continue;
            }

            $abilities[] = $method->getName();
        }

        return $abilities;
    }

    /**
     * Arguments for Gate::allows(), derived from the ability's own signature —
     * the model instance first, then whatever extra parameters it declares
     * (ExamPolicy::viewResultsOf takes a target student id, for instance).
     *
     * Unsaved instances throughout: a correct bypass never touches them.
     *
     * @return list<mixed>
     */
    private function argumentsFor(string $policy, string $ability, string $model): array
    {
        $arguments = [new $model()];

        foreach (\array_slice((new ReflectionMethod($policy, $ability))->getParameters(), 2) as $parameter) {
            $arguments[] = $this->placeholderFor($parameter);
        }

        return $arguments;
    }

    private function placeholderFor(ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            return null;
        }

        return match ($type->getName()) {
            'string' => 'a-placeholder-id',
            'int' => 0,
            'bool' => false,
            default => $type->isBuiltin() ? null : new ($type->getName())(),
        };
    }
}
