<?php

declare(strict_types=1);

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ADMIN MANUAL-GRANT RACE — STAGE 7 / SESSION A.                      ║
 * ║                                                                          ║
 * ║   php tests/Concurrency/enrollment_toggle_race.php          # both       ║
 * ║   php tests/Concurrency/enrollment_toggle_race.php fixed                 ║
 * ║   php tests/Concurrency/enrollment_toggle_race.php naive                 ║
 * ║                                                                          ║
 * ║ 8 genuinely parallel OS processes grant THE SAME (student, course) pair. ║
 * ║                                                                          ║
 * ║   fixed  -> EXACTLY 1 subscription and EXACTLY 1 revenue transaction.    ║
 * ║   naive  -> MORE than 1 revenue transaction (see below for why the       ║
 * ║             SUBSCRIPTION count is not the interesting number here).      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── THIS CONTROL IS SHAPED DIFFERENTLY FROM enrollment_code_race.php's,    ┐
 * │   AND THE DIFFERENCE IS THE ENTIRE LESSON OF THIS FILE.                  │
 * │                                                                          │
 * │ On the code race, the resource being contended is the CODE, and nothing  │
 * │ in the schema protects it — so a broken implementation lets 8 students   │
 * │ in and the subscription count itself is the symptom.                     │
 * │                                                                          │
 * │ Here the contended resource is a (student, course) pair, and             │
 * │ UNIQUE KEY uq_subscription_student_course ALREADY guarantees there can   │
 * │ only ever be one row. So the subscription count is 1 under EVERY         │
 * │ implementation, correct or not — asserting it would be asserting MySQL,  │
 * │ not the application.                                                     │
 * │                                                                          │
 * │ THE MONEY IS WHERE THE RACE ACTUALLY SHOWS. `was_created` gates the      │
 * │ revenue write, and the legacy shape infers it from a SELECT taken        │
 * │ BEFORE the insert:                                                       │
 * │                                                                          │
 * │     $existing = findByStudentAndCourse(...);   // 8 workers: all null    │
 * │     if (!$existing) { insert(); recordRevenue(); }   // 8 sales          │
 * │                                                                          │
 * │ One subscription, eight charges. That is the bug this control            │
 * │ reproduces, and it is a WORSE failure than the code race's — it is       │
 * │ invisible in the enrolment table and only ever surfaces in the           │
 * │ instructor's payout.                                                     │
 * │                                                                          │
 * │ The fixed path derives `was_created` from `wasRecentlyCreated`, which is │
 * │ set by the INSERT itself: exactly one worker's insert lands, the other   │
 * │ seven take createOrFirst's unique-violation branch and report false.     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Read tests/Concurrency/bootstrap.php first — database safety guard and the
 * argument for why this cannot be a PHPUnit test.
 */

use App\Actions\Enrollment\ToggleEnrollment;
use App\Actions\Revenue\RecordRevenueTransaction;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\RevenueTransaction;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__ . '/bootstrap.php';

const TOGGLE_RACE_PRICE = 100.00;

// ───────────────────────────────────────────────────────────── CHILD ROLE ──
if (($argv[1] ?? '') === '--child') {
    [$mode, $startAt] = [$argv[2], (float) $argv[3]];
    [$studentId, $courseId] = [$argv[5], $argv[6]];

    raceBoot();
    raceWaitForBarrier($startAt);

    try {
        if ($mode === 'naive') {
            // ── THE CONTROL. `was_created` inferred from a PRIOR read.
            $existing = DB::table('subscriptions')
                ->where('student_id', $studentId)
                ->where('course_id', $courseId)
                ->first();

            DB::transaction(function () use ($existing, $studentId, $courseId): void {
                if ($existing !== null) {
                    DB::table('subscriptions')
                        ->where('student_id', $studentId)
                        ->where('course_id', $courseId)
                        ->update(['status' => 'active', 'expires_at' => null]);

                    return;
                }

                /*
                 * insertOrIgnore, so the UNIQUE key cannot turn this into a
                 * crash and mask the application-level race. The point of the
                 * control is that the MONEY is written eight times, not that
                 * the insert explodes.
                 */
                $id = (string) Str::uuid7();

                DB::table('subscriptions')->insertOrIgnore([
                    'id' => $id,
                    'student_id' => $studentId,
                    'course_id' => $courseId,
                    'status' => 'active',
                    'expires_at' => null,
                    'created_at' => now(),
                ]);

                app(RecordRevenueTransaction::class)([
                    'subscription_id' => DB::table('subscriptions')
                        ->where('student_id', $studentId)
                        ->where('course_id', $courseId)
                        ->value('id'),
                    'student_id' => $studentId,
                    'course_id' => $courseId,
                    'teacher_id' => DB::table('courses')->where('id', $courseId)->value('teacher_id'),
                    'gross_amount' => TOGGLE_RACE_PRICE,
                    'payment_method' => RevenueTransaction::METHOD_MANUAL_ADMIN,
                ]);
            });

            fwrite(STDOUT, 'GRANTED (naive)');
            exit(0);
        }

        // ── THE REAL PRODUCTION PATH, exactly as
        //    AdminDashboardController::toggleEnrollment() invokes it.
        $subscription = app(ToggleEnrollment::class)($studentId, $courseId, true);

        fwrite(STDOUT, 'GRANTED subscription=' . $subscription->getKey());
    } catch (Throwable $e) {
        fwrite(STDOUT, 'REFUSED (' . class_basename($e) . ': ' . str_replace("\n", ' ', $e->getMessage()) . ')');
    }

    exit(0);
}

// ──────────────────────────────────────────────────────────── PARENT ROLE ──
raceBoot();

$modes = isset($argv[1]) ? [$argv[1]] : ['fixed', 'naive'];
$ids = toggleRaceSeed();
$failures = [];

try {
    foreach ($modes as $mode) {
        DB::table('revenue_transactions')->where('course_id', $ids['course'])->delete();
        DB::table('subscriptions')->where('course_id', $ids['course'])->delete();

        echo str_repeat('=', 78), "\n";
        echo "mode                : {$mode}\n";
        echo 'concurrent workers  : ', RACE_WORKERS, " (ONE student, ONE course)\n";
        echo 'course price        : ', number_format(TOGGLE_RACE_PRICE, 2), "\n";
        echo "subscriptions before: 0\n\n";

        foreach (raceRun(__FILE__, $mode, [$ids['student'], $ids['course']]) as $line) {
            echo '  ', $line, "\n";
        }

        $subscriptions = Subscription::query()->where('course_id', $ids['course'])->count();
        $transactions = RevenueTransaction::query()->where('course_id', $ids['course'])->count();
        $charged = (float) (DB::table('revenue_transactions')
            ->where('course_id', $ids['course'])
            ->sum('gross_amount'));

        echo "\nSELECT COUNT(*)          FROM subscriptions        -> {$subscriptions}\n";
        echo "SELECT COUNT(*)          FROM revenue_transactions -> {$transactions}\n";
        echo 'SELECT SUM(gross_amount) FROM revenue_transactions -> ', number_format($charged, 2), "\n";

        if ($mode === 'fixed') {
            $ok = $subscriptions === 1 && $transactions === 1;
            echo $ok
                ? "RESULT: PASS — one enrolment, charged exactly once\n"
                : "RESULT: FAIL — expected 1/1, got {$subscriptions}/{$transactions}\n";
        } else {
            /*
             * ONLY the transaction count is asserted. The subscription count
             * is 1 under every implementation because the UNIQUE key says so,
             * so requiring it to break would require the control to be MORE
             * broken than any real regression could be — and the run would
             * then fail for the wrong reason. See the file banner.
             */
            $ok = $transactions > 1;
            echo $ok
                ? "RESULT: PASS (control behaved as designed) — the pre-read inference\n"
                . "        charged the student {$transactions} times for ONE enrolment\n"
                . '        (' . number_format($charged, 2) . " instead of " . number_format(TOGGLE_RACE_PRICE, 2) . "),\n"
                . "        which proves the harness really does create concurrent writers.\n"
                : "RESULT: FAIL — the naive control recorded {$transactions} transaction(s).\n"
                . "        The workers did not overlap, so the `fixed` run proves NOTHING.\n";
        }

        if (!$ok) {
            $failures[] = $mode;
        }

        echo "\n";
    }
} finally {
    toggleRaceTeardown($ids);
    echo "fixtures torn down.\n";
}

echo str_repeat('=', 78), "\n";
echo $failures === []
    ? "OVERALL: PASS\n"
    : 'OVERALL: FAIL (' . implode(', ', $failures) . ")\n";

exit($failures === [] ? 0 : 1);

// ─────────────────────────────────────────────────────────────── FIXTURES ──

/** @return array{teacher: string, course: string, student: string} */
function toggleRaceSeed(): array
{
    $teacher = raceUser(UserRole::Instructor);
    Teacher::query()->create(['user_id' => $teacher->getKey(), 'grades' => [], 'subjects' => []]);

    $course = Course::query()->create([
        'title' => 'RACE toggle ' . bin2hex(random_bytes(4)),
        'price' => TOGGLE_RACE_PRICE,
        'teacher_id' => $teacher->getKey(),
        'is_published' => true,
    ]);

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

    return [
        'teacher' => (string) $teacher->getKey(),
        'course' => (string) $course->getKey(),
        'student' => (string) $student->getKey(),
    ];
}

function toggleRaceTeardown(array $ids): void
{
    DB::table('revenue_transactions')->where('course_id', $ids['course'])->delete();
    DB::table('subscriptions')->where('course_id', $ids['course'])->delete();
    DB::table('courses')->where('id', $ids['course'])->delete();
    DB::table('students')->where('user_id', $ids['student'])->delete();
    DB::table('teachers')->where('user_id', $ids['teacher'])->delete();
    DB::table('users')->whereIn('id', [$ids['student'], $ids['teacher']])->delete();
}

/** Referenced by the child's `status` write; kept for symmetry with the enum. */
function toggleRaceActiveStatus(): string
{
    return SubscriptionStatus::Active->value;
}
