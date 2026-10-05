<?php

declare(strict_types=1);

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE EXAM ATTEMPT-CAP RACE — STAGE 6 / SESSION B.                        ║
 * ║                                                                          ║
 * ║   php tests/Concurrency/exam_attempt_race.php          # both modes      ║
 * ║   php tests/Concurrency/exam_attempt_race.php fixed    # locked only     ║
 * ║   php tests/Concurrency/exam_attempt_race.php naive    # control only    ║
 * ║                                                                          ║
 * ║ 8 genuinely parallel OS processes submit to ONE exam with                ║
 * ║ allowed_attempts = 2, for ONE student, inside a sub-millisecond window.  ║
 * ║                                                                          ║
 * ║   fixed  -> COUNT(*) must be EXACTLY 2. Not fewer (a lost submission is  ║
 * ║             as much a bug as an extra one), not more.                    ║
 * ║   naive  -> COUNT(*) must EXCEED 2, or the harness is not creating       ║
 * ║             concurrency and the `fixed` run proved nothing.              ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Read tests/Concurrency/bootstrap.php first — it carries the argument for why
 * PHPUnit and curl cannot test this, and the database safety guard.
 *
 * ┌── WHAT `naive` REPRODUCES, EXACTLY. ─────────────────────────────────────┐
 * │ The pre-fix legacy sequence: COUNT(*) outside any transaction, then an   │
 * │ INSERT. It writes a DISTINCT `attempt_number` per worker (100 + index)   │
 * │ deliberately, so `uq_exam_attempts_ordinal` cannot mask the              │
 * │ APPLICATION-level race — the control has to demonstrate that the         │
 * │ count-then-insert is unsafe on its own, not that MySQL saves it.         │
 * └──────────────────────────────────────────────────────────────────────────┘
 */

use App\Actions\Exam\SubmitExamAttempt;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__ . '/bootstrap.php';

const CAP = 2;

// ───────────────────────────────────────────────────────────── CHILD ROLE ──
if (($argv[1] ?? '') === '--child') {
    [$mode, $startAt, $index, $examId, $studentId, $questionId] =
        [$argv[2], (float) $argv[3], (int) $argv[4], $argv[5], $argv[6], $argv[7]];

    raceBoot();
    raceWaitForBarrier($startAt);

    try {
        if ($mode === 'naive') {
            // ── THE CONTROL. Deliberately vulnerable: no transaction, no lock.
            $allowed = DB::table('exams')->where('id', $examId)->value('allowed_attempts');

            $used = DB::table('exam_attempts')
                ->where('exam_id', $examId)
                ->where('student_id', $studentId)
                ->count();

            if ($allowed !== null && $used >= (int) $allowed) {
                fwrite(STDOUT, 'REJECTED (cap)');
                exit(0);
            }

            DB::table('exam_attempts')->insert([
                'id' => (string) Str::uuid7(),
                'exam_id' => $examId,
                'student_id' => $studentId,
                // A distinct ordinal per worker, so the UNIQUE key cannot hide
                // the application-level race. See the file banner.
                'attempt_number' => 100 + $index,
                'answers' => '[]',
                'score' => 100,
                'started_at' => now(),
                'submitted_at' => now(),
            ]);

            fwrite(STDOUT, 'INSERTED (naive)');
            exit(0);
        }

        // ── THE REAL PRODUCTION PATH, through the container, exactly as
        //    App\Http\Controllers\ExamController::submit() invokes it.
        $user = User::query()->findOrFail($studentId);
        $exam = Exam::query()->findOrFail($examId);

        ['attempt' => $attempt] = app(SubmitExamAttempt::class)(
            $user,
            $exam,
            [['questionId' => $questionId, 'answer' => '4']]
        );

        fwrite(STDOUT, 'ACCEPTED attempt_number=' . $attempt->getAttribute('attempt_number'));
    } catch (Throwable $e) {
        fwrite(STDOUT, 'REJECTED (' . class_basename($e) . ': ' . str_replace("\n", ' ', $e->getMessage()) . ')');
    }

    exit(0);
}

// ──────────────────────────────────────────────────────────── PARENT ROLE ──
raceBoot();

$modes = isset($argv[1]) ? [$argv[1]] : ['fixed', 'naive'];
$ids = raceSeed(CAP);
$failures = [];

try {
    foreach ($modes as $mode) {
        DB::table('exam_attempts')->where('exam_id', $ids['exam'])->delete();

        echo str_repeat('=', 78), "\n";
        echo "mode               : {$mode}\n";
        echo 'allowed_attempts   : ', CAP, "\n";
        echo 'concurrent workers : ', RACE_WORKERS, "\n";
        echo "attempts before    : 0\n\n";

        foreach (raceRun(__FILE__, $mode, [$ids['exam'], $ids['student'], $ids['question']]) as $line) {
            echo '  ', $line, "\n";
        }

        $count = ExamAttempt::query()->ofStudent($ids['exam'], $ids['student'])->count();

        echo "\nSELECT COUNT(*) FROM exam_attempts WHERE exam_id=… AND student_id=… -> {$count}\n";

        /*
         * ╔══════════════════════════════════════════════════════════════════╗
         * ║ THE TWO VERDICTS ARE OPPOSITE, AND BOTH ARE REQUIRED.            ║
         * ║                                                                  ║
         * ║ `fixed` must land on the cap EXACTLY: `<= CAP` would also pass    ║
         * ║ for an implementation that lost every submission, which is not a  ║
         * ║ correct outcome — it is a different bug with a friendlier shape.  ║
         * ║                                                                  ║
         * ║ `naive` must BREACH the cap. A well-behaved control means the     ║
         * ║ harness failed to overlap the workers, so the `fixed` result is   ║
         * ║ meaningless and this run must be treated as a FAILURE.            ║
         * ╚══════════════════════════════════════════════════════════════════╝
         */
        if ($mode === 'fixed') {
            $ok = $count === CAP;
            echo $ok
                ? "RESULT: PASS — exactly {$count} attempt(s) recorded under a cap of " . CAP . "\n"
                : "RESULT: FAIL — expected exactly " . CAP . ", got {$count}\n";
        } else {
            $ok = $count > CAP;
            echo $ok
                ? "RESULT: PASS (control behaved as designed) — the unlocked path recorded {$count} > " . CAP . ",\n"
                . "        which proves the harness really does create concurrent writers.\n"
                : "RESULT: FAIL — the UNLOCKED control respected the cap ({$count} <= " . CAP . ").\n"
                . "        The workers did not overlap, so the `fixed` run proves NOTHING.\n";
        }

        if (!$ok) {
            $failures[] = $mode;
        }

        echo "\n";
    }
} finally {
    // Always, even on a fatal: these are real rows in a real database.
    DB::table('exam_attempts')->where('exam_id', $ids['exam'])->delete();
    raceTeardown($ids);
    echo "fixtures torn down.\n";
}

echo str_repeat('=', 78), "\n";
echo $failures === []
    ? "OVERALL: PASS\n"
    : 'OVERALL: FAIL (' . implode(', ', $failures) . ")\n";

exit($failures === [] ? 0 : 1);
