<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Actions\Revenue\RecordRevenueTransaction;
use App\Models\RevenueTransaction;
use App\Models\TeacherProfitSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `teacher_earnings` AND `teacher_profit_percentage` ARE COMPUTED AT SALE  ║
 * ║ TIME AND FROZEN ON THE ROW.                                              ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * The one test in this file that is not about arithmetic —
 * test_changing_the_percentage_afterwards_never_touches_a_recorded_row — is
 * the one that matters most. Everything else here checks that a number is
 * right today; that one checks that it STAYS right after the world changes
 * around it, which is the difference between a stored ledger and a derived
 * view. An implementation that computed earnings at READ time from the
 * CURRENT percentage would pass every other test in this file.
 */
final class RevenueRecordingTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    public function test_a_sixty_percent_teacher_earns_exactly_sixty_percent(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 250]);
        [$student] = $this->makeStudentWithCode();
        $this->makeProfitSetting($instructor, 60);

        $transaction = $this->recordRevenue($student, $course, 250.00);

        self::assertSame('60.00', (string) $transaction->teacher_profit_percentage);
        self::assertSame('150.00', (string) $transaction->teacher_earnings);

        // Read back from MySQL, not from the in-memory model: the assertion is
        // about what is STORED, and a cast can make a wrong value look right.
        $stored = DB::table('revenue_transactions')->where('id', $transaction->getKey())->sole();
        self::assertSame('150.00', $stored->teacher_earnings);
        self::assertSame('60.00', $stored->teacher_profit_percentage);
    }

    /**
     * NO SETTINGS ROW -> 70%, the `teacher_profit_settings.profit_percentage`
     * schema default mirrored in RecordRevenueTransaction::
     * DEFAULT_PROFIT_PERCENTAGE. The constant and the migration must agree;
     * this test is what notices when they stop.
     */
    public function test_a_teacher_with_no_settings_row_defaults_to_seventy_percent(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        [$student] = $this->makeStudentWithCode();

        self::assertSame(0, TeacherProfitSetting::query()->count());

        $transaction = $this->recordRevenue($student, $course, 100.00);

        self::assertSame('70.00', (string) $transaction->teacher_profit_percentage);
        self::assertSame('70.00', (string) $transaction->teacher_earnings);
        self::assertSame(
            RecordRevenueTransaction::DEFAULT_PROFIT_PERCENTAGE,
            (float) DB::table('teacher_profit_settings')->getConnection()
                ->selectOne("SHOW COLUMNS FROM teacher_profit_settings LIKE 'profit_percentage'")->Default,
            'The named constant must mirror the column default it claims to mirror.'
        );
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ 0% IS A REAL STORED VALUE, NOT "UNSET".                              ║
     * ║                                                                      ║
     * ║ `chk_profit_settings_percentage` permits it, and the natural-reading ║
     * ║ implementation — `$stored ?: 70.0` — would silently pay this teacher ║
     * ║ 70% of every sale for the rest of the product's life. Only a genuine ║
     * ║ NULL may fall through to the default.                                ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_zero_percent_teacher_earns_zero_not_the_default(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 500]);
        [$student] = $this->makeStudentWithCode();
        $this->makeProfitSetting($instructor, 0);

        $transaction = $this->recordRevenue($student, $course, 500.00);

        self::assertSame('0.00', (string) $transaction->teacher_profit_percentage);
        self::assertSame('0.00', (string) $transaction->teacher_earnings);
        self::assertSame('500.00', (string) $transaction->gross_amount);
    }

    public function test_a_hundred_percent_teacher_earns_the_whole_gross(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 99]);
        [$student] = $this->makeStudentWithCode();
        $this->makeProfitSetting($instructor, 100);

        $transaction = $this->recordRevenue($student, $course, 99.00);

        self::assertSame('99.00', (string) $transaction->teacher_earnings);
    }

    /**
     * ROUNDING IS EXPLICIT AND HAPPENS IN PHP. 33.33% of 99.99 is 33.326667;
     * the column holds two decimals. What the application asserts and what
     * MySQL stores must be the same number, decided in one place.
     */
    public function test_a_fractional_split_is_rounded_to_the_columns_two_decimals(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 99.99]);
        [$student] = $this->makeStudentWithCode();
        $this->makeProfitSetting($instructor, 33.33);

        $transaction = $this->recordRevenue($student, $course, 99.99);

        self::assertSame('33.33', (string) $transaction->teacher_earnings);
        self::assertSame(
            '33.33',
            DB::table('revenue_transactions')->where('id', $transaction->getKey())->value('teacher_earnings')
        );
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE SNAPSHOT-IMMUTABILITY TEST. THIS IS THE ACCOUNTING GUARANTEE.    ║
     * ║                                                                      ║
     * ║ Record at 60%, then change the teacher to 40%, then re-read the      ║
     * ║ ORIGINAL row: both the percentage and the earnings must be exactly   ║
     * ║ what they were. Anything that recomputes from the current setting —  ║
     * ║ an accessor, a report-time join, a "helpful" refactor — rewrites     ║
     * ║ history, including sales that have already been paid out.            ║
     * ║                                                                      ║
     * ║ The NEW sale recorded afterwards must, equally, use the NEW rate.    ║
     * ║ Both halves are asserted, because a class that froze everything      ║
     * ║ forever would pass the first half alone.                             ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_changing_the_percentage_afterwards_never_touches_a_recorded_row(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 1000]);
        [$student] = $this->makeStudentWithCode();
        [$later] = $this->makeStudentWithCode();

        $this->makeProfitSetting($instructor, 60);
        $historic = $this->recordRevenue($student, $course, 1000.00);

        self::assertSame('600.00', (string) $historic->teacher_earnings);

        // The admin renegotiates the teacher's share downwards.
        TeacherProfitSetting::query()
            ->whereKey($instructor->getKey())
            ->update(['profit_percentage' => 40]);

        // ── THE HISTORIC ROW IS UNCHANGED, read fresh from the database.
        $reread = RevenueTransaction::query()->findOrFail($historic->getKey());
        self::assertSame('60.00', (string) $reread->teacher_profit_percentage);
        self::assertSame('600.00', (string) $reread->teacher_earnings);

        // ── AND THE NEXT SALE USES THE NEW RATE.
        $fresh = $this->recordRevenue($later, $course, 1000.00);
        self::assertSame('40.00', (string) $fresh->teacher_profit_percentage);
        self::assertSame('400.00', (string) $fresh->teacher_earnings);
    }

    /**
     * AN EXPLICIT NULL MUST NOT WIN OVER THE RESOLVED RATE. This is the exact
     * defect `$data + [...]` (the `+` union operator) would reintroduce: the
     * left-hand null would survive and the column would go back to NULL —
     * which is the state this whole session exists to end.
     */
    public function test_an_explicit_null_percentage_still_resolves_to_the_stored_rate(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 200]);
        [$student] = $this->makeStudentWithCode();
        $this->makeProfitSetting($instructor, 55);

        $transaction = app(RecordRevenueTransaction::class)([
            'student_id' => $student->getKey(),
            'course_id' => $course->getKey(),
            'teacher_id' => $course->teacher_id,
            'gross_amount' => 200,
            'payment_method' => RevenueTransaction::METHOD_MANUAL_ADMIN,
            'teacher_profit_percentage' => null,
        ]);

        self::assertSame('55.00', (string) $transaction->teacher_profit_percentage);
        self::assertSame('110.00', (string) $transaction->teacher_earnings);
    }

    /** An explicitly SUPPLIED rate overrides the stored one. */
    public function test_an_explicit_percentage_overrides_the_stored_setting(): void
    {
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 200]);
        [$student] = $this->makeStudentWithCode();
        $this->makeProfitSetting($instructor, 55);

        $transaction = app(RecordRevenueTransaction::class)([
            'student_id' => $student->getKey(),
            'course_id' => $course->getKey(),
            'teacher_id' => $course->teacher_id,
            'gross_amount' => 200,
            'payment_method' => RevenueTransaction::METHOD_MANUAL_ADMIN,
            'teacher_profit_percentage' => 25,
        ]);

        self::assertSame('25.00', (string) $transaction->teacher_profit_percentage);
        self::assertSame('50.00', (string) $transaction->teacher_earnings);
    }

    /**
     * NO ROW EVER LANDS WITH A NULL SPLIT — the state every row written by
     * this session's two enrolment paths must be free of. Asserted at the
     * DATABASE, over the whole table, after exercising both paths end to end.
     */
    public function test_no_transaction_written_by_either_enrolment_path_has_a_null_split(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 120]);
        $this->makeProfitSetting($instructor, 65);

        // ── path 1: enrollment code
        $code = $this->makeEnrollmentCode($course);
        [$byCode] = $this->makeStudentWithCode();
        $this->loginAs($byCode);
        $this->postJson('/api/v1/enrollment-codes/redeem', ['code' => $code->code])
            ->assertStatus(200);

        // ── path 2: admin manual grant
        [$byAdmin] = $this->makeStudentWithCode();
        $this->loginAsAdmin();
        $this->putJson('/api/v1/admin/enrollments/toggle', [
            'studentId' => (string) $byAdmin->getKey(),
            'courseId' => (string) $course->getKey(),
            'isActive' => true,
        ])->assertStatus(200);

        self::assertSame(2, RevenueTransaction::query()->count());
        self::assertSame(0, DB::table('revenue_transactions')
            ->whereNull('teacher_earnings')
            ->orWhereNull('teacher_profit_percentage')
            ->count());

        foreach (RevenueTransaction::query()->get() as $row) {
            self::assertSame('78.00', (string) $row->teacher_earnings);
            self::assertSame('65.00', (string) $row->teacher_profit_percentage);
        }

        self::assertSame(
            [RevenueTransaction::METHOD_ENROLLMENT_CODE, RevenueTransaction::METHOD_MANUAL_ADMIN],
            RevenueTransaction::query()->orderBy('payment_method')->pluck('payment_method')->all()
        );
    }
}
