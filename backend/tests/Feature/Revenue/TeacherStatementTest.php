<?php

declare(strict_types=1);

namespace Tests\Feature\Revenue;

use App\Actions\Revenue\RecordRevenueTransaction;
use App\Models\TeacherProfitSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /revenue/my-statement (row 99) and GET /revenue/statement/{teacherId}
 * (row 64) — one computation behind two doors.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE FIRST TEST IS SESSION B's DECISION #2, AND IT IS THE POINT OF THIS  ║
 * ║ WHOLE FILE.                                                              ║
 * ║                                                                          ║
 * ║ In backend/, ONE teacher with ONE refunded sale produced THREE different ║
 * ║ answers to "what are we holding for them?":                              ║
 * ║                                                                          ║
 * ║   /revenue/my-statement       balance            counted the refund      ║
 * ║   /revenue/statement/{id}     balance            counted the refund      ║
 * ║   /revenue/teachers           available_balance  did NOT                 ║
 * ║                                                                          ║
 * ║ …because myStatement() summed every row and listTeacherPercentages()     ║
 * ║ hand-wrote `AND rt.status = 'paid'`. Two screens, one product, no        ║
 * ║ authority. All three now route through RevenueTransaction::scopePaid()   ║
 * ║ and the fixture below is built so a mismatch is a DIFFERENT NUMBER, not  ║
 * ║ a rounding difference: 300 paid, 500 refunded — the wrong answer is      ║
 * ║ 800-ish and the right one is 300-ish, which cannot be confused.          ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class TeacherStatementTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    public function test_my_statement_and_the_admin_available_balance_agree_to_the_cent(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        [$student] = $this->makeStudentWithCode();

        // 200 + 100 paid at the default 70% -> 140.00 + 70.00 = 210.00 earned.
        $this->recordRevenue($student, $course, 200.00);
        $this->recordRevenue($student, $course, 100.00);

        // 500 refunded. At 70% that is 350.00 of earnings that MUST NOT count.
        $refunded = $this->recordRevenue($student, $course, 500.00);
        $refunded->forceFill(['status' => 'refunded'])->save();

        $this->makePayout($instructor, 60.00);

        // Expected on BOTH endpoints: 210.00 earned - 60.00 paid = 150.00.
        $this->loginAs($instructor);
        $mine = $this->getJson('/api/v1/revenue/my-statement')->assertOk()->json('data.statement');

        $this->startNextRequest();
        $this->loginAsAdmin();
        $asAdmin = $this->getJson('/api/v1/revenue/statement/' . $instructor->getKey())
            ->assertOk()->json('data.statement');
        $row = collect($this->getJson('/api/v1/revenue/teachers')->json('data.teachers'))
            ->firstWhere('teacher_id', (string) $instructor->getKey());

        self::assertSame(210.0, (float) $mine['total_earnings'], 'A refunded sale must not be earnings.');
        self::assertSame(60.0, (float) $mine['total_paid_out']);
        self::assertSame(150.0, (float) $mine['balance']);
        self::assertSame(2, $mine['transactions_count'], 'The count must move with the money.');

        // THE THREE NUMBERS THAT DISAGREED IN backend/.
        self::assertSame($mine, $asAdmin, 'Row 64 and row 99 are literally the same computation.');
        self::assertSame(150.0, (float) $row['available_balance']);
    }

    /**
     * `profit_percentage` IS THE ONLY FORWARD-LOOKING FIGURE ON THE STATEMENT.
     * It reads the CURRENT setting while `total_earnings` sums frozen
     * snapshots, and the two are allowed to describe different rates.
     */
    public function test_the_percentage_is_the_current_setting_not_the_recorded_one(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        [$student] = $this->makeStudentWithCode();

        $this->makeProfitSetting($instructor, 60);
        $this->recordRevenue($student, $course, 100.00); // 60.00 earned, frozen.

        // The admin re-rates the teacher AFTER the sale.
        TeacherProfitSetting::query()->whereKey($instructor->getKey())
            ->update(['profit_percentage' => 40]);

        $this->loginAs($instructor);
        $statement = $this->getJson('/api/v1/revenue/my-statement')->assertOk()->json('data.statement');

        self::assertSame(40.0, (float) $statement['profit_percentage'], 'The NEXT sale pays 40%.');
        self::assertSame(60.0, (float) $statement['total_earnings'], 'The LAST sale paid 60% and still does.');
    }

    /**
     * NO ROW IN `teacher_profit_settings` MEANS THE DEFAULT, and the default
     * is the named constant, not a second literal 70 that could drift from the
     * column's own DEFAULT '70.00'.
     */
    public function test_a_teacher_with_no_profit_row_reports_the_named_default(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();

        $this->loginAs($instructor);
        $statement = $this->getJson('/api/v1/revenue/my-statement')->assertOk()->json('data.statement');

        self::assertSame(
            RecordRevenueTransaction::DEFAULT_PROFIT_PERCENTAGE,
            (float) $statement['profit_percentage']
        );
        self::assertSame(0.0, (float) $statement['total_earnings']);
        self::assertSame(0.0, (float) $statement['balance']);
        self::assertSame(0, $statement['transactions_count']);
    }

    /**
     * A STORED 0% IS NOT "UNSET". The whole `!== null` treatment in
     * TeacherStatement::profitPercentageFor() exists for this one row.
     */
    public function test_a_stored_zero_percent_is_reported_as_zero_not_as_seventy(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->makeProfitSetting($instructor, 0);

        $this->loginAs($instructor);

        self::assertSame(
            0.0,
            (float) $this->getJson('/api/v1/revenue/my-statement')->json('data.statement.profit_percentage')
        );
    }

    /** One teacher's ledger never leaks into another's statement. */
    public function test_the_statement_is_scoped_to_one_teacher(): void
    {
        $this->disableRateLimiting();
        [$mine] = $this->makeTeacherWithProfile();
        [$theirs] = $this->makeTeacherWithProfile();
        [$student] = $this->makeStudentWithCode();

        $this->recordRevenue($student, $this->makeCourseFor($mine, ['price' => 100]), 100.00);
        $this->recordRevenue($student, $this->makeCourseFor($theirs, ['price' => 100]), 900.00);
        $this->makePayout($theirs, 500.00);

        $this->loginAs($mine);
        $statement = $this->getJson('/api/v1/revenue/my-statement')->assertOk()->json('data.statement');

        self::assertSame(70.0, (float) $statement['total_earnings']);
        self::assertSame(0.0, (float) $statement['total_paid_out']);
        self::assertSame(1, $statement['transactions_count']);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ `?teacher_id=` IS INERT ON EVERY `my-*` ROUTE. Stage 4's pattern.    ║
     * ║ The controller reads the session and nothing beneath it reads the    ║
     * ║ query string, so this asserts a property that holds BY CONSTRUCTION  ║
     * ║ — but it is asserted anyway, because "no code reads it" is exactly   ║
     * ║ the kind of guarantee a later refactor removes without noticing.     ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_a_hostile_teacher_id_parameter_is_ignored_on_all_three_my_routes(): void
    {
        $this->disableRateLimiting();
        [$mine] = $this->makeTeacherWithProfile();
        [$victim] = $this->makeTeacherWithProfile();
        [$student] = $this->makeStudentWithCode();

        $this->recordRevenue($student, $this->makeCourseFor($victim, ['price' => 100]), 900.00);
        $this->makePayout($victim, 111.00);

        $this->loginAs($mine);
        $hostile = '?teacher_id=' . $victim->getKey();

        self::assertSame(
            0.0,
            (float) $this->getJson('/api/v1/revenue/my-statement' . $hostile)->json('data.statement.total_earnings')
        );
        self::assertSame(
            [],
            $this->getJson('/api/v1/revenue/my-transactions' . $hostile)->json('data.transactions')
        );
        self::assertSame(
            [],
            $this->getJson('/api/v1/revenue/my-payouts' . $hostile)->json('data.payouts')
        );
    }

    /**
     * THE TRANSACTION LIST SHOWS REFUNDED ROWS; THE STATEMENT DOES NOT COUNT
     * THEM. That is not a contradiction — an instructor must be able to SEE
     * that a sale was refunded, which is impossible if the row disappears.
     */
    public function test_my_transactions_still_lists_a_refunded_sale_the_statement_excludes(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile(user: ['full_name' => 'أ. سند']);
        $course = $this->makeCourseFor($instructor, ['title' => 'كورس مالي', 'price' => 100]);
        [$student] = $this->makeStudentWithCode();

        $this->recordRevenue($student, $course, 100.00);
        $this->recordRevenue($student, $course, 400.00)->forceFill(['status' => 'refunded'])->save();

        $this->loginAs($instructor);

        $rows = $this->getJson('/api/v1/revenue/my-transactions')->assertOk()->json('data.transactions');
        self::assertCount(2, $rows);

        // The three resolved names backend/ added because every cell was blank.
        self::assertSame('كورس مالي', $rows[0]['course_name']);
        self::assertSame('أ. سند', $rows[0]['teacher_name']);
        self::assertNotNull($rows[0]['student_name']);

        self::assertSame(
            70.0,
            (float) $this->getJson('/api/v1/revenue/my-statement')->json('data.statement.total_earnings')
        );
    }

    /** GET /revenue/my-payouts and GET /revenue/payouts/{id} are one list. */
    public function test_my_payouts_and_the_admin_view_of_them_are_the_same_list(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $admin = $this->makeAdmin();
        $this->makePayout($instructor, 25.50, $admin);

        $this->loginAs($instructor);
        $mine = $this->getJson('/api/v1/revenue/my-payouts')->assertOk()->json('data.payouts');

        $this->startNextRequest();
        $this->loginAsAdmin();
        $theirs = $this->getJson('/api/v1/revenue/payouts/' . $instructor->getKey())
            ->assertOk()->json('data.payouts');

        self::assertSame($mine, $theirs);
        self::assertSame('25.50', (string) $mine[0]['amount']);
        self::assertSame((string) $admin->getKey(), $mine[0]['created_by']);
    }
}
