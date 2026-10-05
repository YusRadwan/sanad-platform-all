<?php

declare(strict_types=1);

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ENROLLMENT-CODE SINGLE-USE RACE — STAGE 7 / SESSION A.              ║
 * ║                                                                          ║
 * ║   php tests/Concurrency/enrollment_code_race.php          # both modes   ║
 * ║   php tests/Concurrency/enrollment_code_race.php fixed    # guarded only ║
 * ║   php tests/Concurrency/enrollment_code_race.php naive    # control only ║
 * ║                                                                          ║
 * ║ 8 genuinely parallel OS processes redeem THE SAME CODE, each as a        ║
 * ║ DIFFERENT student, inside a sub-millisecond window.                      ║
 * ║                                                                          ║
 * ║   fixed  -> EXACTLY 1 subscription and EXACTLY 1 revenue transaction.    ║
 * ║             Not fewer: a code that nobody could spend is as broken as    ║
 * ║             one everybody could.                                         ║
 * ║   naive  -> MORE than 1, or the harness is not creating concurrency and  ║
 * ║             the `fixed` run proved nothing.                              ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Read tests/Concurrency/bootstrap.php first — it carries the argument for why
 * PHPUnit and curl cannot test this, and the database safety guard.
 *
 * ┌── EIGHT **DIFFERENT** STUDENTS, AND THAT IS THE WHOLE FIXTURE DESIGN. ───┐
 * │ One student racing themselves would be a worthless test: UNIQUE KEY      │
 * │ uq_subscription_student_course would reject the seven duplicates all by  │
 * │ itself and a completely unguarded implementation would "pass". The code  │
 * │ is the only shared resource, so the only thing that can produce exactly  │
 * │ one winner is the guarded UPDATE on `enrollment_codes`.                  │
 * │                                                                          │
 * │ This mirrors exam_attempt_race.php's decision to give each naive worker  │
 * │ a distinct `attempt_number`: in both cases the fixture is arranged so    │
 * │ that MySQL cannot rescue a broken application.                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── WHAT `naive` REPRODUCES, EXACTLY. ─────────────────────────────────────┐
 * │ The read-then-write shape this session exists to avoid: SELECT is_used,  │
 * │ branch on it in PHP, then UPDATE by PRIMARY KEY ONLY — no `AND is_used   │
 * │ = 0`, no affected-row check — followed by the same subscription and      │
 * │ revenue writes the real path performs. It is deliberately wrapped in a   │
 * │ transaction, so the control cannot be dismissed as "it just wasn't       │
 * │ transactional": a transaction is not a lock, and that is the point.      │
 * └──────────────────────────────────────────────────────────────────────────┘
 */

use App\Actions\Enrollment\RedeemEnrollmentCode;
use App\Actions\Revenue\RecordRevenueTransaction;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\EnrollmentCode;
use App\Models\RevenueTransaction;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/bootstrap.php';

/** The course price. Non-zero, or no revenue row would ever be written. */
const RACE_PRICE = 100.00;

// ───────────────────────────────────────────────────────────── CHILD ROLE ──
if (($argv[1] ?? '') === '--child') {
    [$mode, $startAt, $index] = [$argv[2], (float) $argv[3], (int) $argv[4]];
    $codeValue = $argv[5];
    $studentId = $argv[6 + $index];

    raceBoot();
    raceWaitForBarrier($startAt);

    try {
        $student = User::query()->findOrFail($studentId);

        if ($mode === 'naive') {
            // ── THE CONTROL. Deliberately unguarded: read, branch, blind write.
            $row = DB::table('enrollment_codes')->where('code', $codeValue)->first();

            if ($row === null || (int) $row->is_used === 1) {
                fwrite(STDOUT, 'REJECTED (already used)');
                exit(0);
            }

            DB::transaction(function () use ($row, $student): void {
                // NO `AND is_used = 0`, and the affected-row count is ignored.
                DB::table('enrollment_codes')->where('id', $row->id)->update([
                    'is_used' => 1,
                    'used_by_student_id' => $student->getKey(),
                    'used_at' => now(),
                ]);

                $subscription = Subscription::query()->create([
                    'student_id' => $student->getKey(),
                    'course_id' => $row->course_id,
                    'status' => SubscriptionStatus::Active,
                    'expires_at' => null,
                ]);

                app(RecordRevenueTransaction::class)([
                    'subscription_id' => $subscription->getKey(),
                    'student_id' => $student->getKey(),
                    'course_id' => $row->course_id,
                    'teacher_id' => DB::table('courses')->where('id', $row->course_id)->value('teacher_id'),
                    'gross_amount' => RACE_PRICE,
                    'payment_method' => RevenueTransaction::METHOD_ENROLLMENT_CODE,
                ]);
            });

            fwrite(STDOUT, 'ACCEPTED (naive)');
            exit(0);
        }

        // ── THE REAL PRODUCTION PATH, through the container, exactly as
        //    App\Http\Controllers\EnrollmentCodeController::redeem() invokes it.
        $result = app(RedeemEnrollmentCode::class)($codeValue, $student);

        fwrite(STDOUT, 'ACCEPTED course=' . $result['course_id']);
    } catch (Throwable $e) {
        fwrite(STDOUT, 'REJECTED (' . class_basename($e) . ': ' . str_replace("\n", ' ', $e->getMessage()) . ')');
    }

    exit(0);
}

// ──────────────────────────────────────────────────────────── PARENT ROLE ──
raceBoot();

$modes = isset($argv[1]) ? [$argv[1]] : ['fixed', 'naive'];
$ids = codeRaceSeed();
$failures = [];

try {
    foreach ($modes as $mode) {
        // A FRESH, UNUSED code for each mode — the second run must not inherit
        // the first run's spent one, which would make it trivially "safe".
        codeRaceReset($ids);

        echo str_repeat('=', 78), "\n";
        echo "mode                : {$mode}\n";
        echo "code                : {$ids['code']}\n";
        echo 'concurrent workers  : ', RACE_WORKERS, " (8 DIFFERENT students)\n";
        echo 'course price        : ', number_format(RACE_PRICE, 2), "\n\n";

        $args = array_merge([$ids['code']], $ids['students']);

        foreach (raceRun(__FILE__, $mode, $args) as $line) {
            echo '  ', $line, "\n";
        }

        $subscriptions = Subscription::query()->where('course_id', $ids['course'])->count();
        $transactions = RevenueTransaction::query()->where('course_id', $ids['course'])->count();
        $claimed = (int) DB::table('enrollment_codes')->where('code', $ids['code'])->value('is_used');

        echo "\nSELECT COUNT(*) FROM subscriptions        WHERE course_id=… -> {$subscriptions}\n";
        echo "SELECT COUNT(*) FROM revenue_transactions WHERE course_id=… -> {$transactions}\n";
        echo "SELECT is_used   FROM enrollment_codes    WHERE code=…      -> {$claimed}\n";

        /*
         * ╔══════════════════════════════════════════════════════════════════╗
         * ║ THE TWO VERDICTS ARE OPPOSITE, AND BOTH ARE REQUIRED.            ║
         * ║                                                                  ║
         * ║ `fixed` must be EXACTLY 1 of each. `<= 1` would also pass for an ║
         * ║ implementation that lost every redemption — a different bug with ║
         * ║ a friendlier shape, not a correct outcome.                        ║
         * ║                                                                  ║
         * ║ `naive` must EXCEED 1. A well-behaved control means the workers  ║
         * ║ never overlapped, so the `fixed` result is meaningless and this  ║
         * ║ run is a FAILURE.                                                 ║
         * ╚══════════════════════════════════════════════════════════════════╝
         */
        if ($mode === 'fixed') {
            $ok = $subscriptions === 1 && $transactions === 1 && $claimed === 1;
            echo $ok
                ? "RESULT: PASS — exactly one student enrolled and exactly one sale recorded\n"
                : "RESULT: FAIL — expected 1/1/1, got {$subscriptions}/{$transactions}/{$claimed}\n";
        } else {
            $ok = $subscriptions > 1 || $transactions > 1;
            echo $ok
                ? "RESULT: PASS (control behaved as designed) — the unguarded path let\n"
                . "        {$subscriptions} students in and recorded {$transactions} sales off ONE code,\n"
                . "        which proves the harness really does create concurrent writers.\n"
                : "RESULT: FAIL — the UNGUARDED control produced {$subscriptions}/{$transactions}.\n"
                . "        The workers did not overlap, so the `fixed` run proves NOTHING.\n";
        }

        if (!$ok) {
            $failures[] = $mode;
        }

        echo "\n";
    }
} finally {
    codeRaceTeardown($ids);
    echo "fixtures torn down.\n";
}

echo str_repeat('=', 78), "\n";
echo $failures === []
    ? "OVERALL: PASS\n"
    : 'OVERALL: FAIL (' . implode(', ', $failures) . ")\n";

exit($failures === [] ? 0 : 1);

// ─────────────────────────────────────────────────────────────── FIXTURES ──

/**
 * One teacher, one paid course, one code, and RACE_WORKERS students.
 *
 * Built through the same models the application uses, so the fixture cannot
 * express a state the application could not reach.
 *
 * @return array{teacher: string, course: string, code: string, students: list<string>}
 */
function codeRaceSeed(): array
{
    $teacher = raceUser(UserRole::Instructor);
    Teacher::query()->create(['user_id' => $teacher->getKey(), 'grades' => [], 'subjects' => []]);

    $course = Course::query()->create([
        'title' => 'RACE code ' . bin2hex(random_bytes(4)),
        'price' => RACE_PRICE,
        'teacher_id' => $teacher->getKey(),
        'is_published' => true,
    ]);

    $students = [];

    for ($i = 0; $i < RACE_WORKERS; $i++) {
        $student = raceUser(UserRole::Student);
        Student::query()->create([
            'user_id' => $student->getKey(),
            'student_code' => 'RC' . bin2hex(random_bytes(5)),
            'phone' => '01012345678',
            'parent_phone' => '01087654321',
            'governorate' => 'القاهرة',
            'gender' => 'male',
            'education_type' => 'general',
            'grade_level' => 'الصف الأول الثانوي',
        ]);
        $students[] = (string) $student->getKey();
    }

    return [
        'teacher' => (string) $teacher->getKey(),
        'course' => (string) $course->getKey(),
        'code' => codeRaceIssue((string) $course->getKey()),
        'students' => $students,
    ];
}

/** Wipes the previous mode's effects and re-issues a fresh unused code. */
function codeRaceReset(array &$ids): void
{
    DB::table('revenue_transactions')->where('course_id', $ids['course'])->delete();
    DB::table('subscriptions')->where('course_id', $ids['course'])->delete();
    DB::table('enrollment_codes')->where('course_id', $ids['course'])->delete();

    $ids['code'] = codeRaceIssue($ids['course']);
}

/** A fresh unused 8-digit code for the race course. */
function codeRaceIssue(string $courseId): string
{
    $code = EnrollmentCode::query()->create([
        'code' => (string) random_int(10000000, 99999999),
        'course_id' => $courseId,
        'created_by' => null,
        'is_used' => false,
    ]);

    return (string) $code->code;
}

/**
 * Delete every row the harness created, deepest first.
 *
 * `courses` is SOFT-deleted by the model, so it goes through the query builder
 * — a trashed fixture row would keep its codes and subscriptions alive and
 * accumulate on every run.
 */
function codeRaceTeardown(array $ids): void
{
    DB::table('revenue_transactions')->where('course_id', $ids['course'])->delete();
    DB::table('subscriptions')->where('course_id', $ids['course'])->delete();
    DB::table('enrollment_codes')->where('course_id', $ids['course'])->delete();
    DB::table('courses')->where('id', $ids['course'])->delete();

    DB::table('students')->whereIn('user_id', $ids['students'])->delete();
    DB::table('teachers')->where('user_id', $ids['teacher'])->delete();
    DB::table('users')->whereIn('id', array_merge($ids['students'], [$ids['teacher']]))->delete();
}
