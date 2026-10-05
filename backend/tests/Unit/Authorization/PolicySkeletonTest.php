<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Models\Announcement;
use App\Models\Concerns\PlaceholderModel;
use App\Models\ContentItem;
use App\Models\CourseGroup;
use App\Models\Exam;
use App\Models\Notification;
use App\Models\BroadcastNotification;
use App\Models\QuestionGroup;
use App\Models\Section;
use App\Models\User;
use App\Policies\AnnouncementPolicy;
use App\Policies\Concerns\ResolvesCourseOwnership;
use App\Policies\ContentItemPolicy;
use App\Policies\CourseGroupPolicy;
use App\Policies\ExamPolicy;
use App\Policies\QuestionGroupPolicy;
use App\Policies\SectionPolicy;
use Illuminate\Auth\Access\Response as AccessResponse;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The eight STRUCTURAL policies: signatures now, bodies later.
 *
 * ┌── WHAT THIS FILE IS ACTUALLY FOR ────────────────────────────────────────┐
 * │ It is a HAND-OFF CONTRACT, not a behaviour test. It pins:               │
 * │                                                                          │
 * │  1. every ability's exact name and parameter list, so the later stage    │
 * │     implements the question that was analysed, not a re-guessed one;     │
 * │  2. that every body still THROWS — so a stage cannot half-implement a    │
 * │     policy and leave a sibling ability silently returning a stub value;  │
 * │  3. which models are still placeholders. Finishing a model means         │
 * │     dropping App\Models\Concerns\PlaceholderModel, which fails the list  │
 * │     below and forces whoever did it to come back here and remove the     │
 * │     matching skeleton expectation too.                                   │
 * │                                                                          │
 * │ Every assertion here is EXPECTED to be deleted as its stage lands. A     │
 * │ failure in this file is usually the suite telling you to finish the      │
 * │ hand-off, not that something broke.                                      │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class PolicySkeletonTest extends TestCase
{
    /**
     * policy class => [ability => [parameter type, …] after the User.
     *
     * Derived from the backend/ line ranges named in each policy's docblock.
     */
    private const CONTRACT = [
        /*
         * SectionPolicy and CourseGroupPolicy WERE HERE AND WERE RETIRED IN
         * STAGE 5 / SESSION A. Their models graduated from PlaceholderModel and
         * their bodies stopped throwing, so leaving them in CONTRACT would fail
         * test_every_skeleton_body_still_throws() — which is precisely the
         * hand-off tripwire working. Their real behaviour is now covered by
         * tests/Feature/Course/SectionCrudTest.php and CourseGroupCrudTest.php
         * over HTTP, and by CourseIsolationMatrixTest through the Gate.
         */
        /*
         * ContentItemPolicy LEFT CONTRACT IN STAGE 5 / SESSION B, because
         * manage() stopped throwing. CONTRACT is all-or-nothing per policy —
         * test_every_skeleton_body_still_throws() iterates the same list as
         * the signature test — so its three DEFERRED abilities are pinned
         * separately by test_the_deferred_content_item_abilities_still_throw()
         * below, which also keeps their signatures under guard.
         */
        /*
         * ExamPolicy and QuestionGroupPolicy LEFT CONTRACT
         * IN STAGE 6 / SESSION A. CONTRACT is all-or-nothing per policy — the
         * throw-assertion iterates the same list as the signature test — and
         * both now have at least one REAL body:
         *
         *   ExamPolicy         view, viewEnrolledDetail, viewStaffDetail,
         *                      viewQuestions   (three still deferred)
         *   QuestionGroupPolicy  manage, view  (nothing deferred at all)
         *
         * Their deferred halves are pinned separately by
         * DEFERRED_ASSESSMENT_ABILITIES + test_the_deferred_session_b_
         * abilities_still_throw() below, exactly as ContentItemPolicy's were
         * when manage() landed in Stage 5 / Session B. Their real behaviour is
         * covered over HTTP by tests/Feature/Assessment/*.
         */
        /*
         * ╔══════════════════════════════════════════════════════════════════╗
         * ║ CONTRACT IS **EMPTY** AS OF STAGE 8. THE HAND-OFF IS COMPLETE.   ║
         * ║                                                                  ║
         * ║ AnnouncementPolicy::manage() LEFT IN STAGE 8 — it has a real     ║
         * ║ body now, pinned by test_the_stage_8_announcement_ability_no_    ║
         * ║ longer_throws() below and exercised over HTTP by                 ║
         * ║ tests/Feature/Notification/AnnouncementCrudTest.php.             ║
         * ║                                                                  ║
         * ║ NotificationPolicy::update() LEFT BY BEING **DELETED, UNBUILT**. ║
         * ║ Approved decision 3.3: mark-read is SCOPE-based. The class file   ║
         * ║ is gone, it is no longer in AuthServiceProvider's map, and        ║
         * ║ test_the_notification_policy_was_deliberately_not_built() below   ║
         * ║ is what stops it being re-added by reflex. Routing an inbox row   ║
         * ║ through the Gate would let `Gate::before` hand an ADMIN another   ║
         * ║ user's notification — strictly worse than backend/'s inline      ║
         * ║ comparison, which is the whole argument.                         ║
         * ║                                                                  ║
         * ║ The array is KEPT rather than deleted so the next stage that      ║
         * ║ hands a policy forward finds the mechanism and its rationale      ║
         * ║ instead of reinventing either — the same reason                   ║
         * ║ DEFERRED_ASSESSMENT_ABILITIES stayed after Stage 6.               ║
         * ╚══════════════════════════════════════════════════════════════════╝
         *
         * @var array<class-string, array<string, list<string>>>
         */
    ];

    /**
     * ContentItemPolicy's abilities that are STILL deferred, with the exact
     * signatures Session C / Stage 6 must implement.
     *
     * Kept out of CONTRACT because manage() — the fourth ability on the same
     * class — is real now, and CONTRACT's throw-assertion is per-policy.
     */
    private const DEFERRED_CONTENT_ITEM_ABILITIES = [
        /*
         * ╔══════════════════════════════════════════════════════════════════╗
         * ║ **EMPTY AS OF STAGE 9. ContentItemPolicy IS COMPLETE.**           ║
         * ╚══════════════════════════════════════════════════════════════════╝
         *
         * view() AND watch() LEFT THIS LIST IN STAGE 5 / SESSION C. They back
         * the three public content-item reads and the /lessons family
         * respectively — proven over HTTP by
         * tests/Feature/ContentItem/PublicContentReadTest.php,
         * PublicPayloadLeakTest.php and tests/Feature/Lesson/*.
         *
         * downloadFile() LEFT IN STAGE 9, WHICH BUILT BOTH ITS BODY AND ITS
         * ROUTE (freeze-table row 73, GET /content-items/{id}/file). That was
         * the whole of open decision 8.4, and it is closed: the application
         * now contains no policy ability whose body is a placeholder throw,
         * asserted absolutely and with no exception list at
         * GateBeforeTest::test_layer3_not_one_policy_placeholder_throw_remains.
         *
         * The array is KEPT rather than deleted, for the same reason
         * PLACEHOLDERS above was kept when Stage 8 emptied it: the three tests
         * wired to it stay live for the next stage that needs to defer an
         * ability, and its emptiness is ASSERTED rather than implied.
         */
    ];

    /**
     * The two abilities Session C graduated, with the signatures it had to
     * honour. Kept as a list so the pair below can prove they are BOTH gone
     * from the deferred set — a half-migration would otherwise pass silently.
     */
    private const GRADUATED_IN_SESSION_C = ['view', 'watch'];

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ EMPTY SINCE STAGE 6 / SESSION B. THE ASSESSMENT SURFACE HAS NO       ║
     * ║ DEFERRED ABILITY LEFT — AND THE ARRAY IS KEPT, NOT DELETED, SO THE   ║
     * ║ THREE TESTS BELOW STAY WIRED FOR THE NEXT STAGE THAT NEEDS THEM.     ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * It contained these entries after Session A:
     *
     *   ExamPolicy::viewAnswerKey     ✅ implemented in 6B
     *   ExamPolicy::viewResultsOf     ✅ implemented in 6B
     *   ExamPolicy::submit            ⛔ **DELETED** in 6B
     *
     * ┌── THE DELETION WAS THE POINT OF OPEN DECISION 6.7. ──────────────────┐
     * │ Session A listed it "under protest" and explicitly authorised         │
     * │ Session B to remove it. It did: the "must be a real `students`      │
     * │ row" rejection cannot live in a policy body (`Gate::before` waves an  │
     * │ admin past it) and the attempt cap must be evaluated INSIDE the       │
     * │ locked transaction that performs the INSERT, which a policy is not.   │
     * │ Nothing worth keeping remained. See ExamPolicy's headstone comment    │
     * │ and test_the_submit_ability_is_gone() below, which is what            │
     * │ stops it being re-added by reflex.                                    │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * @var array<class-string, array<string, list<string>>>
     */
    private const DEFERRED_ASSESSMENT_ABILITIES = [];

    /**
     * The abilities Session B graduated, plus the one it deleted, kept as
     * explicit lists so that a HALF-migration cannot pass silently.
     */
    private const IMPLEMENTED_IN_STAGE_6B = [
        ExamPolicy::class => [
            'viewAnswerKey' => [User::class, Exam::class],
            'viewResultsOf' => [User::class, Exam::class, 'string'],
        ],
    ];

    /** @var array<class-string, string> */
    private const DELETED_IN_STAGE_6B = [
        ExamPolicy::class => 'submit',
    ];

    /**
     * The abilities STAGE 6 / SESSION A DID build, with the signatures it had
     * to honour. Asserted as NOT-throwing, so a revert to a skeleton fails.
     *
     * `?User` on the two view() methods is LOAD-BEARING and is asserted here
     * rather than assumed: Gate::methodAllowsGuests() consults exactly that
     * nullability to decide whether a logged-out caller reaches the policy, and
     * without it every exam is a 404 for every guest — open
     * decision 3.7's failure mode one layer down, which fails CLOSED and
     * therefore silently. Note this WIDENS the Stage 3 skeleton's `User`; that
     * signature described requireExamAccess(), which backs row 90, not rows
     * 88/89.
     */
    private const IMPLEMENTED_IN_STAGE_6A = [
        ExamPolicy::class => [
            'view' => ['?' . User::class, Exam::class],
            'viewEnrolledDetail' => [User::class, Exam::class],
            'viewStaffDetail' => [User::class, Exam::class],
            'viewQuestions' => [User::class, Exam::class],
        ],
        QuestionGroupPolicy::class => [
            'manage' => [User::class, QuestionGroup::class],
            'view' => [User::class, QuestionGroup::class],
        ],
    ];

    /** The models that are STILL stand-ins. Shrink this list as stages land. */
    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ **EMPTY AS OF STAGE 8.** NO PLACEHOLDER MODEL REMAINS IN app/Models. ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * Section and CourseGroup left in Stage 5 / Session A; ContentItem in
     * Session B; Exam and QuestionGroup in Stage 6 / Session A —
     * which is also what retired open decision 5.11's
     * ALLOWED_PLACEHOLDER_METHODS entries below. Announcement and Notification
     * left in Stage 8, and BroadcastNotification was built outright, never
     * having been stubbed.
     *
     * The list stays wired (the loops below iterate it, and
     * test_no_placeholder_model_remains() asserts it is empty) so the
     * mechanism survives for a future stage that needs a forward reference.
     *
     * @var list<class-string>
     */
    private const PLACEHOLDERS = [];

    // ── SIGNATURES ────────────────────────────────────────────────────────

    public function test_every_declared_ability_exists_with_the_analysed_signature(): void
    {
        // CONTRACT emptied in Stage 8. Asserted, not merely iterated: an empty
        // foreach performs no assertions, and PHPUnit would mark this "risky"
        // rather than failing — the quietest way for a hand-off tripwire to die.
        $this->assertSame([], self::CONTRACT, 'Every skeleton graduated in Stage 8.');

        foreach (self::CONTRACT as $policy => $abilities) {            foreach ($abilities as $ability => $expected) {
                $this->assertTrue(
                    method_exists($policy, $ability),
                    "{$policy}::{$ability}() is missing."
                );

                $method = new ReflectionMethod($policy, $ability);

                $this->assertTrue($method->isPublic(), "{$policy}::{$ability}() must be public.");
                $this->assertSame(
                    'bool',
                    (string) $method->getReturnType(),
                    "{$policy}::{$ability}() must return bool."
                );

                $actual = array_map(
                    static fn (\ReflectionParameter $p): string => (string) $p->getType(),
                    $method->getParameters()
                );

                $this->assertSame(
                    $expected,
                    $actual,
                    "{$policy}::{$ability}() has the wrong parameter list."
                );
            }
        }
    }

    /**
     * NO NULLABLE RESOURCE PARAMETERS. A policy handed `null` for the thing it
     * is ruling on has no question to answer, and the implicit "…then allow"
     * that tends to follow is exactly the fallthrough this stage forbids. The
     * ONE nullable is a first-parameter `?User` on a guest-reachable read.
     */
    public function test_no_ability_accepts_a_nullable_resource(): void
    {
        // CONTRACT emptied in Stage 8. Asserted, not merely iterated: an empty
        // foreach performs no assertions, and PHPUnit would mark this "risky"
        // rather than failing — the quietest way for a hand-off tripwire to die.
        $this->assertSame([], self::CONTRACT, 'Every skeleton graduated in Stage 8.');

        foreach (self::CONTRACT as $policy => $abilities) {            foreach (array_keys($abilities) as $ability) {
                foreach ((new ReflectionMethod($policy, $ability))->getParameters() as $i => $parameter) {
                    if ($i === 0) {
                        continue; // the actor; ?User is legitimate for guest reads
                    }

                    $this->assertFalse(
                        $parameter->getType()?->allowsNull() ?? true,
                        "{$policy}::{$ability}() parameter \${$parameter->getName()} must not be nullable."
                    );
                    $this->assertFalse(
                        $parameter->isDefaultValueAvailable(),
                        "{$policy}::{$ability}() parameter \${$parameter->getName()} must not have a default."
                    );
                }
            }
        }
    }

    /**
     * …and the same guarantee for any deferred assessment ability.
     *
     * THE LIST IS EMPTY SINCE STAGE 6 / SESSION B — the assertion below states
     * that as a FACT rather than letting the loop pass vacuously, so that
     * "nothing is deferred" is something the suite claims out loud and a
     * re-deferral has to change this file.
     */
    public function test_every_deferred_assessment_ability_keeps_its_analysed_signature(): void
    {
        $this->assertSame(
            [],
            self::DEFERRED_ASSESSMENT_ABILITIES,
            'Stage 6 is complete: every assessment ability is implemented or deliberately deleted.'
        );

        foreach (self::DEFERRED_ASSESSMENT_ABILITIES as $policy => $abilities) {
            foreach ($abilities as $ability => $expected) {
                $method = new ReflectionMethod($policy, $ability);

                $this->assertSame(
                    $expected,
                    array_map(
                        static fn (\ReflectionParameter $p): string => (string) $p->getType(),
                        $method->getParameters()
                    ),
                    "{$policy}::{$ability}() has the wrong parameter list."
                );
            }
        }
    }

    /** viewResultsOf's third parameter is what stops student-id swapping. */
    public function test_view_results_of_takes_a_target_student_id(): void
    {
        $method = new ReflectionMethod(ExamPolicy::class, 'viewResultsOf');

        $this->assertCount(3, $method->getParameters());
        $this->assertSame('targetStudentId', $method->getParameters()[2]->getName());
        $this->assertSame('string', (string) $method->getParameters()[2]->getType());
    }

    // ── BODIES ────────────────────────────────────────────────────────────

    /**
     * Every body throws, and the message names the OWNING STAGE — so a 500 in
     * a later stage says which backlog item is missing rather than "something
     * went wrong".
     */
    public function test_every_skeleton_body_still_throws(): void
    {
        // CONTRACT emptied in Stage 8. Asserted, not merely iterated: an empty
        // foreach performs no assertions, and PHPUnit would mark this "risky"
        // rather than failing — the quietest way for a hand-off tripwire to die.
        $this->assertSame([], self::CONTRACT, 'Every skeleton graduated in Stage 8.');

        foreach (self::CONTRACT as $policy => $abilities) {            foreach (array_keys($abilities) as $ability) {
                $args = $this->argumentsFor($policy, $ability);

                try {
                    (new $policy())->{$ability}(...$args);
                    $this->fail("{$policy}::{$ability}() returned instead of throwing.");
                } catch (LogicException $e) {
                    $this->assertStringContainsString(
                        "{$this->shortName($policy)}::{$ability}",
                        $e->getMessage()
                    );
                    $this->assertMatchesRegularExpression(
                        '/Stage \d/',
                        $e->getMessage(),
                        "{$policy}::{$ability}() must name its owning stage."
                    );
                }
            }
        }
    }

    /**
     * THE SESSION B HAND-OFF. Every deferred assessment ability still throws,
     * and its message names the owning SESSION, not just the stage — so a 500
     * in Session B says which piece of work is missing.
     */
    public function test_the_deferred_session_b_abilities_still_throw(): void
    {
        // NOTHING IS OWED ANY MORE — Session B landed. Kept wired (rather than
        // deleted) because the mechanism is the standing hand-off pattern
        // between sessions and the next split stage will refill the list.
        $this->assertSame([], self::DEFERRED_ASSESSMENT_ABILITIES);

        foreach (self::DEFERRED_ASSESSMENT_ABILITIES as $policy => $abilities) {
            foreach (array_keys($abilities) as $ability) {
                try {
                    (new $policy())->{$ability}(...$this->argumentsFor($policy, $ability));
                    $this->fail("{$policy}::{$ability}() returned instead of throwing.");
                } catch (LogicException $e) {
                    $this->assertStringContainsString(
                        "{$this->shortName($policy)}::{$ability}",
                        $e->getMessage()
                    );
                    $this->assertStringContainsString(
                        'Session B',
                        $e->getMessage(),
                        "{$policy}::{$ability}() must name the owning SESSION, not only the stage — "
                        . 'Stage 6 is split in two and "Stage 6" alone does not say which half owes it.'
                    );
                }
            }
        }
    }

    /**
     * ┌── AND THE ONES SESSION A **DID** BUILD ARE GENUINELY NOT SKELETONS. ─┐
     * │ Without this, the test above would keep passing if someone reverted  │
     * │ view() to a stub — and a LogicException escaping Gate::allows() is a │
     * │ 500, not a refusal, i.e. an outage of the public course page.        │
     * │                                                                      │
     * │ Source inspection rather than invocation, for the reason             │
     * │ ContentItemPolicy's twin gives: this file boots no Laravel           │
     * │ application, and every implemented body dereferences a relation.     │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_the_stage_6a_abilities_no_longer_throw(): void
    {
        foreach (self::IMPLEMENTED_IN_STAGE_6A as $policy => $abilities) {
            foreach ($abilities as $ability => $expected) {
                $this->assertArrayNotHasKey(
                    $ability,
                    self::DEFERRED_ASSESSMENT_ABILITIES[$policy] ?? [],
                    "{$policy}::{$ability}() was implemented in Stage 6 / Session A."
                );

                $method = new ReflectionMethod($policy, $ability);

                $this->assertSame(
                    $expected,
                    array_map(
                        static fn (\ReflectionParameter $p): string => (string) $p->getType(),
                        $method->getParameters()
                    ),
                    "{$policy}::{$ability}() has the wrong parameter list."
                );

                $file = file($method->getFileName() ?: '') ?: [];
                $body = implode('', \array_slice(
                    $file,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1
                ));

                $this->assertStringNotContainsString(
                    'LogicException',
                    $body,
                    "{$policy}::{$ability}() must not go back to being a skeleton — live routes "
                    . 'depend on it, and a throw would be a 500 rather than a refusal.'
                );
            }
        }
    }

    /**
     * ┌── AND THE FOUR SESSION **B** ABILITIES ARE REAL TOO. ────────────────┐
     * │ Same source-inspection technique, same reason: this file boots no    │
     * │ application and every implemented body dereferences a relation.      │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_the_stage_6b_abilities_no_longer_throw(): void
    {
        foreach (self::IMPLEMENTED_IN_STAGE_6B as $policy => $abilities) {
            foreach ($abilities as $ability => $expected) {
                $this->assertArrayNotHasKey(
                    $ability,
                    self::DEFERRED_ASSESSMENT_ABILITIES[$policy] ?? [],
                    "{$policy}::{$ability}() was implemented in Stage 6 / Session B."
                );

                $method = new ReflectionMethod($policy, $ability);

                $this->assertSame(
                    $expected,
                    array_map(
                        static fn (\ReflectionParameter $p): string => (string) $p->getType(),
                        $method->getParameters()
                    ),
                    "{$policy}::{$ability}() has the wrong parameter list."
                );

                $file = file($method->getFileName() ?: '') ?: [];
                $body = implode('', array_slice(
                    $file,
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1
                ));

                $this->assertStringNotContainsString(
                    'LogicException',
                    $body,
                    "{$policy}::{$ability}() must not go back to being a skeleton."
                );
            }
        }
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ `submit` MUST NOT EXIST ON EITHER POLICY. THIS TEST IS THE FENCE     ║
     * ║ AROUND OPEN DECISION 6.7's RESOLUTION.                               ║
     * ║                                                                      ║
     * ║ Re-adding the ability is the natural reflex of anybody who sees a    ║
     * ║ submit ROUTE with no submit ABILITY, and it is exactly the wrong     ║
     * ║ move: a policy body cannot see an admin (`Gate::before` returned     ║
     * ║ true before it), and it cannot evaluate the attempt cap (that must   ║
     * ║ happen inside the locked transaction performing the INSERT). A       ║
     * ║ `submit` ability could therefore only ever answer a DIFFERENT        ║
     * ║ question under a misleading name — and the next reader would trust   ║
     * ║ it as the gate and stop looking for the two checks that actually     ║
     * ║ hold.                                                                ║
     * ║                                                                      ║
     * ║ If this test ever has to be deleted, the deletion must be accompanied ║
     * ║ by moving the `students`-row check OUT of the two Actions — which is  ║
     * ║ a thing that cannot be done correctly, which is the whole point.      ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_submit_ability_is_gone(): void
    {
        foreach (self::DELETED_IN_STAGE_6B as $policy => $ability) {
            $this->assertFalse(
                method_exists($policy, $ability),
                "{$policy}::{$ability}() was deleted in Stage 6 / Session B (open decision 6.7) and "
                . 'must not come back. The student check lives in the Action that performs the write, '
                . 'because that is the only layer Gate::before cannot bypass.'
            );
        }
    }

    /**
     * …and the refusal it was replaced by is genuinely in the WRITE layer.
     *
     * Source inspection, deliberately: asserting the 403 over HTTP is done by
     * tests/Feature/Assessment/AssessmentSubmitTest, but that would still pass
     * if somebody moved the check into a policy and the request happened to be
     * a student's. THIS asserts the PLACEMENT, which is the property open
     * decisions 3.1 and 6.7 are actually about.
     */
    public function test_the_student_check_lives_in_the_write_action(): void
    {
        foreach ([
            \App\Actions\Exam\SubmitExamAttempt::class,
        ] as $action) {
            $source = (string) file_get_contents((new \ReflectionClass($action))->getFileName() ?: '');

            $this->assertStringContainsString(
                'Student::query()->whereKey(',
                $source,
                "{$action} must check the `students` row itself — it is the one layer Gate::before "
                . 'does not cover, and the FK it protects is students.user_id.'
            );
        }
    }

    // ── TRAIT USE ─────────────────────────────────────────────────────────

    /** The six course-routed policies share the one engine. */
    public function test_the_course_routed_policies_use_the_shared_engine(): void
    {
        foreach ([
            SectionPolicy::class,
            CourseGroupPolicy::class,
            ContentItemPolicy::class,
            ExamPolicy::class,
        ] as $policy) {
            $this->assertContains(
                ResolvesCourseOwnership::class,
                class_uses($policy),
                "{$policy} must use the shared ownership engine."
            );
        }
    }

    /**
     * …and the two DIRECT-owner policies deliberately do NOT. QuestionGroup
     * owns via question_groups.teacher_id and Announcement via
     * announcements.teacher_id; neither has a Course in its chain, and a trait
     * is opt-in precisely so they are not forced to carry one.
     *
     * ┌── THE SECOND ENTRY CHANGED IN STAGE 8, AND THE REASON IS THE POINT. ─┐
     * │ It used to be NotificationPolicy. That class was deleted unbuilt      │
     * │ (decision 3.3), and AnnouncementPolicy took its place here — which is │
     * │ not a substitution of convenience: `announcements.course_id` EXISTS   │
     * │ and is nullable, so this is the first policy that could plausibly     │
     * │ have used the engine and deliberately does not. course_id scopes the  │
     * │ AUDIENCE; `teacher_id` is the owner. Wiring the engine in would make  │
     * │ a course-scoped announcement change hands whenever its course did.    │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_the_course_less_policies_do_not_use_the_engine(): void
    {
        foreach ([QuestionGroupPolicy::class, AnnouncementPolicy::class] as $policy) {
            $this->assertNotContains(
                ResolvesCourseOwnership::class,
                class_uses($policy),
                "{$policy} has no Course in its ownership chain and must not use the engine."
            );
        }
    }

    // ── PLACEHOLDER BOOKKEEPING ───────────────────────────────────────────

    /**
     * The hand-off tripwire. When a stage builds the real model it removes the
     * PlaceholderModel marker; this assertion then fails and sends whoever did
     * it back here to retire the matching skeleton expectations too.
     */
    public function test_the_placeholder_model_list_is_exactly_what_is_still_stubbed(): void
    {
        foreach (self::PLACEHOLDERS as $model) {
            $this->assertContains(
                PlaceholderModel::class,
                class_implements($model),
                "{$model} is listed as a placeholder but no longer carries the marker — "
                . 'if its real model has landed, retire its skeleton policy expectations here too.'
            );
        }

        foreach ([
            \App\Models\Course::class,
            \App\Models\Subscription::class,
            User::class,
            // ── graduated in Stage 5 / Session A ──────────────────────────
            Section::class,
            CourseGroup::class,
            // ── graduated in Stage 5 / Session B ──────────────────────────
            ContentItem::class,
            // ── graduated in Stage 6 / Session A ──────────────────────────
            Exam::class,
            QuestionGroup::class,
            \App\Models\Question::class,
            // ── graduated in Stage 8 ──────────────────────────────────────
            Announcement::class,
            Notification::class,
            // …and one that was never a placeholder at all: Stage 8 built it
            // outright, because nothing before Stage 8 wrote a broadcast row.
            BroadcastNotification::class,
        ] as $real) {
            $this->assertNotContains(
                PlaceholderModel::class,
                class_implements($real),
                "{$real} is a REAL model and must never carry the placeholder marker."
            );
        }
    }

    /** A placeholder must stay thin: no relationships, no scopes, no rules. */
    public function test_placeholder_models_declare_no_behaviour(): void
    {
        // PLACEHOLDERS emptied in Stage 8 — asserted for the same reason as
        // CONTRACT above: an empty loop is a silently dead tripwire.
        $this->assertSame([], self::PLACEHOLDERS, 'No placeholder model remains.');

        foreach (self::PLACEHOLDERS as $model) {
            $own = array_filter(
                (new \ReflectionClass($model))->getMethods(),
                static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $model
            );

            $names = array_map(static fn (ReflectionMethod $m): string => $m->getName(), $own);
            sort($names);

            $this->assertEmpty(
                array_diff($names, self::ALLOWED_PLACEHOLDER_METHODS[$model] ?? ['casts']),
                "{$model} is a placeholder and must declare nothing but \$table/\$timestamps/casts(); found: "
                . implode(', ', $names)
            );
        }
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE ONE RECORDED EXEMPTION TO "A PLACEHOLDER DECLARES NO BEHAVIOUR"  ║
     * ║ IS **RETIRED**, AS OPEN DECISION 5.11 REQUIRED.                      ║
     * ║                                                                      ║
     * ║ Stage 5 / Session B was allowed to declare contentItem() on Exam and  ║
     * ║ and nothing else — because it had to WRITE those tables               ║
     * ║ three stages early. 5.11 said: "عند بناء النموذجين حقيقةً، تُحذف هذه  ║
     * ║ القائمة مع بقية بنود التسليم." Stage 6 / Session A built them, so the ║
     * ║ allow-list is empty and both models have left PLACEHOLDERS above.     ║
     * ║                                                                      ║
     * ║ The constant itself stays (the ?? fallback in the assertion below     ║
     * ║ reads it) so the NEXT stage that needs a narrow, recorded exemption   ║
     * ║ finds the mechanism and its argument rather than reinventing either.  ║
     * ║                                                                      ║
     * ║ NOTE what did NOT change with graduation: Exam still                  ║
     * ║ has $fillable. App\Actions\ContentItem\PersistExamDetail still writes ║
     * ║ every column with setAttribute, precisely so no request key can reach ║
     * ║ a column nobody has audited.                                          ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    private const ALLOWED_PLACEHOLDER_METHODS = [];

    // ── THE ABILITIES STAGE 5 / SESSION B DELIBERATELY DID NOT BUILD ──────

    /**
     * ContentItemPolicy::manage() is real; its other three still throw.
     *
     * This is the same hand-off tripwire CONTRACT provides, applied to a
     * POLICY THAT IS HALF DONE — the case the original all-or-nothing list
     * cannot express. Session B's six routes all ask manage() and none of them
     * asks these three, so a "conservative" stub body here would be an
     * unexercised guess that Session C would have to notice was wrong before
     * replacing it. A LogicException naming the owning session is louder.
     *
     * When Session C implements them, this method is what fails and sends
     * whoever did it back to delete the matching entry.
     */
    public function test_the_deferred_content_item_abilities_still_throw(): void
    {
        /*
         * ╔══════════════════════════════════════════════════════════════════╗
         * ║ THE LIST IS EMPTY AS OF STAGE 9, AND THE LOOP BELOW THEREFORE    ║
         * ║ RUNS ZERO TIMES — WHICH IS WHY THE EMPTINESS IS ASSERTED FIRST.  ║
         * ║                                                                  ║
         * ║ A loop over an empty array is a test that proves nothing while   ║
         * ║ reporting success. The assertion above it is the real claim:     ║
         * ║ ContentItemPolicy has NO deferred ability left. The mechanism    ║
         * ║ stays wired for whichever stage next needs to defer one.         ║
         * ╚══════════════════════════════════════════════════════════════════╝
         */
        $this->assertSame(
            [],
            self::DEFERRED_CONTENT_ITEM_ABILITIES,
            'Stage 5 / Session C graduated view() and watch(); Stage 9 graduated downloadFile() '
            . '(freeze-table row 73) and, with it, closed open decision 8.4 — the last placeholder '
            . 'throw in the application.'
        );

        foreach (self::DEFERRED_CONTENT_ITEM_ABILITIES as $ability => $expected) {
            $method = new ReflectionMethod(ContentItemPolicy::class, $ability);

            $this->assertSame(
                $expected,
                array_map(
                    static fn (\ReflectionParameter $p): string => (string) $p->getType(),
                    $method->getParameters()
                ),
                "ContentItemPolicy::{$ability}() has the wrong parameter list."
            );

            try {
                (new ContentItemPolicy())->{$ability}(
                    ...$this->argumentsFor(ContentItemPolicy::class, $ability)
                );
                $this->fail("ContentItemPolicy::{$ability}() returned instead of throwing.");
            } catch (LogicException $e) {
                $this->assertStringContainsString("ContentItemPolicy::{$ability}", $e->getMessage());
                $this->assertMatchesRegularExpression('/Stage \d/', $e->getMessage());
            }
        }
    }

    /**
     * THE STAGE 9 HAND-OFF, ASSERTED THE SAME WAY manage()'s AND SESSION C's
     * WERE — plus the one thing those two did not have to pin: A RETURN TYPE.
     *
     * ┌── downloadFile() RETURNS `Illuminate\Auth\Access\Response`, NOT bool.─┐
     * │ That is load-bearing and is asserted here rather than left to        │
     * │ documentation. The ability owes THREE wire answers (404 'File not    │
     * │ found', 403 'You do not have access to this file', and 200), and a   │
     * │ bool cannot carry the difference between the first two. "Tidying"    │
     * │ the signature back to bool would compile, would keep every           │
     * │ isolation-matrix Gate assertion passing — `Gate::allows()` reads     │
     * │ both forms as booleans — and would silently downgrade the hidden-    │
     * │ item 404 to a 403, i.e. re-open the enumeration oracle the 404 was   │
     * │ chosen to close. This assertion is the tripwire for that.            │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * Source inspection rather than invocation, for the reason every twin of
     * this test gives: this file boots no Laravel application, and the body
     * dereferences a belongsTo relation. Its real behaviour is proven through
     * the Gate and over HTTP in tests/Feature/File/ContentItemDownloadTest and
     * ContentItemDownloadIsolationTest.
     */
    public function test_the_stage_9_download_ability_no_longer_throws(): void
    {
        $this->assertArrayNotHasKey(
            'downloadFile',
            self::DEFERRED_CONTENT_ITEM_ABILITIES,
            'ContentItemPolicy::downloadFile() was implemented in Stage 9 and backs a live route.'
        );

        $method = new ReflectionMethod(ContentItemPolicy::class, 'downloadFile');

        $this->assertSame(
            [User::class, ContentItem::class],
            array_map(
                static fn (\ReflectionParameter $p): string => (string) $p->getType(),
                $method->getParameters()
            ),
            'ContentItemPolicy::downloadFile() has the wrong parameter list. `User` and NOT `?User`: '
            . 'row 73 is not a DEGRADE row and a guest must be stopped at the auth gate with a 401, '
            . 'never reach the policy.'
        );

        $this->assertSame(
            AccessResponse::class,
            (string) $method->getReturnType(),
            'downloadFile() must return an Illuminate\Auth\Access\Response — see this test\'s docblock. '
            . 'A bool cannot distinguish its 404 refusal from its 403 one.'
        );

        $file = file($method->getFileName() ?: '') ?: [];
        $body = implode('', \array_slice(
            $file,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringNotContainsString(
            'LogicException',
            $body,
            'ContentItemPolicy::downloadFile() must not go back to being a skeleton — '
            . 'GET /content-items/{id}/file depends on it, and a throw would be a 500 rather than '
            . 'a refusal. Open decision 8.4 is closed and must stay closed.'
        );

        $this->assertStringContainsString(
            'denyAsNotFound',
            $body,
            'The hidden-item and wrong-type refusals must be 404s. A plain `return false` here would '
            . 'answer 403, confirming to a caller with a guessed UUID that the id names a real '
            . 'unreleased lesson.'
        );
    }

    /**
     * …and manage() is genuinely NOT one of them any more. Without this, the
     * test above would keep passing if someone reverted manage() to a stub.
     *
     * The check is on the METHOD SOURCE and not on a call, because this file
     * is a bare PHPUnit test with no Laravel application booted — invoking
     * manage() would touch a belongsTo relation and therefore a connection
     * resolver that does not exist here. manage()'s real BEHAVIOUR is proven
     * over HTTP and through the Gate in tests/Feature/ContentItem/
     * ContentItemIsolationTest.
     */
    public function test_the_content_item_manage_ability_no_longer_throws(): void
    {
        $method = new ReflectionMethod(ContentItemPolicy::class, 'manage');
        $file = file($method->getFileName() ?: '') ?: [];
        $body = implode('', array_slice(
            $file,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringNotContainsString(
            'LogicException',
            $body,
            'ContentItemPolicy::manage() was implemented in Stage 5 / Session B and must not '
            . 'go back to being a skeleton — six live routes depend on it.'
        );
        $this->assertArrayNotHasKey('manage', self::DEFERRED_CONTENT_ITEM_ABILITIES);
    }

    /**
     * THE SESSION C HAND-OFF, ASSERTED THE SAME WAY manage()'s WAS.
     *
     * view() and watch() back live public and authenticated routes now, so a
     * revert to a skeleton would be an outage of the public course page and of
     * the video player respectively — and would 500, not 403, because a
     * LogicException escaping Gate::allows() is not an authorization answer.
     *
     * Source inspection rather than invocation, for the reason given above:
     * this file boots no Laravel application, and both bodies dereference a
     * belongsTo relation.
     *
     * The signatures are re-asserted here because they left
     * DEFERRED_CONTENT_ITEM_ABILITIES, which is where they used to be pinned.
     * `?User` on view() is load-bearing — Gate::methodAllowsGuests() consults
     * exactly that nullability to decide whether a logged-out caller reaches
     * the policy at all, and without it every public content item is a 404 for
     * every guest (open decision 3.7's failure mode, one layer down).
     */
    public function test_the_two_session_c_abilities_no_longer_throw(): void
    {
        $expected = [
            'view' => ['?' . User::class, ContentItem::class],
            'watch' => [User::class, ContentItem::class],
        ];

        foreach (self::GRADUATED_IN_SESSION_C as $ability) {
            $this->assertArrayNotHasKey(
                $ability,
                self::DEFERRED_CONTENT_ITEM_ABILITIES,
                "ContentItemPolicy::{$ability}() was implemented in Stage 5 / Session C."
            );

            $method = new ReflectionMethod(ContentItemPolicy::class, $ability);

            $this->assertSame(
                $expected[$ability],
                array_map(
                    static fn (\ReflectionParameter $p): string => (string) $p->getType(),
                    $method->getParameters()
                ),
                "ContentItemPolicy::{$ability}() has the wrong parameter list."
            );

            $file = file($method->getFileName() ?: '') ?: [];
            $body = implode('', \array_slice(
                $file,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            ));

            $this->assertStringNotContainsString(
                'LogicException',
                $body,
                "ContentItemPolicy::{$ability}() must not go back to being a skeleton — live "
                . 'routes depend on it, and a throw would be a 500 rather than a refusal.'
            );
        }
    }

    // ── THE STAGE 8 HAND-OFF: ONE BUILT, ONE DELETED UNBUILT ──────────────

    /**
     * AnnouncementPolicy::manage() is real, and cannot go back to a skeleton.
     *
     * Source inspection rather than invocation, for the reason every twin of
     * this test above gives: this file boots no Laravel application, and the
     * body reads an Eloquent attribute. Its real behaviour is proven over HTTP
     * and through the Gate in tests/Feature/Notification/AnnouncementCrudTest
     * and AnnouncementIsolationTest.
     */
    public function test_the_stage_8_announcement_ability_no_longer_throws(): void
    {
        $this->assertArrayNotHasKey(
            AnnouncementPolicy::class,
            self::CONTRACT,
            'AnnouncementPolicy was implemented in Stage 8 and must not be re-listed as a skeleton.'
        );

        $method = new ReflectionMethod(AnnouncementPolicy::class, 'manage');

        $this->assertSame(
            [User::class, Announcement::class],
            array_map(
                static fn (\ReflectionParameter $p): string => (string) $p->getType(),
                $method->getParameters()
            ),
            'AnnouncementPolicy::manage() has the wrong parameter list.'
        );

        $file = file($method->getFileName() ?: '') ?: [];
        $body = implode('', \array_slice(
            $file,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringNotContainsString(
            'LogicException',
            $body,
            'AnnouncementPolicy::manage() backs three live routes — a throw here would be a 500, '
            . 'not a refusal.'
        );

        /*
         * ┌── THE NULL BRANCH IS ASSERTED ON THE SOURCE, NOT INFERRED. ──────┐
         * │ Approved decision 3.2: `teacher_id IS NULL` is a PLATFORM         │
         * │ announcement and must be an EXPLICIT `false`, not the incidental  │
         * │ `null !== $userId` that backend/ relies on. A refactor that       │
         * │ dropped the branch would still pass every ownership test — the    │
         * │ two spellings agree on every row that HAS an owner — and would    │
         * │ differ only on the platform rows, where the incidental version is │
         * │ one `??` away from failing OPEN.                                  │
         * └──────────────────────────────────────────────────────────────────┘
         */
        $this->assertStringContainsString(
            'null',
            $body,
            'The teacher_id === null branch (decision 3.2) must be written out explicitly.'
        );
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ NotificationPolicy WAS DELETED UNBUILT, AND THIS IS WHAT STOPS IT    ║
     * ║ COMING BACK BY REFLEX.                                                ║
     * ║                                                                      ║
     * ║ Approved decision 3.3. Adding a policy here would look like tightening║
     * ║ security and would LOOSEN it: `Gate::before` returns true for an      ║
     * ║ admin, so `$this->authorize('update', $notification)` would let an    ║
     * ║ admin mark a stranger's inbox row read — which backend/'s inline      ║
     * ║ `$notification['user_id'] !== $userId` never permitted. The boundary  ║
     * ║ is the relation-scoped QUERY, where no role is exempt.                ║
     * ║                                                                      ║
     * ║ Asserted three ways because any one alone can be satisfied by         ║
     * ║ accident: the file is gone, the model has no policy registered, and   ║
     * ║ Laravel 12's CONVENTION-based discovery (App\Models\X ->              ║
     * ║ App\Policies\XPolicy, which needs no registration at all) does not    ║
     * ║ resolve one either.                                                   ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_the_notification_policy_was_deliberately_not_built(): void
    {
        $this->assertFalse(
            class_exists('App\\Policies\\NotificationPolicy'),
            'Decision 3.3: mark-read is scope-based. A NotificationPolicy would hand admins other '
            . "users' inbox rows via Gate::before."
        );

        $this->assertArrayNotHasKey(
            Notification::class,
            \App\Providers\AuthServiceProvider::policyMap(),
            'Notification must have no entry in the policy map.'
        );

        $this->assertArrayNotHasKey(
            \App\Models\BroadcastNotification::class,
            \App\Providers\AuthServiceProvider::policyMap(),
            'Broadcasts are protected by `role:admin` middleware alone (open decision 8.2) — a policy '
            . 'beneath middleware that already admits exactly one role protects nothing.'
        );
    }

    /** The placeholder era is over; the mechanism is kept, the list is empty. */
    public function test_no_placeholder_model_remains(): void
    {
        $this->assertSame(
            [],
            self::PLACEHOLDERS,
            'Stage 8 graduated the last two. A NEW entry here means a stage introduced a forward '
            . 'reference — legitimate, but it must be recorded in docs/open-decisions.md.'
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return list<mixed> */
    private function argumentsFor(string $policy, string $ability): array
    {
        $args = [];

        foreach ((new ReflectionMethod($policy, $ability))->getParameters() as $parameter) {
            $type = (string) $parameter->getType();
            $args[] = $type === 'string' ? 'a-student-id' : new (ltrim($type, '?'))();
        }

        return $args;
    }

    private function shortName(string $class): string
    {
        return substr((string) strrchr($class, '\\'), 1);
    }
}
