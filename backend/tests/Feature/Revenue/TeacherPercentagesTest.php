<?php

declare(strict_types=1);

namespace Tests\Feature\Revenue;

use App\Actions\Revenue\RecordRevenueTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/revenue/teachers — freeze-table row 63.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE FIRST TEST IS THE FAN-OUT REGRESSION, SHAPED EXACTLY LIKE            ║
 * ║ CoursesOverviewTest's — same methodology, one table over.                ║
 * ║                                                                          ║
 * ║ 3 transactions and 2 payouts is the minimum fixture in which every wrong ║
 * ║ answer is a DIFFERENT number from the right one. With earnings of        ║
 * ║ 70+140+210 = 420.00 and payouts of 100+50 = 150.00:                      ║
 * ║                                                                          ║
 * ║   correct (correlated subqueries)   420.00 - 150.00 =  270.00            ║
 * ║   joined (cross product, 6 rows)    840.00 - 450.00 =  390.00            ║
 * ║                                                                          ║
 * ║ Note the trap: the WRONG answer is still positive, still plausible, and  ║
 * ║ still "roughly right" — 390 vs 270 on a payout screen reads as an        ║
 * ║ accounting question, not as a query-shape bug. Asserting the exact cent  ║
 * ║ is what makes this a regression test rather than a smoke test. Both      ║
 * ║ multipliers are asserted separately below so a partial fan-out (one edge ║
 * ║ joined, one subqueried) also fails.                                      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class TeacherPercentagesTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    public function test_three_transactions_and_two_payouts_do_not_multiply(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile(user: ['full_name' => 'أ. سند']);
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        [$student] = $this->makeStudentWithCode();

        // EXACTLY THREE transactions at the 70% default: 70 + 140 + 210 = 420.
        $this->recordRevenue($student, $course, 100.00);
        $this->recordRevenue($student, $course, 200.00);
        $this->recordRevenue($student, $course, 300.00);

        // EXACTLY TWO payouts: 100 + 50 = 150.
        $this->makePayout($instructor, 100.00);
        $this->makePayout($instructor, 50.00);

        $this->loginAsAdmin();

        $row = collect($this->getJson('/api/v1/revenue/teachers')->assertOk()->json('data.teachers'))
            ->firstWhere('teacher_id', (string) $instructor->getKey());

        self::assertSame(
            270.0,
            (float) $row['available_balance'],
            'A join would report 390.00 — earnings doubled and payouts tripled.'
        );

        // The two halves, separately, so a PARTIAL fan-out cannot pass.
        $statement = $this->getJson('/api/v1/revenue/statement/' . $instructor->getKey())
            ->json('data.statement');
        self::assertSame(420.0, (float) $statement['total_earnings'], 'A join would report 840.00.');
        self::assertSame(150.0, (float) $statement['total_paid_out'], 'A join would report 450.00.');
    }

    /**
     * THE TWO DEAD FIELDS backend/'s OWN COMMENT DOCUMENTS.
     *
     * `name` duplicates `full_name` because three admin screens read
     * `teacher.name` and it was blank; `available_balance` was never selected
     * at all and the frontend's `?? 0` fired on every row. Both are asserted
     * as PRESENT and CORRECT, because "the key exists" and "the key is 0
     * because nobody computed it" look identical to a smoke test.
     */
    public function test_the_two_historically_dead_fields_are_present_and_real(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile(user: ['full_name' => 'أ. منى']);
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        [$student] = $this->makeStudentWithCode();
        $this->recordRevenue($student, $course, 100.00);

        $this->loginAsAdmin();

        $row = $this->getJson('/api/v1/revenue/teachers')->assertOk()->json('data.teachers.0');

        self::assertArrayHasKey('name', $row);
        self::assertSame('أ. منى', $row['name']);
        self::assertSame($row['full_name'], $row['name'], 'Both spellings ship; consumers disagree.');
        self::assertArrayHasKey('available_balance', $row);
        self::assertSame(70.0, (float) $row['available_balance'], 'Not the frontend `?? 0` fallback.');
    }

    /** A REFUNDED sale is not an available balance. Decision #2, row 63 side. */
    public function test_a_refunded_sale_is_excluded_from_the_available_balance(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        [$student] = $this->makeStudentWithCode();

        $this->recordRevenue($student, $course, 100.00);
        $this->recordRevenue($student, $course, 900.00)->forceFill(['status' => 'refunded'])->save();

        $this->loginAsAdmin();

        self::assertSame(
            70.0,
            (float) $this->getJson('/api/v1/revenue/teachers')->json('data.teachers.0.available_balance')
        );
    }

    /** No `teacher_profit_settings` row means the NAMED default, not a 70. */
    public function test_a_teacher_with_no_setting_row_reports_the_named_default(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        [$rated] = $this->makeTeacherWithProfile();
        $this->makeProfitSetting($rated, 35.5);

        $this->loginAsAdmin();

        $rows = collect($this->getJson('/api/v1/revenue/teachers')->json('data.teachers'))
            ->keyBy('teacher_id');

        self::assertSame(
            RecordRevenueTransaction::DEFAULT_PROFIT_PERCENTAGE,
            (float) $rows[(string) $instructor->getKey()]['profit_percentage']
        );
        self::assertSame(35.5, (float) $rows[(string) $rated->getKey()]['profit_percentage']);
    }

    /** A brand-new teacher reports 0.0, never null — the column is money. */
    public function test_a_teacher_with_nothing_attached_reports_zero_not_null(): void
    {
        $this->disableRateLimiting();
        $this->makeTeacherWithProfile();

        $this->loginAsAdmin();

        $row = $this->getJson('/api/v1/revenue/teachers')->json('data.teachers.0');

        self::assertNotNull($row['available_balance']);
        self::assertSame(0.0, (float) $row['available_balance']);
    }

    /** Each teacher's aggregates are their own — proven over a set. */
    public function test_one_teachers_figures_never_leak_into_anothers(): void
    {
        $this->disableRateLimiting();
        [$busy] = $this->makeTeacherWithProfile(user: ['full_name' => 'مزدحم']);
        [$quiet] = $this->makeTeacherWithProfile(user: ['full_name' => 'هادئ']);
        [$student] = $this->makeStudentWithCode();

        $this->recordRevenue($student, $this->makeCourseFor($busy, ['price' => 100]), 1000.00);
        $this->makePayout($busy, 400.00);
        $this->recordRevenue($student, $this->makeCourseFor($quiet, ['price' => 100]), 100.00);

        $this->loginAsAdmin();

        $rows = collect($this->getJson('/api/v1/revenue/teachers')->json('data.teachers'))->keyBy('name');

        self::assertSame(300.0, (float) $rows['مزدحم']['available_balance']); // 700 - 400
        self::assertSame(70.0, (float) $rows['هادئ']['available_balance']);
    }

    /**
     * ⚠ AN ACCEPTED DIVERGENCE FROM backend/, NOT A PORT.
     *
     * backend/ used `INNER JOIN users` with no `deleted_at` clause, so a
     * soft-deleted teacher stayed on this list forever. scopeWithLiveUser()
     * pushes the check into a subquery over User where SoftDeletes applies.
     * A teacher whose account is gone cannot be paid, so listing them on a
     * payout screen was never useful. Recorded in open-decisions 7.11.
     */
    public function test_a_soft_deleted_teacher_drops_off_the_list(): void
    {
        $this->disableRateLimiting();
        [$live] = $this->makeTeacherWithProfile(user: ['full_name' => 'حيّ']);
        [$gone] = $this->makeTeacherWithProfile(user: ['full_name' => 'محذوف']);
        $gone->delete();

        $this->loginAsAdmin();

        $names = array_column($this->getJson('/api/v1/revenue/teachers')->json('data.teachers'), 'name');

        self::assertSame(['حيّ'], $names);
        self::assertNotNull($live->fresh());
    }
}
