<?php

declare(strict_types=1);

namespace App\Actions\Exam;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\Student;
use App\Models\User;
use App\Support\AssessmentAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/exams/{examId}/submit — freeze-table row 92.
 *
 * Port of backend/ExamService::submit() (~lines 114-186).
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE MOST CONCURRENCY-CRITICAL CLASS IN THE APPLICATION.                  ║
 * ║ THE ONLY CLASS THAT INSERTS INTO `exam_attempts`.                        ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── LAYER 1 — ONE TRANSACTION AROUND THE CHECK **AND** THE INSERT. ────────┐
 * │ The pre-fix legacy did `SELECT COUNT(*)` and then, several statements    │
 * │ later and outside any transaction, an INSERT. Textbook TOCTOU: N racing  │
 * │ submissions all read 0, all pass a cap of 1, all insert.                 │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ LAYER 2 — LOCK THE **PARENT** `exams` ROW, NEVER THE `exam_attempts`    ║
 * ║ ROWS. THIS IS THE ONE "OBVIOUS" WRONG ANSWER.                           ║
 * ║                                                                          ║
 * ║ If you find yourself writing                                             ║
 * ║     ExamAttempt::where(...)->lockForUpdate()->count()                    ║
 * ║ STOP. That is the bug, and it passes every single-threaded test.         ║
 * ║                                                                          ║
 * ║ WHY: a student's FIRST attempt has NO `exam_attempts` row. In InnoDB's   ║
 * ║ REPEATABLE READ, `SELECT … FOR UPDATE` over an empty result set places   ║
 * ║ no record lock, so two concurrent first submissions would both "take     ║
 * ║ the lock", both count 0, and both insert — a cap of 1 yielding 2 rows.   ║
 * ║ The `exams` row ALWAYS exists, so locking it serialises every concurrent ║
 * ║ submitter for that exam and the second one counts a state that already   ║
 * ║ contains the first.                                                      ║
 * ║                                                                          ║
 * ║ PROVEN, NOT ASSERTED: tests/Concurrency/exam_attempt_race.php runs 8     ║
 * ║ genuinely parallel OS processes against this code path AND against a     ║
 * ║ deliberately unlocked control. The control must EXCEED the cap; if it    ║
 * ║ does not, the harness is not creating real concurrency and a green run   ║
 * ║ here proves nothing.                                                     ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── LAYER 3 — `attempt_number` UNDER `uq_exam_attempts_ordinal`. ──────────┐
 * │ The ordinal is COUNT(*)+1 computed inside the same locked transaction,   │
 * │ so two writers that somehow both got past layers 1-2 compute the SAME    │
 * │ number and MySQL rejects the second itself. Defence in depth BELOW the   │
 * │ application — the one layer a future code path cannot forget.            │
 * │ (`assignment_submissions` has no such key: open decision 6.11.)          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `DB::transaction($closure)` — ONE ARGUMENT. **NEVER** PASS AN ATTEMPT    ║
 * ║ COUNT.                                                                   ║
 * ║                                                                          ║
 * ║ `DB::transaction($closure, 3)` makes Laravel re-run the WHOLE closure on ║
 * ║ a deadlock. The closure's last statement is an INSERT; a retry after a   ║
 * ║ deadlock that struck late could run the count+insert a second time and   ║
 * ║ write a second attempt for a cap of one. A retry loop is a correctness   ║
 * ║ hazard on a body that is not idempotent, and this body is not.           ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── SCORING RUNS **BEFORE** THE TRANSACTION OPENS. ────────────────────────┐
 * │ backend/'s own reasoning, preserved: grading is pure computation over    │
 * │ rows already read, so keeping it out of the critical section holds the   │
 * │ `exams` row for the shortest possible time. Every other student          │
 * │ submitting the same exam is blocked for exactly that window.             │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class SubmitExamAttempt
{
    /** backend/'s literal Arabic text. Part of the API contract. */
    public const ATTEMPTS_EXHAUSTED = 'لقد استنفدت عدد المحاولات المسموح بها لهذا الامتحان';

    /**
     * @param  array<int|string, mixed>  $answers  the submitted payload, verbatim
     * @return array{attempt: ExamAttempt, scored: ScoreExamAttempt}
     */
    public function __invoke(User $user, Exam $exam, array $answers): array
    {
        /*
         * ╔══════════════════════════════════════════════════════════════════╗
         * ║ ESCAPE HATCH FOR `Gate::before` — THE RESOLUTION OF OPEN         ║
         * ║ DECISION 3.1 / 6.7, AND THE REASON `ExamPolicy::submit` NO       ║
         * ║ LONGER EXISTS.                                                    ║
         * ║                                                                   ║
         * ║ THE MECHANISM, IN ORDER:                                          ║
         * ║  1. `exam_attempts.student_id` is an FK into `students.user_id`.  ║
         * ║  2. An admin and an instructor have no `students` row, and cannot ║
         * ║     have one — the role decides whether the profile exists.       ║
         * ║  3. `Gate::before` returns true for an admin BEFORE any policy    ║
         * ║     body is entered, and the OWNING INSTRUCTOR passes course      ║
         * ║     access legitimately, on the merits, previewing their own      ║
         * ║     exam. AssessmentAccess::assertExamAccessible() therefore lets ║
         * ║     BOTH through — deliberately; see its banner for why "may you  ║
         * ║     see this material" and "may you record an attempt" must stay  ║
         * ║     two questions.                                                ║
         * ║  4. Both consequently arrive HERE fully authorised, and an INSERT ║
         * ║     for either dies on the FK: a raw 500 where the contract owes  ║
         * ║     a 403.                                                        ║
         * ║                                                                   ║
         * ║ So the refusal lives in the ACTION THAT PERFORMS THE WRITE — the  ║
         * ║ one layer the Gate bypass does not cover. It is FORBIDDEN to move ║
         * ║ it into a Policy body (never runs for an admin) or into           ║
         * ║ `Gate::before` (a second authorization rule in the one place      ║
         * ║ reserved for exactly one). Same placement as, and the third       ║
         * ║ instance of, App\Actions\Lesson\SaveLessonProgress's hatch —      ║
         * ║ different answer: a 403 here, a silent 200 no-op there.           ║
         * ║                                                                   ║
         * ║ The check is on the `students` TABLE, not on `$user->role`.       ║
         * ║ The FK is what the database enforces, so the FK is what the       ║
         * ║ application must ask about — a Student-role user whose profile    ║
         * ║ row was deleted would otherwise still reach the INSERT.           ║
         * ╚══════════════════════════════════════════════════════════════════╝
         */
        if (!Student::query()->whereKey($user->getKey())->exists()) {
            throw new ForbiddenException(AssessmentAccess::NOT_A_STUDENT_EXAM);
        }

        // Pure computation, deliberately outside the critical section.
        $scored = ScoreExamAttempt::for($this->questionsOf($exam), $answers);

        $examId = (string) $exam->getKey();
        $studentId = (string) $user->getKey();

        $attempt = DB::transaction(function () use ($examId, $studentId, $answers, $scored): ExamAttempt {
            // ── LAYER 2. The PARENT row. Read the class banner before editing.
            $locked = Exam::query()->whereKey($examId)->lockForUpdate()->first();

            if ($locked === null) {
                // The exam was deleted between this request's read and the
                // lock. backend/ answers 404 here; so does this.
                throw new NotFoundException(AssessmentAccess::EXAM_NOT_FOUND);
            }

            // Counted UNDER the lock, so it already includes any attempt a
            // concurrent submitter committed while we were blocked.
            $used = ExamAttempt::query()->ofStudent($examId, $studentId)->count();

            self::assertAttemptsRemaining($locked->getAttribute('allowed_attempts'), $used);

            $attempt = new ExamAttempt();

            // forceFill, not create(): every value below comes from the
            // session, the route or the server — never from a request key.
            // See App\Models\ExamAttempt's "no $fillable" banner.
            $attempt->forceFill([
                'exam_id' => $examId,
                'student_id' => $studentId,
                // ── LAYER 3. COUNT(*)+1, computed under the same lock.
                'attempt_number' => $used + 1,
                'answers' => $answers,
                'score' => $scored->score,
                'started_at' => now(),
                'submitted_at' => now(),
            ])->save();

            return $attempt;
        });

        return ['attempt' => $attempt, 'scored' => $scored];
    }

    /**
     * This exam's bank, `ORDER BY id` — the ordering
     * QuestionGroup::questions() already pins and backend/QuestionRepository
     * ::listByGroup() used.
     *
     * `question_group_id` is NULLABLE (`fk_exams_question_group` is ON DELETE
     * SET NULL, so deleting a bank detaches every exam pointing at it). A
     * detached exam is not an error — it scores 0.00 out of 0 total points, via
     * ScoreExamAttempt's explicit zero guard.
     *
     * @return Collection<int, Question>
     */
    private function questionsOf(Exam $exam): Collection
    {
        $groupId = $exam->getAttribute('question_group_id');

        if ($groupId === null) {
            return new Collection();
        }

        return Question::query()
            ->where('question_group_id', $groupId)
            ->orderBy('id')
            ->get();
    }

    /**
     * `exams.allowed_attempts` is INT NULL, and NULL MEANS UNLIMITED BY
     * DESIGN — the instructor form leaves it empty. So does 0 and any
     * non-positive value, matching backend/'s `(int) $allowed <= 0` guard
     * exactly rather than treating 0 as "no attempts allowed".
     *
     * ┌── `$used` IS PASSED IN, NEVER RE-COUNTED HERE. ──────────────────────┐
     * │ The count must be the one taken inside the locked transaction.       │
     * │ Counting again in this method would issue a second, UNLOCKED read    │
     * │ and reintroduce precisely the race the lock exists to close — and it │
     * │ would look perfectly innocent doing it.                              │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * A 422 (not a 403): the cap is a state of the data, not of the caller's
     * identity, and backend/ raises ValidationException here. The field key is
     * `attempts`, which is what the client renders against.
     */
    private static function assertAttemptsRemaining(mixed $allowed, int $used): void
    {
        if ($allowed === null || $allowed === '' || (int) $allowed <= 0) {
            return;
        }

        if ($used >= (int) $allowed) {
            throw ValidationException::withMessages(['attempts' => [self::ATTEMPTS_EXHAUSTED]]);
        }
    }
}
