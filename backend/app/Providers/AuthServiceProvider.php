<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\CenterGroup;
use App\Models\CenterSession;
use App\Models\ContentItem;
use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\Exam;
use App\Models\QuestionGroup;
use App\Models\Section;
use App\Models\User;
use App\Policies\AnnouncementPolicy;
use App\Policies\CenterGroupPolicy;
use App\Policies\CenterSessionPolicy;
use App\Policies\ContentItemPolicy;
use App\Policies\CourseGroupPolicy;
use App\Policies\CoursePolicy;
use App\Policies\ExamPolicy;
use App\Policies\QuestionGroupPolicy;
use App\Policies\SectionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE ONE PLACE AUTHORIZATION IS WIRED UP. STAGE 3.                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Laravel 11+ removed the framework's own AuthServiceProvider from the
 * skeleton, and Laravel 12 DISCOVERS policies by naming convention
 * (App\Models\Course -> App\Policies\CoursePolicy) with no registration at all.
 * This class is reintroduced by hand anyway, and the map below is written out
 * explicitly, for two reasons:
 *
 *  1. Gate::before has to be registered SOMEWHERE, and a provider whose whole
 *     subject is authorization is a better home for it than AppServiceProvider,
 *     where it would sit next to unrelated bootstrapping.
 *
 *  2. AN EXPLICIT MAP IS AUDITABLE; CONVENTION IS NOT. "Which model is
 *     protected, and by what?" must be answerable by reading ONE file. With
 *     discovery alone, a policy that is silently not found — a typo'd class
 *     name, a model moved to a sub-namespace, a policy deleted in a refactor —
 *     produces NO error: Gate simply finds no policy, and every ability on that
 *     model falls through to `false`… or, on an ability nobody defined, to
 *     whatever the caller's own `if` decides. In a stage whose entire subject
 *     is tenant isolation, a security control that can disappear without a
 *     failing test is not acceptable. PolicyRegistrationTest asserts this map
 *     is what Gate actually resolves.
 *
 * The provider is registered in bootstrap/providers.php.
 */
class AuthServiceProvider extends ServiceProvider
{
    /**
     * Model => Policy. EIGHT entries as of Stage 8, and every one of them is
     * now LIVE — the map held nine and eight of those were skeletons when it
     * was written in Stage 3.
     *
     * Registering the skeletons early was deliberate: it meant the resource was
     * already gated the moment its routes appeared, and a stage that forgot to
     * implement a body got a loud 500 from LogicException rather than a quiet
     * unprotected endpoint. Stage 8 retired the last of them.
     */
    private const POLICIES = [
        Course::class => CoursePolicy::class,
        Section::class => SectionPolicy::class,
        CourseGroup::class => CourseGroupPolicy::class,
        ContentItem::class => ContentItemPolicy::class,
        Exam::class => ExamPolicy::class,
        QuestionGroup::class => QuestionGroupPolicy::class,
        Announcement::class => AnnouncementPolicy::class,
        // Center System — same direct-owner shape as QuestionGroupPolicy.
        CenterGroup::class => CenterGroupPolicy::class,
        CenterSession::class => CenterSessionPolicy::class,
        /*
         * ╔══════════════════════════════════════════════════════════════════╗
         * ║ Notification::class HAS NO POLICY, AND THAT IS STAGE 8's         ║
         * ║ DELIBERATE ANSWER TO OPEN DECISION 3.3 — NOT AN OMISSION.        ║
         * ║                                                                  ║
         * ║ A NotificationPolicy skeleton lived here from Stage 3 and was     ║
         * ║ DELETED, unbuilt. Its only ability would have been "the recipient ║
         * ║ may mark their own row read", and routing that through the Gate   ║
         * ║ makes the answer STRICTLY WORSE: `Gate::before` returns true for  ║
         * ║ an admin, so an admin could mark a stranger's notification read — ║
         * ║ something backend/'s inline `user_id !== $userId` comparison      ║
         * ║ never permitted. The bypass is right for course data (the admin   ║
         * ║ console genuinely moderates every tenant) and wrong for a         ║
         * ║ personal inbox, which nothing admin-facing even lists.            ║
         * ║                                                                  ║
         * ║ The boundary is the QUERY instead:                                ║
         * ║   $request->user()->notifications()->visible()->find($id)         ║
         * ║ — the ownership predicate is compiled in by the relation, so       ║
         * ║ there is no ability to bypass and no role that is exempt. A       ║
         * ║ miss is 404, matching Stage 5's "a guessed uuid must not confirm  ║
         * ║ existence" rule rather than backend/'s 403.                       ║
         * ║                                                                  ║
         * ║ Broadcasts likewise carry no policy: all five routes are          ║
         * ║ `role:admin` at the router, and a policy beneath middleware that  ║
         * ║ already admits exactly one role protects nothing but adds a       ║
         * ║ second place for the rule to drift. Open decisions 8.1 and 8.2.   ║
         * ╚══════════════════════════════════════════════════════════════════╝
         */
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerAdminBypass();
    }

    /** Exposed so tests can assert the registered map rather than re-type it. */
    public static function policyMap(): array
    {
        return self::POLICIES;
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE ADMIN BYPASS. ONE PLACE, ONE RULE.                               │
     * │                                                                      │
     * │ backend/ repeats `if ($role === 'admin') return;` inside NINE        │
     * │ separate ownership checks across its services. Nine copies of a      │
     * │ privilege escalation rule is nine chances to write `!==` once. This  │
     * │ closure is all nine of them.                                         │
     * │                                                                      │
     * │ ══ IT MUST RETURN null FOR NON-ADMINS. NEVER false. ══               │
     * │                                                                      │
     * │ Gate::callBeforeCallbacks() (vendor/laravel/framework/src/Illuminate/│
     * │ Auth/Access/Gate.php ~line 564) stops at the FIRST before-callback   │
     * │ that returns anything other than null:                               │
     * │                                                                      │
     * │     if (! is_null($result = $before($user, $ability, $arguments))) { │
     * │         return $result;   // policy NEVER runs                       │
     * │     }                                                                │
     * │                                                                      │
     * │ `false` is not null. Returning it would DENY every non-admin for     │
     * │ every ability on every model, before a single policy method ran —    │
     * │ a total, silent outage of the instructor and student surfaces that   │
     * │ no policy test would catch, because no policy would execute.         │
     * │ `null` means "no opinion, carry on to the policy". Proven by         │
     * │ GateBeforeTest::test_a_non_admin_actually_reaches_the_policy_method. │
     * │                                                                      │
     * │ GUESTS never reach this callback at all: canBeCalledWithUser()       │
     * │ skips any before-callback whose parameter is non-nullable when the   │
     * │ user is null. The `User $user` hint (not `?User`) is therefore a     │
     * │ guard, not a formality — it makes a guest structurally incapable of  │
     * │ triggering the bypass.                                               │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ ⚠ DOCUMENTED ESCAPE HATCH — READ BEFORE BUILDING ANY `submit`       ║
     * ║   ABILITY (STAGE 6). THE BYPASS BELOW MUST NOT BE THE ONLY GUARD.   ║
     * ║                                                                      ║
     * ║ `exam_attempts.student_id` is a FOREIGN KEY into                    ║
     * ║ `students.user_id`. An ADMIN and an                                 ║
     * ║ INSTRUCTOR have no `students` row at all.                            ║
     * ║                                                                      ║
     * ║ So when an admin hits POST /exams/{id}/submit, this callback returns ║
     * ║ true, ExamPolicy::submit() is never consulted, the action proceeds   ║
     * ║ to INSERT an attempt row, and MySQL rejects it with a raw FK         ║
     * ║ violation — a **500** where a clean **403** was owed. backend/ hit   ║
     * ║ exactly this and patched it in                                       ║
     * ║   backend/app/Services/ExamService.php ~lines 119-129                ║
     * ║ with an explicit studentExists() check placed AFTER its access gate. ║
     * ║ Note both patches sit downstream of a gate that had ALREADY waved    ║
     * ║ admins (and the owning instructor) through — the check is not        ║
     * ║ redundant with the gate, it is the part the gate cannot express.     ║
     * ║                                                                      ║
     * ║ THE RULE FOR STAGE 6:                                                ║
     * ║   The "only a real student may submit" rejection MUST live somewhere ║
     * ║   Gate::before does NOT cover — the Form Request's validation, or    ║
     * ║   the action/service performing the write. It MUST NOT be written    ║
     * ║   inside ExamPolicy::submit(), because                               ║
     * ║   those methods are never entered for an admin, and it MUST NOT be   ║
     * ║   "handled" by special-casing `submit` in the callback below —       ║
     * ║   carving ability names out of the bypass here would put a second    ║
     * ║   authorization rule in the one place that is supposed to hold       ║
     * ║   exactly one.                                                       ║
     * ║                                                                      ║
     * ║   Rejection messages, verbatim from backend/:                        ║
     * ║     'Only an enrolled student may submit an exam attempt'            ║
     * ║                                                                      ║
     * ║ Also mirrored on ExamPolicy::submit() so the warning is unmissable   ║
     * ║ from either end.                                                     ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * WHY AN ADMIN IS TRUSTED AT ALL: the admin console legitimately reads and
     * moderates every tenant's data (Admin/*.tsx renders every teacher's
     * courses, every student's enrollment). That is a product requirement, not
     * an oversight — but it is precisely why it is written ONCE, here, where it
     * can be found, tested and revoked.
     */
    private function registerAdminBypass(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            // `=== UserRole::Admin`, not a string comparison: users.role is
            // cast to the enum on the model, so comparing against 'admin'
            // would compare an enum instance to a string and be false for
            // EVERYONE — including real admins.
            return $user->role === UserRole::Admin ? true : null;
        });
    }
}
