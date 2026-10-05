<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Exam\SubmitExamAttempt;
use App\Exceptions\NotFoundException;
use App\Http\Resources\ExamResourceFactory;
use App\Http\Resources\ExamResultResourceFactory;
use App\Http\Resources\QuestionResource;
use App\Http\Resources\StaffQuestionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Support\AssessmentAccess;
use App\Support\ContentItemAccess;
use App\Support\CourseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Exams — freeze-table rows 88 and 89. TWO routes, BOTH PUBLIC.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ EVERY LINE OF THIS CONTROLLER IS REACHABLE BY A LOGGED-OUT VISITOR.     ║
 * ║ TREAT ALL OF IT AS A LEAK VECTOR.                                       ║
 * ║                                                                          ║
 * ║   row 88  GET /courses/{courseId}/exams   DEGRADE                        ║
 * ║   row 89  GET /exams/{examId}             404 then DEGRADE               ║
 * ║                                                                          ║
 * ║ APPROVED DECISION #1 — THE MIDDLE GROUND. The routes STAY               ║
 * ║ unauthenticated, matching backend/ and the public-degrade pattern used   ║
 * ║ everywhere else in this application (and consistent with the already-    ║
 * ║ approved decision 3.6). Three things make that safe, and all three are   ║
 * ║ new in this session:                                                     ║
 * ║                                                                          ║
 * ║  (a) HIDDEN ITEMS ARE FILTERED out of the listing for anyone who is not  ║
 * ║      the course owner or an admin. backend/ExamService::listByCourse()   ║
 * ║      does NOT do this — it selects every exam under the course — so a    ║
 * ║      guest could enumerate exams the instructor had staged for next      ║
 * ║      term. A real gap, closed here.                                      ║
 * ║  (b) DECISION 3.6 CASCADES: a draft course's exam list and its exams'    ║
 * ║      detail routes are 404 for a non-owner, exactly as the course row    ║
 * ║      and its syllabus already are. Hiding a course while leaving its     ║
 * ║      exam list enumerable would hide nothing.                            ║
 * ║  (c) THE FIELD-LEVEL PAYLOAD IS GOVERNED BY THE THREE-WAY RESOURCE      ║
 * ║      SPLIT (App\Http\Resources\ExamResourceFactory), so hiding sensitive ║
 * ║      fields does not rely on the route being auth-gated AT ALL. That is  ║
 * ║      the property that makes (a) and (b) hardening rather than the whole ║
 * ║      defence.                                                            ║
 * ║                                                                          ║
 * ║ THE THREE RULES THAT MUST NOT BE RELAXED, inherited verbatim from        ║
 * ║ Session 5C's public block:                                               ║
 * ║  1. NEVER `$this->authorize()` / `Gate::authorize()` here. DEGRADE rows: ║
 * ║     authorize() throws 403 at a storefront visitor — an outage of the    ║
 * ║     public course page, not a protection.                                ║
 * ║  2. ALWAYS `Gate::forUser($request->user())->allows(...)`, never         ║
 * ║     `$request->user()?->can(...)` — open decision 3.7.                   ║
 * ║  3. `is_hidden` FILTERS in a list and does NOT 404 a single fetch — see  ║
 * ║     show() below, where the asymmetry is MEASURED against backend/ and   ║
 * ║     is NOT the same asymmetry Stage 5 established for content items.     ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ STAGE 6 / SESSION B ADDED THREE **AUTHENTICATED** ROUTES BELOW THOSE    ║
 * ║ TWO. THE GUARANTEE THIS CLASS CARRIED HAS CHANGED SHAPE — READ THIS.   ║
 * ║                                                                          ║
 * ║   row 90  GET  /exams/{examId}/questions   auth                          ║
 * ║   row 91  GET  /exams/{examId}/results     auth                          ║
 * ║   row 92  POST /exams/{examId}/submit      rl:submit + auth              ║
 * ║                                                                          ║
 * ║ Until this session, "a student cannot cheat the attempt cap" was true    ║
 * ║ BY CONSTRUCTION: no handler existed whose locking could be got wrong.    ║
 * ║ It is now true because App\Actions\Exam\SubmitExamAttempt is the ONLY    ║
 * ║ writer of `exam_attempts` and performs its count and its INSERT inside   ║
 * ║ one transaction that holds an exclusive lock on the PARENT `exams` row.  ║
 * ║ That is weaker than absence, so it is proven rather than asserted — by   ║
 * ║ tests/Concurrency/exam_attempt_race.php, which races 8 real OS           ║
 * ║ processes against it AND against an unlocked control that must FAIL.     ║
 * ║                                                                          ║
 * ║ THE THREE PUBLIC-ROUTE RULES ABOVE DO **NOT** APPLY TO THESE THREE.      ║
 * ║ They carry `auth`, they are not DEGRADE rows, and a refusal on them is   ║
 * ║ a genuine refusal — 403 with one of backend/'s literal strings, raised   ║
 * ║ by App\Support\AssessmentAccess. Do not "harmonise" them with show()     ║
 * ║ and indexByCourse() above; the two halves of this controller answer      ║
 * ║ different kinds of question on purpose.                                  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class ExamController extends Controller
{
    /**
     * GET /api/v1/courses/{courseId}/exams — PUBLIC. Row 88.
     *
     * THE REFUSAL LADDER, IN ORDER, AND EACH RUNG IS LOAD-BEARING:
     *   1. 404 'Course not found' — no such course, or it is soft-deleted
     *      (Course's global scope answers both with one query).
     *   2. 404 'Course not found' — the course is a DRAFT and the caller is
     *      neither its owner nor an admin. Decision 3.6's cascade, and the
     *      rung backend/ does not have: listByCourse() checked nothing at all.
     *
     * ┌── HIDDEN EXAMS ARE **FILTERED**, NOT REFUSED — AND NOT UNCONDITIONALLY.┐
     * │ Owner and admin see everything, including hidden. Everyone else — a   │
     * │ guest, an unsubscribed visitor, AND A FULLY PAID-UP SUBSCRIBER — gets │
     * │ the filtered list. That gap is the most-repeated caution in this      │
     * │ rewrite: `viewHidden` is owner-only while `consumeContent`, asked     │
     * │ two lines later on the same course in the same request, says yes for  │
     * │ the paying student. Two questions; the subscriber is exactly the      │
     * │ actor whose answers differ.                                           │
     * │                                                                      │
     * │ This is row-20 semantics (entitlement-dependent filtering), NOT       │
     * │ row-22's (`is_hidden = 0` in the SQL for everybody, owner included).  │
     * │ Chosen deliberately: an instructor's own exam list is a management    │
     * │ surface and must show what they staged. Open decision 5.14 records    │
     * │ that `is_hidden` has THREE behaviours in this codebase and that every │
     * │ new read route must pick one EXPLICITLY rather than by analogy — this │
     * │ is that explicit pick.                                                │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * The Gate is consulted ONCE PER REQUEST, not once per row: both rules are
     * properties of the (caller, course) pair, and a per-exam call would add a
     * subscription EXISTS query per exam on a public endpoint.
     *
     * `with('contentItem.course')` is the N+1 fix AND the correctness fix: the
     * Resources read `title`/`course_id` off the content item and the FACTORY
     * asks a policy that walks exam -> contentItem -> course for every row.
     * Without the eager load that is three queries per exam.
     */
    public function indexByCourse(Request $request, string $courseId): JsonResponse
    {
        $course = CourseAccess::findOrFail($courseId);

        CourseAccess::assertVisible($request->user(), $course);

        $query = Exam::query()->forCourse((string) $course->getKey())->with(['contentItem.course', 'contentItem.group']);

        $mayaSeeHidden = ContentItemAccess::maySeeHidden($request->user(), $course);
        if (!$mayaSeeHidden) {
            $query->visibleOnly();
        }

        $exams = $query->get();

        // `visibleOnly()` only checks the content item's own `is_hidden` —
        // it has no idea the item might belong to a GROUP that is itself
        // hidden or schedule-gated (a future `start_at` / past `end_at`),
        // the same three-gate check CourseController::structure() applies
        // to every group before listing its items. Without this, an exam
        // dropped into a group scheduled to start next week was still
        // returned here today, and the frontend then attached it to
        // whichever group happened to be first in the student's structure
        // response — a real visibility bypass, not just a display glitch.
        if (!$mayaSeeHidden) {
            $exams = $exams->filter(
                static fn (Exam $exam): bool => ($exam->contentItem?->group?->isCurrentlyVisible() ?? true)
            )->values();
        }

        $exams = $exams->map(
            static fn (Exam $exam) => ExamResourceFactory::for($request->user(), $exam)
        );

        return ApiResponse::success(['exams' => $exams->values()]);
    }

    /**
     * GET /api/v1/exams/{examId} — PUBLIC. Row 89.
     *
     * TWO INDEPENDENT DECISIONS, IN THIS ORDER, AND THEY ARE NOT THE SAME
     * QUESTION:
     *
     *   assertExamVisible()  MAY YOU KNOW IT EXISTS?  -> 404 if not (a draft
     *                        course's exam, for a non-owner). Includes a
     *                        subscribed student, because a draft course is not
     *                        subscribable.
     *   ExamResourceFactory  WHICH SHAPE DO YOU GET?  -> public / enrolled /
     *                        staff. Never a refusal; the un-entitled answer is
     *                        a smaller 200.
     *
     * Collapsing them into one boolean is the leak this session exists to
     * prevent: a guest is `true` on the first and `false` on both halves of the
     * second.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ A HIDDEN EXAM IS STILL REACHABLE BY ID HERE — 200, NOT 404 — AND     ║
     * ║ THAT IS **MEASURED AGAINST backend/**, NOT INFERRED FROM STAGE 5.    ║
     * ║                                                                      ║
     * ║ Stage 5 established, for generic content items, that a hidden item is ║
     * ║ FILTERED from a list (row 20) but 404s on a direct fetch (row 21).    ║
     * ║ THAT SYMMETRY DOES NOT HOLD HERE. backend/ExamService::find() calls   ║
     * ║ ExamRepository::findById(), whose SQL is                              ║
     * ║   SELECT * FROM exams WHERE id = :id LIMIT 1                          ║
     * ║ — it does not join `content_items` at all and therefore CANNOT see    ║
     * ║ `is_hidden`. Row 89 has never 404'd on a hidden exam for anybody.     ║
     * ║                                                                      ║
     * ║ Ported as-is rather than "made consistent", because tightening it is  ║
     * ║ a product decision with a real victim: an exam a student has already  ║
     * ║ opened, hidden mid-term by the instructor to stop NEW starts, would   ║
     * ║ begin 404ing for the student holding its id. Recorded in              ║
     * ║ docs/open-decisions.md; pinned by ExamPublicReadTest so that a later  ║
     * ║ "tidy-up" is a deliberate act.                                        ║
     * ║                                                                      ║
     * ║ It is not a hole: the id is an opaque UUIDv7 that the filtered list   ║
     * ║ never disclosed, and the PAYLOAD is still governed by the three-way   ║
     * ║ split, so a guest holding one learns the title and the duration.      ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function show(Request $request, string $examId): JsonResponse
    {
        $exam = AssessmentAccess::findExamOrFail($examId);

        // 404 'Exam not found' — the id names nothing the caller may know
        // about: an orphan (soft-deleted course) or a draft course's exam.
        AssessmentAccess::assertExamVisible($request->user(), $exam);

        return ApiResponse::success([
            'exam' => ExamResourceFactory::for($request->user(), $exam),
        ]);
    }

    // ══ STAGE 6 / SESSION B — THE AUTHENTICATED HALF ═════════════════════

    /**
     * GET /api/v1/exams/{examId}/questions — `auth`. Row 90.
     *
     * The paper itself. Port of backend/ExamService::questionsFor().
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE ANSWER KEY IS DECIDED BY **WHICH RESOURCE CLASS** IS INSTANTIATED,║
     * ║ NOT BY UNSETTING A FIELD.                                            ║
     * ║                                                                      ║
     * ║ backend/ builds the full row and then does                            ║
     * ║   array_map(fn ($q) => { unset($q['correct_answer']); return $q; })   ║
     * ║ — a SUBTRACTIVE design, and subtractive designs fail OPEN: the next   ║
     * ║ call site that forgets the unset ships the key. Here                  ║
     * ║ QuestionResource has no line of code capable of reading that column,  ║
     * ║ so a student's shape is safe by construction rather than by care.     ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * COURSE STAFF DO GET THE KEY, and that is deliberate and ported: this
     * route is the instructor's read-only exam preview, and a preview that
     * hides the answers is useless. assertExamAccessible() has already 403'd
     * any OTHER instructor. Nothing on this path writes `exam_attempts`.
     *
     * The gate for the key is `viewStaffDetail` (course staff), NOT
     * `viewAnswerKey` — the latter also opens for a student when
     * `show_answers = 1`, which is a RESULTS-page reveal and must not turn the
     * live paper into an open book. Two abilities, two questions; backend/
     * uses isCourseStaff() here and $reveal on results, exactly this split.
     *
     * AN EXAM WITH NO BANK RETURNS `[]`, not a 404 — `fk_exams_question_group`
     * is ON DELETE SET NULL, so a detached exam is a normal state.
     */
    public function questions(Request $request, string $examId): JsonResponse
    {
        $exam = AssessmentAccess::findExamOrFail($examId);

        AssessmentAccess::assertExamAccessible($request->user(), $exam);

        $questions = self::bankOf($exam) ?? new Collection();

        $isStaff = Gate::forUser($request->user())->allows('viewStaffDetail', $exam);

        return ApiResponse::success([
            'questions' => $isStaff
                ? StaffQuestionResource::collection($questions)
                : QuestionResource::collection($questions),
        ]);
    }

    /**
     * GET /api/v1/exams/{examId}/results — `auth`. Row 91.
     *
     * Port of backend/ExamService::resultsFor().
     *
     * ┌── `?student_id=` IS RESOLVED **HERE**, THEN HANDED TO THE POLICY. ───┐
     * │ backend/'s signature is `?string $studentId = null` meaning "default  │
     * │ to me". That defaulting is a CONTROLLER concern, which is why         │
     * │ ExamPolicy::viewResultsOf takes a plain `string` third parameter and  │
     * │ never reads the request — Stage 3's rule that a policy body is a pure │
     * │ function of what it is given.                                         │
     * │                                                                      │
     * │ An EMPTY `?student_id=` means "me" too, matching backend/'s           │
     * │ `$studentId !== ''` guard. Without that, a client that always appends │
     * │ the parameter and sometimes leaves it blank would 403 itself.         │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * THREE GATES, IN THIS ORDER, AND THE ORDER IS THE CONTRACT:
     *   1. 403 — may you see this exam at all?      assertExamAccessible
     *   2. 403 'You may only view your own results' assertResultsViewable
     *   3. 404 'No attempt found for this exam'     the row itself
     *
     * Rung 3 last means STAFF asking about a student who never sat the exam get
     * a 404, not a 403 — the ported behaviour, and the reason
     * ExamPolicy::viewResultsOf deliberately does not read `exam_attempts`.
     */
    public function results(Request $request, string $examId): JsonResponse
    {
        $exam = AssessmentAccess::findExamOrFail($examId);

        AssessmentAccess::assertExamAccessible($request->user(), $exam);

        $user = $request->user();
        $requested = trim((string) $request->query('student_id', ''));
        $targetStudentId = $requested !== '' ? $requested : (string) $user->getKey();

        AssessmentAccess::assertResultsViewable($user, $exam, $targetStudentId);

        $attempt = ExamAttempt::latestFor((string) $exam->getKey(), $targetStudentId)
            ?? throw new NotFoundException(AssessmentAccess::NO_ATTEMPT);

        return ApiResponse::success([
            // The bank is read HERE and handed in — a Resource that issues its
            // own queries hides its cost, and keeping the `question_group_id`
            // literal out of app/Http/Resources keeps that directory's
            // "only two classes may name the bank id" grep blunt.
            'result' => ExamResultResourceFactory::for($user, $exam, $attempt, self::bankOf($exam)),
        ]);
    }

    /**
     * POST /api/v1/exams/{examId}/submit — `rl:submit` + `auth`. Row 92.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THIS CONTROLLER METHOD IS DELIBERATELY THIN. EVERY DECISION THAT     ║
     * ║ MATTERS — the student check, the scoring, the row lock, the count,   ║
     * ║ the ordinal, the INSERT — LIVES IN App\Actions\Exam\SubmitExamAttempt║
     * ║ SO THAT THERE IS EXACTLY ONE WRITER OF `exam_attempts` AND EXACTLY   ║
     * ║ ONE PLACE TO AUDIT THE LOCKING. Do not inline any of it back.        ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ┌── `answers` IS TAKEN VERBATIM, WITH NO VALIDATION RULE. PORTED. ─────┐
     * │ backend/ExamController::submit() does `$request->all()['answers'] ??  │
     * │ []` and nothing else — a non-array value falls through its foreach as │
     * │ a warning and scores nothing. Adding `array` here would turn that     │
     * │ silent zero into a 422, i.e. change the response to a request that    │
     * │ succeeds today. The coercion below reproduces the outcome without the │
     * │ warning; ScoreExamAttempt then ignores any entry it cannot read.      │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * 200, NOT 201 — and it is asymmetric with the assignment submit, which is
     * a 201. That asymmetry is backend/'s (ApiResponse::success here,
     * ApiResponse::created there) and is preserved rather than tidied: both
     * shapes are already in a shipped client.
     *
     * The payload is the SUBMIT summary, not the results payload: score,
     * earned/total points and pass/fail. Row 91 is where the answer key and the
     * `show_answers` decision live.
     */
    public function submit(Request $request, string $examId): JsonResponse
    {
        $exam = AssessmentAccess::findExamOrFail($examId);

        // COURSE access only. The "are you a real student" refusal is NOT here
        // — it is the first statement of the Action, which is the one layer
        // `Gate::before` cannot bypass. See that class's escape-hatch banner.
        AssessmentAccess::assertExamAccessible($request->user(), $exam);

        $answers = $request->input('answers');
        $answers = is_array($answers) ? $answers : [];

        ['attempt' => $attempt, 'scored' => $scored] = app(SubmitExamAttempt::class)(
            $request->user(),
            $exam,
            $answers
        );

        $passScore = $exam->getAttribute('pass_score');

        return ApiResponse::success([
            'attempt_id' => $attempt->getKey(),
            'score' => $scored->score,
            'earned_points' => $scored->earnedPoints,
            'total_points' => $scored->totalPoints,
            // TRI-STATE, and the null is load-bearing: an exam with no pass
            // mark has not been passed OR failed, and a client rendering
            // "راسب" for false must not render it for "no mark set".
            'passed' => $passScore === null ? null : $scored->score >= (float) $passScore,
        ], 'تم تسليم الامتحان بنجاح');
    }

    /**
     * This exam's bank in `id` order — or NULL when the exam has no bank.
     *
     * ┌── NULL AND AN EMPTY COLLECTION MEAN DIFFERENT THINGS. ───────────────┐
     * │ NULL = `question_group_id IS NULL` (the exam is detached — normal,   │
     * │ because `fk_exams_question_group` is ON DELETE SET NULL).            │
     * │ EMPTY = a real bank that currently has no questions.                  │
     * │                                                                      │
     * │ Row 90 treats them alike (both serialise to `[]`), but row 91 does   │
     * │ NOT: backend/'s `$reveal && $exam['question_group_id']` omits         │
     * │ `correct_answers` in the first case and emits `[]` in the second.    │
     * │ Collapsing them here would silently change one of those.              │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * `ORDER BY id` — a UUIDv7, i.e. creation order — matching
     * QuestionGroup::questions() and backend/QuestionRepository::listByGroup().
     *
     * @return Collection<int, Question>|null
     */
    private static function bankOf(Exam $exam): ?Collection
    {
        $groupId = $exam->getAttribute('question_group_id');

        if ($groupId === null) {
            return null;
        }

        return Question::query()
            ->where('question_group_id', $groupId)
            ->orderBy('id')
            ->get();
    }
}
