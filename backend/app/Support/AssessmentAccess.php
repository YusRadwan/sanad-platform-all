<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserRole;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionGroup;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE REFUSAL SHAPES OF THE ASSESSMENT SURFACE — QUESTION BANK AND EXAMS. ║
 * ║ STAGE 6 / SESSION A.                                                    ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * The third member of a family: App\Support\CourseAccess is the course level,
 * App\Support\ContentItemAccess is the content-item level, and this is the
 * assessment level. All three exist for the same reason — the POLICY decides
 * yes/no, this decides which exception carries which literal string, and the
 * freeze table is the specification for both.
 *
 * ┌── THE LANGUAGE SPLIT IS **PORTED**, NOT AN OVERSIGHT. ───────────────────┐
 * │ backend/ mixes deliberately, and both halves reach a consumer:            │
 * │                                                                          │
 * │   ARABIC   user-facing outcomes — success toasts, field-validation text  │
 * │            ('تم إنشاء مجموعة الأسئلة بنجاح', 'العنوان مطلوب')            │
 * │   ENGLISH  internal/ownership refusals                                    │
 * │            ('You do not own this question group', 'Question not found')  │
 * │                                                                          │
 * │ Translating either direction would be a contract change. The same        │
 * │ convention already holds on App\Support\ContentItemAccess (English 403   │
 * │ strings) next to ContentItemController's Arabic success messages, and    │
 * │ App\Exceptions\ForbiddenException's docblock argues why a role/ownership │
 * │ refusal is deliberately English and deliberately vague.                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * WHY Gate::denies AND NOT authorize(): identical to CourseAccess's argument.
 * authorize() raises AuthorizationException, which App\Exceptions\Handler
 * flattens to the generic 'Forbidden' — the right status with the wrong body.
 * Only an exception implementing App\Exceptions\PublicMessage survives, and
 * ForbiddenException is that. Going through the Gate (rather than a
 * hand-written teacher_id comparison) keeps Stage 3's admin bypass in play; a
 * manual comparison would lose it silently.
 */
final class AssessmentAccess
{
    /** backend/'s literal strings. All six are part of the API contract. */
    public const GROUP_NOT_FOUND = 'Question group not found';

    public const QUESTION_NOT_FOUND = 'Question not found';

    public const NOT_OWNER_GROUP = 'You do not own this question group';

    public const EXAM_NOT_FOUND = 'Exam not found';

    public const COURSE_NOT_FOUND = CourseAccess::COURSE_NOT_FOUND;

    // ── STAGE 6 / SESSION B — rows 90, 91, 92 ────────────────────────────
    //
    // More literal strings, all read from backend/ExamService rather than
    // invented. The language split
    // documented in the class banner holds here too: every one of these is an
    // ENGLISH ownership/identity refusal, while the Arabic strings on the same
    // routes (attempt cap, past due, missing answer, score range) are
    // user-facing VALIDATION text and live on the Actions that raise them.

    /** requireExamAccess(), the missing-identity branch — fail closed. */
    public const NO_EXAM_ACCESS = 'You do not have access to this exam';

    /** requireExamAccess(), a foreign INSTRUCTOR. */
    public const NOT_OWNER_EXAM = 'You do not own this exam';

    /** requireExamAccess()/requireCourseAccess(), an unsubscribed student. */
    public const NOT_ENROLLED = 'You are not enrolled in this course';

    /** resultsFor(), a student naming somebody else's id. */
    public const OWN_RESULTS_ONLY = 'You may only view your own results';

    /** resultsFor(), no row in `exam_attempts` for this (exam, student). */
    public const NO_ATTEMPT = 'No attempt found for this exam';

    /**
     * THE ESCAPE-HATCH REFUSAL. Read
     * App\Actions\Exam\SubmitExamAttempt's banner before moving this
     * anywhere near a Policy.
     */
    public const NOT_A_STUDENT_EXAM = 'Only an enrolled student may submit an exam attempt';

    // ══ QUESTION BANK — rows 80-87 ═══════════════════════════════════════

    /**
     * Resolve a question-group id, or 404 with backend/'s message.
     *
     * NOT implicit route-model binding, for the same reason CourseAccess::
     * findOrFail() is not: binding's own NotFoundHttpException carries "No
     * query results for model [App\Models\QuestionGroup] {id}", which Handler
     * genericises to 'Not found' precisely because it discloses internals — so
     * the contract string would be lost either way.
     */
    public static function findGroupOrFail(?string $id): QuestionGroup
    {
        return QuestionGroup::query()->whereKey((string) $id)->first()
            ?? throw new NotFoundException(self::GROUP_NOT_FOUND);
    }

    /**
     * Resolve a question id, or 404 'Question not found'.
     *
     * The parent group is eager-loaded because EVERY caller immediately needs
     * it for the ownership hop — a question has no owner column of its own.
     */
    public static function findQuestionOrFail(?string $id): Question
    {
        return Question::query()->with('group')->whereKey((string) $id)->first()
            ?? throw new NotFoundException(self::QUESTION_NOT_FOUND);
    }

    /** Rows 82, 83, 85 — write access to a bank. 403 'You do not own this question group'. */
    public static function assertGroupManageable(QuestionGroup $group): void
    {
        if (Gate::denies('manage', $group)) {
            throw new ForbiddenException(self::NOT_OWNER_GROUP);
        }
    }

    /**
     * Row 84 — READ access to a bank's questions. SAME string, different
     * ability.
     *
     * backend/listQuestions() and createQuestion() both call the one
     * getOwnedGroup(), which throws one string for both — so the wording is
     * shared even though QuestionGroupPolicy keeps the abilities distinct. The
     * identical situation, and the identical resolution, as
     * ContentItemAccess::assertGroupViewable() for row 78.
     */
    public static function assertGroupViewable(QuestionGroup $group): void
    {
        if (Gate::denies('view', $group)) {
            throw new ForbiddenException(self::NOT_OWNER_GROUP);
        }
    }

    /**
     * Rows 86 and 87 — a QUESTION's write gate, DELEGATED to its parent bank.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE 404-BEFORE-403 LADDER, AND THE ORPHAN RUNG.                      ║
     * ║                                                                      ║
     * ║ backend/updateQuestion() resolves the question (404 'Question not     ║
     * ║ found'), then hands `$question['question_group_id']` to               ║
     * ║ getOwnedGroup(), which 404s 'Question group not found' on a missing   ║
     * ║ parent and only THEN compares owners. Both rungs are reproduced:      ║
     * ║ `fk_questions_group` is ON DELETE CASCADE so a parentless question    ║
     * ║ is not reachable in practice, but the null branch must exist anyway   ║
     * ║ — handing null to a policy typed `QuestionGroup` is a TypeError, i.e. ║
     * ║ a 500 with a stack trace where a clean 404 was owed.                  ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public static function assertQuestionManageable(Question $question): void
    {
        $group = $question->group
            ?? throw new NotFoundException(self::GROUP_NOT_FOUND);

        self::assertGroupManageable($group);
    }

    // ══ EXAMS — rows 88, 89 ═══════════════════════════════════════════════

    /**
     * Resolve an exam id, or 404 'Exam not found'.
     *
     * `contentItem.course` is eager-loaded in one go: every caller needs the
     * course for the visibility gate AND for the payload decision, and the
     * chain is exactly the two hops App\Models\Exam::course() walks. Loading
     * it here is what turns Stage 3's "resolve that chain ONCE, not as three
     * sequential findById calls" instruction into something the query log can
     * confirm.
     */
    public static function findExamOrFail(?string $id): Exam
    {
        return Exam::query()->with('contentItem.course')->whereKey((string) $id)->first()
            ?? throw new NotFoundException(self::EXAM_NOT_FOUND);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ APPROVED DECISION #1(b) — THE DRAFT-COURSE CASCADE, ON A **PUBLIC**  ║
     * ║ ROUTE. 404, NEVER 403, AND NEVER authorize().                        ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ┌── WHY 404 AND NOT 403 ───────────────────────────────────────────────┐
     * │ 403 on a guessed id is a POSITIVE ORACLE: it confirms the id names a │
     * │ real, unreleased exam, which lets a competitor enumerate an          │
     * │ instructor's unpublished catalogue one id at a time without ever      │
     * │ seeing its contents. 404 is indistinguishable from a wrong guess.     │
     * │ The same argument, and the same mechanism, as                         │
     * │ CourseAccess::assertVisible() and ContentItemAccess::assertViewable().│
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * DenyMode is not CALLED here, deliberately and for the third time in this
     * codebase: it THROWS, and an exception escapes Gate::allows() exactly as
     * it escapes authorize() (open decision 3.5), so an ability that used it
     * would be usable only behind authorize() — which the freeze table forbids
     * in bold on every DEGRADE row. ExamPolicy::view() therefore stays a PURE
     * BOOLEAN and the 404 is raised here, with the same NotFoundException
     * DenyMode itself would have thrown. Identical on the wire.
     *
     * `Gate::forUser($user)->allows(...)` and NEVER `$user?->can(...)` — open
     * decision 3.7. ExamPolicy::view() is declared `?User` and STRUCTURALLY
     * ADMITS GUESTS (a published course's exam is public), so the null-safe
     * idiom would short-circuit on the USER and answer false before the policy
     * was consulted — turning every public exam into a 404 for every
     * logged-out visitor. A fail-closed, silent outage of the course page that
     * no authenticated-actor test would catch.
     *
     * ┌── HIDDEN EXAMS ARE **NOT** CHECKED HERE, AND THAT IS MEASURED. ──────┐
     * │ `content_items.is_hidden` is filtered out of the LISTING (rows 88 and │
     * │ 93 — see ExamController::indexByCourse) but a DIRECT fetch of a       │
     * │ hidden exam by id still answers 200. That asymmetry is not the one    │
     * │ Stage 5 established for generic content items (list -> filtered,      │
     * │ single -> 404), and it was not assumed by analogy: backend/           │
     * │ ExamService::find() calls ExamRepository::findById(), whose SQL is    │
     * │ `SELECT * FROM exams WHERE id = :id LIMIT 1` — it does not join       │
     * │ `content_items` at all and therefore cannot see `is_hidden`. Row 89   │
     * │ has never 404'd on a hidden exam for anybody.                         │
     * │                                                                      │
     * │ Ported as-is. Tightening it would be a product decision (an exam a    │
     * │ student is midway through, hidden by the instructor to stop new       │
     * │ starts, would begin 404ing for them) and it is recorded in            │
     * │ docs/open-decisions.md rather than slipped in during a rewrite.       │
     * │ Pinned by ExamPublicReadTest::test_a_hidden_exam_is_filtered_from_    │
     * │ the_listing_but_still_reachable_by_id.                                │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public static function assertExamVisible(?User $user, Exam $exam): void
    {
        self::assertParentCourseExists($exam->course === null);

        if (!Gate::forUser($user)->allows('view', $exam)) {
            throw new NotFoundException(self::EXAM_NOT_FOUND);
        }
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE ORPHAN RUNG, AND IT MUST COME **BEFORE** THE GATE.               ║
     * ║                                                                      ║
     * ║ A soft-deleted course leaves its content items and their `exams`     ║
     * ║ rows in place (backend/CourseService::delete() says so               ║
     * ║ explicitly), and Course's SoftDeletes global scope then makes         ║
     * ║ `$exam->course` null. The POLICY handles that by failing closed — but ║
     * ║ `Gate::before` returns true for an ADMIN before the policy is entered ║
     * ║ at all, so an admin would receive a 200 for a resource whose course   ║
     * ║ no longer exists while everybody else got a 404. Two answers for one  ║
     * ║ orphan, and the more privileged one is the wrong one.                 ║
     * ║                                                                      ║
     * ║ Checked here, ahead of the Gate, so the answer is the same for every  ║
     * ║ caller. This is the SAME rung, with the SAME message, that            ║
     * ║ ContentItemController::show() raises for an orphaned content item and ║
     * ║ that ContentItemAccess::assertParentCourseExists() raises for the six ║
     * ║ write routes — one behaviour for one state across three families.     ║
     * ║                                                                      ║
     * ║ MEASURED, NOT ANTICIPATED: AssessmentPublicReadTest::test_an_exam_    ║
     * ║ under_a_soft_deleted_course_is_404_not_500 returned 200 for the admin ║
     * ║ before this rung existed.                                            ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * NOTE this is a DIVERGENCE from backend/, and a deliberate one:
     * ExamService::find() never resolves the course at all
     * (`SELECT * FROM exams WHERE id = :id`), so it answered 200 with an
     * orphan's full payload for everyone. Recorded in docs/open-decisions.md.
     */
    private static function assertParentCourseExists(bool $missing): void
    {
        if ($missing) {
            throw new NotFoundException(self::COURSE_NOT_FOUND);
        }
    }

    // ══ STAGE 6 / SESSION B — THE ASSESSMENT WRITE + RESULT SURFACE ══════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ ROWS 90, 91 **AND** 92 ALL ASK THIS ONE QUESTION.                    ║
     * ║ Port of backend/ExamService::requireExamAccess() (~lines 288-312),   ║
     * ║ INCLUDING its three-way message ladder, which is API contract.       ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ┌── IT DELIBERATELY LETS THE OWNER AND THE ADMIN **THROUGH**. ─────────┐
     * │ That is not an oversight being papered over by the student check     │
     * │ downstream — it is TWO DISTINCT QUESTIONS asked in order:            │
     * │                                                                      │
     * │   1. "may you see this course's material at all?"   ← here           │
     * │   2. "are you eligible to RECORD AN ATTEMPT as a student?"           │
     * │                                     ← App\Actions\Exam\SubmitExam-   │
     * │                                       Attempt, and only there        │
     * │                                                                      │
     * │ Collapsing them would break the instructor preview flow (row 90 is   │
     * │ the instructor's read-only paper view and MUST stay open to them),   │
     * │ and it would put an identity rule in a layer `Gate::before` can      │
     * │ bypass. Keep them apart.                                              │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * The ability consulted is `viewQuestions`, which Session A built as the
     * literal port of requireExamAccess() — owner OR active subscriber, with
     * admins arriving true from `Gate::before`. The role branch below only
     * chooses WHICH refusal string a failure gets; it never grants anything.
     */
    public static function assertExamAccessible(?User $user, Exam $exam): void
    {
        if ($user === null) {
            throw new ForbiddenException(self::NO_EXAM_ACCESS);
        }

        // The orphan rung, ahead of the Gate, for the same reason
        // assertParentCourseExists() sits ahead of it on rows 89/94: an admin
        // never enters a policy body, so a null course would answer 200 for
        // them and 403 for everyone else.
        self::assertParentCourseExists($exam->course === null);

        if (Gate::forUser($user)->allows('viewQuestions', $exam)) {
            return;
        }

        throw new ForbiddenException(
            $user->role === UserRole::Instructor ? self::NOT_OWNER_EXAM : self::NOT_ENROLLED
        );
    }

    /**
     * ROW 91's SECOND gate — whose results are these?
     *
     * `$targetStudentId` is resolved by the CONTROLLER (an absent or empty
     * `?student_id=` means "mine") and passed in already decided, so this layer
     * never reads the request — Stage 3's rule, and the reason
     * ExamPolicy::viewResultsOf takes a third parameter at all.
     *
     * Laravel passes extra policy arguments positionally after the model, hence
     * the array form.
     */
    public static function assertResultsViewable(User $user, Exam $exam, string $targetStudentId): void
    {
        if (Gate::forUser($user)->denies('viewResultsOf', [$exam, $targetStudentId])) {
            throw new ForbiddenException(self::OWN_RESULTS_ONLY);
        }
    }

    /** Static-only. */
    private function __construct()
    {
    }
}
