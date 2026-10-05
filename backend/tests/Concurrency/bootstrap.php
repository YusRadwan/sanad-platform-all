<?php

declare(strict_types=1);

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ SHARED HARNESS FOR THE STAGE 6 / SESSION B RACE TESTS.                  ║
 * ║                                                                          ║
 * ║ These are NOT PHPUnit tests and are deliberately outside phpunit.xml's   ║
 * ║ two testsuites. They are standalone scripts that spawn REAL OS           ║
 * ║ PROCESSES, because that is the only way to create genuine concurrency    ║
 * ║ on this stack.                                                           ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── WHY NOT PHPUnit, AND WHY NOT curl AGAINST A DEV SERVER? ───────────────┐
 * │                                                                          │
 * │ PHPUnit / Laravel's test client run IN-PROCESS and SINGLE-THREADED. Two  │
 * │ "concurrent" submissions in a test method are simply sequential, so a    │
 * │ completely unlocked implementation passes. Such a test proves nothing.   │
 * │                                                                          │
 * │ curl against `php artisan serve` is no better and for a sharper reason:  │
 * │ PHP's built-in server handles requests ONE AT A TIME on Windows          │
 * │ (PHP_CLI_SERVER_WORKERS is fork-based and POSIX-only), so N simultaneous │
 * │ requests are serialised BY THE WEB SERVER before reaching the database.  │
 * │ backend/tests/concurrency/exam_attempt_race_test.php records measuring   │
 * │ exactly that: its unlocked control "passed" over HTTP.                   │
 * │                                                                          │
 * │ So the concurrency is created where it actually matters: N genuinely     │
 * │ parallel OS processes, each booting its own application and opening its  │
 * │ OWN MySQL connection, all entering the write path inside a               │
 * │ sub-millisecond window. That is STRICTER than production HTTP traffic,   │
 * │ not a stand-in for it.                                                   │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE NEGATIVE CONTROL IS **MANDATORY**, AND IT IS THE POINT.              ║
 * ║                                                                          ║
 * ║ A green race test on its own is worthless: it is indistinguishable from  ║
 * ║ a harness that failed to overlap the workers at all. Every script here   ║
 * ║ therefore runs in two modes —                                            ║
 * ║                                                                          ║
 * ║   fixed  the REAL production code path (the Action, through the          ║
 * ║          container). MUST end at exactly the cap.                        ║
 * ║   naive  a deliberately unlocked count-then-insert. MUST EXCEED the cap. ║
 * ║                                                                          ║
 * ║ If `naive` also respects the cap, the harness is not creating            ║
 * ║ concurrency and the `fixed` result means nothing. The runner treats a    ║
 * ║ well-behaved control as a FAILURE.                                       ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── DATABASE SAFETY — A HARD GUARD, NOT A CONVENTION. ─────────────────────┐
 * │ These scripts WRITE REAL ROWS and are not wrapped in a transaction (they │
 * │ cannot be: the whole point is that each worker commits independently).   │
 * │ assertSafeDatabase() below refuses to run against anything but           │
 * │ `sanad_platform_v2_test`, and refuses OUTRIGHT if the configured name    │
 * │ looks like backend/'s `sanad_platform_test`. Every fixture is torn down  │
 * │ afterwards, and the teardown runs in a `finally`.                        │
 * └──────────────────────────────────────────────────────────────────────────┘
 */

use App\Actions\ContentItem\ContentDetailPersisters;
use App\Enums\ContentItemType;
use App\Enums\QuestionType;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\ContentItem;
use App\Models\Course;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionGroup;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const RACE_DATABASE = 'sanad_platform_v2_test';

/** How many workers race. 8 is backend/'s number and is kept for comparability. */
const RACE_WORKERS = 8;

/** Boot the real application, pointed at the TEST database, and prove it. */
function raceBoot(): \Illuminate\Foundation\Application
{
    $root = dirname(__DIR__, 2);

    require_once $root . '/vendor/autoload.php';

    /** @var \Illuminate\Foundation\Application $app */
    $app = require $root . '/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    /*
     * The connection is repointed AFTER boot and the pool purged, rather than
     * relying on env-var precedence: putenv() vs .env vs phpunit.xml is exactly
     * the kind of thing that silently resolves the wrong way, and "the wrong
     * way" here means writing rows into a development database.
     */
    config(['database.connections.mysql.database' => RACE_DATABASE]);
    DB::purge('mysql');

    assertSafeDatabase();

    return $app;
}

/** Refuse to touch anything but the v2 test database. Fatal, never a warning. */
function assertSafeDatabase(): void
{
    $name = (string) DB::connection()->getDatabaseName();

    if ($name !== RACE_DATABASE) {
        fwrite(STDERR, "REFUSING TO RUN: connected to '{$name}', expected '" . RACE_DATABASE . "'.\n");
        exit(2);
    }

    // Belt and braces. backend/'s database must never be reachable from here.
    if (str_contains($name, 'sanad_platform_test')) {
        fwrite(STDERR, "REFUSING TO RUN: '{$name}' is backend/'s database.\n");
        exit(2);
    }
}

/**
 * Every fixture the two races need, created through the SAME factories the
 * application and the PHPUnit suite use — so the fixture cannot express a state
 * the application could not.
 *
 * @return array{teacher: string, student: string, course: string, exam: string, question: string}
 */
function raceSeed(int $allowedAttempts): array
{
    $teacher = raceUser(UserRole::Instructor);
    Teacher::query()->create(['user_id' => $teacher->getKey(), 'grades' => [], 'subjects' => []]);

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

    $course = Course::query()->create([
        'title' => 'RACE ' . bin2hex(random_bytes(4)),
        'price' => 0,
        'teacher_id' => $teacher->getKey(),
        'is_published' => true,
    ]);

    Subscription::query()->create([
        'student_id' => $student->getKey(),
        'course_id' => $course->getKey(),
        'status' => SubscriptionStatus::Active,
        'expires_at' => null,
        'created_at' => now(),
    ]);

    $section = Section::query()->create([
        'course_id' => $course->getKey(),
        'title' => 'RACE section',
        'order_index' => 0,
    ]);

    $bank = new QuestionGroup();
    $bank->fill(['title' => 'RACE bank', 'grade' => null]);
    $bank->setAttribute('teacher_id', $teacher->getKey());
    $bank->save();

    $question = new Question();
    $question->fill([
        'body' => '٢+٢',
        'type' => QuestionType::Mcq->value,
        'options' => ['3', '4'],
        'correct_answer' => '4',
        'points' => 1,
    ]);
    $question->setAttribute('question_group_id', $bank->getKey());
    $question->save();

    $examItem = ContentItem::createUnderSection($section, [
        'title' => 'RACE exam',
        'type' => ContentItemType::Exam,
        'is_hidden' => false,
    ]);
    ContentDetailPersisters::apply($examItem, [
        'question_group_id' => $bank->getKey(),
        'allowed_attempts' => $allowedAttempts,
    ]);

    return [
        'teacher' => (string) $teacher->getKey(),
        'student' => (string) $student->getKey(),
        'course' => (string) $course->getKey(),
        'exam' => (string) Exam::query()->where('content_item_id', $examItem->getKey())->firstOrFail()->getKey(),
        'question' => (string) $question->getKey(),
    ];
}

function raceUser(UserRole $role): User
{
    $user = new User();

    $user->forceFill([
        'username' => 'race' . bin2hex(random_bytes(5)),
        'email' => 'race' . bin2hex(random_bytes(5)) . '@sanad.local',
        'password_hash' => Hash::make('RaceHarnessPassword42'),
        'full_name' => 'سباق',
        'role' => $role,
        'is_active' => true,
    ])->save();

    return $user->refresh();
}

/**
 * Delete every row the harness created, deepest first.
 *
 * `courses` is SOFT-deleted by the model, so it is removed with the query
 * builder — a trashed fixture row left behind would still hold its section,
 * content items and detail rows alive and would accumulate on every run.
 *
 * @param array{teacher: string, student: string, course: string, exam: string, question: string} $ids
 */
function raceTeardown(array $ids): void
{
    DB::table('exam_attempts')->where('exam_id', $ids['exam'])->delete();
    DB::table('subscriptions')->where('course_id', $ids['course'])->delete();

    // content_items -> exams cascade; sections -> content_items;
    // courses -> sections. One hard delete at the top is enough.
    DB::table('courses')->where('id', $ids['course'])->delete();

    DB::table('questions')->where('id', $ids['question'])->delete();
    DB::table('question_groups')->where('teacher_id', $ids['teacher'])->delete();

    DB::table('students')->where('user_id', $ids['student'])->delete();
    DB::table('teachers')->where('user_id', $ids['teacher'])->delete();
    DB::table('users')->whereIn('id', [$ids['student'], $ids['teacher']])->delete();
}

/**
 * Fire N children at a SHARED wall-clock barrier and collect their output.
 *
 * The barrier is what makes the overlap real: without it each worker would
 * enter the write path whenever its interpreter happened to finish booting,
 * which on a cold autoloader can be hundreds of milliseconds apart — wide
 * enough for the race never to occur.
 *
 * @param  array<int, string>  $childArgs  extra argv passed to every child
 * @return list<string>
 */
function raceRun(string $script, string $mode, array $childArgs): array
{
    // Two seconds is comfortably longer than a cold Laravel boot, so every
    // child is already spinning on the barrier when it lifts.
    $startAt = microtime(true) + 2.0;

    $procs = [];
    $pipes = [];

    for ($i = 0; $i < RACE_WORKERS; $i++) {
        $command = implode(' ', array_map('escapeshellarg', array_merge(
            [PHP_BINARY, $script, '--child', $mode, (string) $startAt, (string) $i],
            $childArgs
        )));

        $procs[$i] = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes[$i],
            dirname(__DIR__, 2)
        );
    }

    $output = [];

    foreach ($procs as $i => $process) {
        if ($process === false) {
            $output[] = "child {$i}: FAILED TO SPAWN";

            continue;
        }

        $output[] = 'child ' . $i . ': ' . trim((string) stream_get_contents($pipes[$i][1]));
        $stderr = trim((string) stream_get_contents($pipes[$i][2]));

        if ($stderr !== '') {
            $output[] = "child {$i} STDERR: {$stderr}";
        }

        fclose($pipes[$i][1]);
        fclose($pipes[$i][2]);

        // proc_close REAPS the process. Skipping it leaves zombies behind on
        // POSIX and open handles on Windows, which is the "no orphaned
        // processes" half of this harness's cleanliness contract.
        proc_close($process);
    }

    sort($output);

    return $output;
}

/** Spin until the shared barrier. A sleep would quantise to the scheduler tick. */
function raceWaitForBarrier(float $startAt): void
{
    while (microtime(true) < $startAt) {
        // busy-wait on purpose — see the function docblock
    }
}
