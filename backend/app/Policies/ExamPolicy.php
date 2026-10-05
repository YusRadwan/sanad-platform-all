<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Exam;
use App\Models\User;
use App\Policies\Concerns\ResolvesCourseOwnership;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ COMPLETE AS OF STAGE 6 / SESSION B. SIX ABILITIES, NOT SEVEN.           ║
 * ║                                                                          ║
 * ║   view                 ✅ 6A   existence / draft-course cascade          ║
 * ║   viewEnrolledDetail   ✅ 6A   which payload SHAPE (enrolled)            ║
 * ║   viewStaffDetail      ✅ 6A   which payload SHAPE (staff)               ║
 * ║   viewQuestions        ✅ 6A   the paper itself — row 90, ROUTED IN 6B   ║
 * ║   viewAnswerKey        ✅ 6B   `exams.show_answers`'s enforcement point  ║
 * ║   viewResultsOf        ✅ 6B   whose results — the 3-parameter ability   ║
 * ║   submit               ⛔ DELETED IN 6B — read the headstone below       ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Ported shape from backend/app/Services/ExamService (~lines 254-312:
 * requireExamAccess / isCourseStaff / courseOfExam, plus resultsFor ~185-250).
 *
 * OWNERSHIP IS INDIRECT: exam -> content_item -> course -> teacher_id. The
 * skeleton required Stage 6 to resolve that chain ONCE rather than as three
 * sequential findById calls; it is `$exam->course`, an attribute accessor over
 * the eager-loadable `contentItem.course` pair — see App\Models\Exam.
 *
 * ┌── WHY THERE ARE **THREE** READ ABILITIES AND NOT ONE. ───────────────────┐
 * │ Exactly the argument CoursePolicy's four abilities make, applied to one  │
 * │ resource. One actor — a subscribed student — answers differently to all  │
 * │ three:                                                                   │
 * │                                                                          │
 * │   ability             owner  subscribed  other instructor  guest         │
 * │   ──────────────────  ─────  ──────────  ────────────────  ─────         │
 * │   view (published)    yes    yes         yes               yes           │
 * │   view (draft course) yes    NO          NO                NO            │
 * │   viewEnrolledDetail  yes    YES         no                n/a           │
 * │   viewStaffDetail     yes    NO          no                n/a           │
 * │                                                                          │
 * │ Collapsing them means picking one of those answers and being wrong       │
 * │ twice. Concretely: the merged version either hands `question_group_id`   │
 * │ to every enrolled student (approved decision #3's exact leak) or hides   │
 * │ the exam's own duration from the storefront.                             │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ADMINS ARE ABSENT FROM THIS FILE, as from every policy: Gate::before has
 * already returned true for them before any method here is entered.
 */
class ExamPolicy
{
    use ResolvesCourseOwnership;

    /**
     * MAY THIS CALLER KNOW THIS EXAM EXISTS? — freeze-table rows 88 and 89.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ `?User`, NOT `User`. THE SKELETON'S SIGNATURE IS **WIDENED** HERE,   ║
     * ║ AND THE NULLABILITY IS LOAD-BEARING RATHER THAN COSMETIC.           ║
     * ║                                                                      ║
     * ║ Rows 88/89 are DEGRADE rows: `/courses/{id}/exams` and `/exams/{id}` ║
     * ║ carry no `auth` in backend/ and none here, because the course page   ║
     * ║ renders logged-out. Laravel's Gate consults exactly this parameter's ║
     * ║ nullability (methodAllowsGuests) to decide whether a logged-out      ║
     * ║ caller reaches the policy at all — with a non-nullable `User` the    ║
     * ║ Gate short-circuits to false and EVERY exam is a 404 for EVERY       ║
     * ║ guest. That is open decision 3.7's failure mode one layer down, and  ║
     * ║ it fails CLOSED, i.e. silently.                                      ║
     * ║                                                                      ║
     * ║ The skeleton wrote `User` because Stage 3 read requireExamAccess(),  ║
     * ║ which backs row 90 (`/exams/{id}/questions`, `auth`) — a different   ║
     * ║ question on a different route. The refusal STRINGS it recorded       ║
     * ║ ('You do not own this exam' / 'You are not enrolled in this course') ║
     * ║ belong to that ability, viewQuestions(), and are preserved there.    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * MIRRORS CoursePolicy::view()'s SHAPE EXACTLY, via `$exam->course` —
     * published is public, everything else is the owner alone (plus admins,
     * upstream). That is approved decision #1(b): a draft course's exams are
     * 404 for a non-owner exactly as the course row and its syllabus already
     * are, because hiding a course while leaving its exam list enumerable
     * would hide nothing.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ "MIRRORS CoursePolicy::view()" IS LOAD-BEARING: THE PUBLIC BRANCH    ║
     * ║ BELOW IS Course::isPubliclyVisible(), NOT `is_published`.            ║
     * ║                                                                      ║
     * ║ This is the ONE read on the exam surface that does not already       ║
     * ║ cascade through CourseAccess::assertVisible() — GET /exams/{id}      ║
     * ║ resolves the exam by id and asks this ability directly, so a copy of ║
     * ║ the public predicate lives here and would otherwise have kept a      ║
     * ║ SUSPENDED instructor's exams enumerable after their course row went  ║
     * ║ dark. The neighbouring route, GET /courses/{id}/exams, is covered by  ║
     * ║ the course check and needs nothing of its own.                        ║
     * ║                                                                      ║
     * ║ As in CoursePolicy, the change is confined to the PUBLIC branch: the  ║
     * ║ ownsCourse() line below is untouched, so the suspended owner keeps    ║
     * ║ their own exams, and admins never reach this body (Gate::before).     ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * THE NULL-COURSE BRANCH FAILS CLOSED, identically to every other policy
     * in this application: a soft-deleted course leaves its content items and
     * their detail rows in place, and an orphan's existence is nobody's to
     * confirm. App\Support\AssessmentAccess turns the same fact into a 404 one
     * rung earlier, so this branch is the belt to that layer's braces.
     *
     * PURE BOOLEAN, NEVER THROWING — decision 3.5's standing constraint. The
     * 404 is raised by App\Support\AssessmentAccess::assertExamVisible(), the
     * pattern Session 5A settled at CourseAccess::assertVisible() and 5C
     * re-used at ContentItemAccess::assertViewable().
     */
    public function view(?User $user, Exam $exam): bool
    {
        $course = $exam->course;

        if ($course === null) {
            return false;
        }

        if ($course->isPubliclyVisible()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $this->ownsCourse($user, $course);
    }

    /**
     * WHICH PAYLOAD SHAPE? — the ENROLLED one.
     *
     * `consumeContent` on the owning course: the owner previewing their own
     * material, or a STUDENT with a live subscription. Nothing else.
     *
     * ┌── THIS ABILITY DECIDES **KEYS**, NOT ACCESS. ────────────────────────┐
     * │ A `false` here is never a refusal — it is a smaller 200, because     │
     * │ rows 88/89 are DEGRADE rows and a guest browsing the storefront is   │
     * │ the intended user of them, not an intruder. The caller turns the     │
     * │ boolean into a Resource CLASS (App\Http\Resources\ExamResourceFactory│
     * │ ), and those classes are structurally separate precisely so a wrong  │
     * │ answer here degrades rather than leaks.                              │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * `$user->can('consumeContent', …)` rather than `$this->canConsume(…)`:
     * the definition of "may consume this course" must stay in exactly one
     * place, CoursePolicy, and going through the Gate keeps Stage 3's admin
     * bypass in play on that ability too. A direct trait call would be a
     * second copy of the rule that could drift.
     */
    public function viewEnrolledDetail(User $user, Exam $exam): bool
    {
        $course = $exam->course;

        return $course !== null && $user->can('consumeContent', $course);
    }

    /**
     * WHICH PAYLOAD SHAPE? — the STAFF one, i.e. the only shape carrying
     * `question_group_id`.
     *
     * `manage` on the owning course — the OWNER, plus admins upstream.
     *
     * ┌── A SUBSCRIBED STUDENT MUST FAIL THIS WHILE PASSING THE ONE ABOVE. ──┐
     * │ That gap IS approved decision #3. `exams.question_group_id` names    │
     * │ the instructor's private bank; handing it to a student turns         │
     * │ `GET /question-groups/{id}/questions` into a targeted request rather │
     * │ than a guess — and while that route 403s them today, the id is a     │
     * │ tenant identifier that no student surface has any use for. The       │
     * │ narrowest correct answer is not to emit it, and the way to make that │
     * │ structural is a separate Resource class that has no line of code     │
     * │ capable of writing the key.                                          │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function viewStaffDetail(User $user, Exam $exam): bool
    {
        $course = $exam->course;

        return $course !== null && $user->can('manage', $course);
    }

    /**
     * GET /exams/{id}/questions — the paper itself. Freeze-table row 90.
     *
     * ┌── BUILT NOW, ROUTED IN SESSION B. THAT IS DELIBERATE. ───────────────┐
     * │ No route in this session references it, and none may: row 90 is a    │
     * │ Session B surface. It is implemented here anyway because             │
     * │ App\Http\Resources\QuestionResource and StaffQuestionResource are    │
     * │ built here (they are pure data-shaping with no write dependency),    │
     * │ and an ability with two ready consumers and no body is an invitation │
     * │ for Session B to re-derive the rule inline.                          │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * PORTED FROM requireExamAccess() EXACTLY — the owner OR a subscriber.
     * Note it is NOT `viewEnrolledDetail` under another name even though the
     * two are true for the same actors today: this one answers "may you read
     * the questions", which Session B may narrow (a locked exam, an exam whose
     * window has not opened) without touching which KEYS a detail payload
     * carries. Two questions, two abilities.
     *
     * `ownsCourse()` is called explicitly ahead of the Gate hop so that an
     * instructor previewing their own paper never depends on a subscription
     * query, matching backend/'s branch order — cost, not correctness.
     *
     * The three refusal strings the skeleton recorded belong to THIS ability
     * and are Session B's to raise at the route:
     *   'You do not own this exam'            a foreign INSTRUCTOR
     *   'You are not enrolled in this course'  an unsubscribed student
     *   'You do not have access to this exam'  missing identity (fail closed)
     */
    public function viewQuestions(User $user, Exam $exam): bool
    {
        $course = $exam->course;

        if ($course === null) {
            return false;
        }

        return $this->ownsCourse($user, $course) || $user->can('consumeContent', $course);
    }

    /**
     * May this caller see `correct_answer`?
     *
     * COURSE STAFF ALWAYS. A student only when `exams.show_answers = 1`, and
     * only on their own submitted result — backend/ enforces this in
     * resultsFor() (~line 224). `show_answers` was written by the instructor
     * form and read by NOTHING until that fix; do not let it regress.
     *
     * Split out from viewQuestions() on purpose: same actor, same exam, two
     * different answers.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ ✅ IMPLEMENTED IN STAGE 6 / SESSION B. IT IS **THE** ENFORCEMENT     ║
     * ║ POINT FOR `exams.show_answers` — the column an early audit found was ║
     * ║ written by the instructor form and read by NOTHING.                  ║
     * ║                                                                      ║
     * ║ A **PURE FUNCTION OF THE EXAM AND THE CALLER**. No `request()`, no   ║
     * ║ query string, no attempt lookup — Stage 3's rule for every policy    ║
     * ║ body, and here it is what makes the ability answerable identically   ║
     * ║ from the results route (row 91) and the paper route (row 90) without ║
     * ║ either of them having to agree on a request shape first.             ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * STAFF ALWAYS, REGARDLESS OF THE SWITCH. `show_answers` is a
     * STUDENT-FACING reveal toggle; the people who authored the key are not
     * the people it hides it from. Admins arrive true from `Gate::before`
     * before this body runs, which is the same answer this body would give
     * them via `manage` — the bypass and the rule agree here, so nothing
     * depends on which one fired.
     *
     * A NULL COURSE FAILS CLOSED, like every other body in this file. Callers
     * have already turned that state into a 404 one rung earlier
     * (App\Support\AssessmentAccess::assertExamAccessible).
     */
    public function viewAnswerKey(User $user, Exam $exam): bool
    {
        $course = $exam->course;

        if ($course === null) {
            return false;
        }

        return $user->can('manage', $course) || (bool) $exam->getAttribute('show_answers');
    }

    /*
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ ⛔ `submit()` IS **GONE**. IT WAS DELETED IN STAGE 6 / SESSION B, ON  ║
     * ║ PURPOSE, AND IT MUST NOT BE RE-ADDED. THIS COMMENT IS THE HEADSTONE. ║
     * ║                                                                      ║
     * ║ Session A left the signature throwing and explicitly handed the       ║
     * ║ "should this ability exist at all?" question to Session B (open       ║
     * ║ decision 6.7, which authorised deleting it outright). It does not     ║
     * ║ exist, for two independent reasons — either alone would be enough:    ║
     * ║                                                                      ║
     * ║  1. THE PART OF THE RULE THAT MATTERS CANNOT LIVE IN A POLICY.        ║
     * ║     "Is this caller a real row in `students`?" is invisible to any    ║
     * ║     policy body, because `Gate::before` returns true for an admin     ║
     * ║     BEFORE the body is entered (open decision 3.1). And the owning    ║
     * ║     instructor passes course access legitimately, on the merits.      ║
     * ║     A `submit` ability could therefore only ever answer a question    ║
     * ║     nobody was asking.                                                ║
     * ║                                                                      ║
     * ║  2. THE REST OF THE RULE IS THE ATTEMPT CAP, WHICH MUST BE EVALUATED  ║
     * ║     INSIDE THE LOCKED TRANSACTION THAT PERFORMS THE INSERT. A policy  ║
     * ║     consulted before the transaction opens is a check whose answer is ║
     * ║     stale by the time it is used — the exact TOCTOU this session      ║
     * ║     exists to close.                                                  ║
     * ║                                                                      ║
     * ║ WHAT REPLACED IT, AND WHERE TO LOOK:                                  ║
     * ║   course access  App\Support\AssessmentAccess::assertExamAccessible() ║
     * ║                  (ability `viewQuestions` — the ported                ║
     * ║                  requireExamAccess, shared with row 90)               ║
     * ║   student check  App\Actions\Exam\SubmitExamAttempt, first statement, ║
     * ║                  403 'Only an enrolled student may submit an exam     ║
     * ║                  attempt'                                             ║
     * ║   attempt cap    the same Action, INSIDE DB::transaction(), after     ║
     * ║                  `SELECT … FOR UPDATE` on the `exams` row              ║
     * ║                                                                      ║
     * ║ Keeping a hollow `submit` that returned "course access" under a name  ║
     * ║ meaning "may submit" would be worse than nothing: the next reader     ║
     * ║ would trust it as the gate and stop looking for the two checks that   ║
     * ║ actually hold.                                                        ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */

    /**
     * View the attempt/result of a SPECIFIC student.
     *
     * ┌── THE THIRD PARAMETER IS THE WHOLE POINT OF THIS ABILITY. ───────────┐
     * │ Without `$targetStudentId`, the question degenerates into "may this  │
     * │ user see results?" — which is true for every enrolled student and    │
     * │ therefore lets any student read any classmate's score by changing a  │
     * │ query-string id. backend/ guards it at ExamService::resultsFor()     │
     * │ (~line 200): a caller who is neither admin nor instructor may only   │
     * │ pass their OWN id.                                                   │
     * │                                                                      │
     * │ It is typed `string`, NOT `?string`. backend/'s signature is         │
     * │ `?string $studentId = null` meaning "default to me", and that        │
     * │ defaulting is a CONTROLLER concern (resolve null to the session id   │
     * │ before asking).                                                      │
     * │                                                                      │
     * │ Rejection message: 'You may only view your own results'.             │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * Laravel passes extra policy arguments positionally after the model:
     * `Gate::allows('viewResultsOf', [$exam, $studentId])`.
     *
     * ✅ IMPLEMENTED IN STAGE 6 / SESSION B.
     *
     * ┌── IT DOES NOT READ `exam_attempts`, AND IT MUST NOT. ────────────────┐
     * │ "Whose results may you ask for" and "does an attempt exist" are two  │
     * │ questions with two different statuses — 403 and 404 — and merging    │
     * │ them would make the answer depend on data the caller is not yet      │
     * │ authorised to have looked up. The existence check happens AFTER this │
     * │ returns true, in App\Http\Controllers\ExamController::results().     │
     * │                                                                      │
     * │ Consequence, and it is the ported one: a staff member asking for a   │
     * │ student who has never sat the exam gets 404, not 403.                 │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * The comparison is `(string)` on both sides for the same reason
     * ResolvesCourseOwnership::ownsCourse() casts: these are CHAR(36) values
     * that arrive as query-string text on one side and as a model key on the
     * other, and `===` between a string and anything else is silently false.
     *
     * A foreign INSTRUCTOR never reaches here — assertExamAccessible() has
     * already answered 403 'You do not own this exam' — so `manage` is
     * effectively "the owner, or an admin via Gate::before".
     */
    public function viewResultsOf(User $user, Exam $exam, string $targetStudentId): bool
    {
        if ($targetStudentId === (string) $user->getKey()) {
            return true;
        }

        $course = $exam->course;

        return $course !== null && $user->can('manage', $course);
    }
}
