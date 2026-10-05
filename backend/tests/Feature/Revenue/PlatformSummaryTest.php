<?php

declare(strict_types=1);

namespace Tests\Feature\Revenue;

use App\Models\RevenueTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /revenue/platform (row 61) and GET /revenue/transactions (row 62) — the
 * summary cards and the table beneath them, on ONE screen, with ONE filter bar.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ TWO PROPERTIES ARE PINNED HERE AND THEY PULL IN OPPOSITE DIRECTIONS.    ║
 * ║                                                                          ║
 * ║ 1. THE TWO ROUTES MUST AGREE about the date/method slice, or Revenue.tsx ║
 * ║    renders all-time totals on top of a filtered table.                   ║
 * ║                                                                          ║
 * ║ 2. `payouts_total` AND `refunds_total` MUST **NOT** MOVE with the date   ║
 * ║    range — an undocumented but entirely real backend/ behaviour, kept    ║
 * ║    deliberately (decision #3). The second test proves the asymmetry      ║
 * ║    EXPLICITLY so that a maintainer who "fixes" it gets a failing test    ║
 * ║    that explains itself rather than silently changing two numbers on a   ║
 * ║    finance screen.                                                       ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class PlatformSummaryTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    /**
     * The summary and the table must describe the same slice under every
     * filter combination the frontend can produce.
     */
    public function test_the_summary_and_the_transaction_table_apply_the_same_filters(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        [$student] = $this->makeStudentWithCode();

        $this->recordRevenue($student, $course, 100.00)
            ->forceFill(['created_at' => '2026-01-10 12:00:00'])->save();
        $this->recordRevenue($student, $course, 200.00)
            ->forceFill(['created_at' => '2026-02-10 12:00:00'])->save();
        $this->recordRevenue($student, $course, 400.00, RevenueTransaction::METHOD_ENROLLMENT_CODE)
            ->forceFill(['created_at' => '2026-02-20 12:00:00'])->save();

        $this->loginAsAdmin();

        foreach ([
            '' => [700.0, 3],
            '?dateFrom=2026-02-01' => [600.0, 2],
            '?dateFrom=2026-02-01&dateTo=2026-02-15' => [200.0, 1],
            '?paymentMethod=enrollment_code' => [400.0, 1],
            '?dateFrom=2026-02-01&paymentMethod=manual_admin' => [200.0, 1],
            // 'All' is Revenue.tsx's default <option> value and MUST be inert.
            '?paymentMethod=All' => [700.0, 3],
            // An unknown query parameter must not filter anything.
            '?nonsense=1' => [700.0, 3],
        ] as $query => [$expectedGross, $expectedCount]) {
            $summary = $this->getJson('/api/v1/revenue/platform' . $query)->assertOk()->json('data.summary');
            $rows = $this->getJson('/api/v1/revenue/transactions' . $query)->assertOk()->json('data.transactions');

            self::assertSame($expectedGross, (float) $summary['gross_revenue'], "gross for '{$query}'");
            self::assertSame($expectedCount, $summary['transactions_count'], "count for '{$query}'");
            self::assertCount($expectedCount, $rows, "table rows for '{$query}'");
            self::assertSame(
                $expectedGross,
                array_sum(array_map(static fn (array $r): float => (float) $r['gross_amount'], $rows)),
                "the table's own total for '{$query}' must equal the card"
            );
        }
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ DECISION #3, PROVEN. THIS TEST DOCUMENTS AN INTENTIONAL              ║
     * ║ INCONSISTENCY — IT IS NOT DESCRIBING A BUG TO BE FIXED.              ║
     * ║                                                                      ║
     * ║ Narrowing the date window changes `gross_revenue` and leaves         ║
     * ║ `payouts_total` and `refunds_total` EXACTLY where they were, because ║
     * ║ backend/ queries those two tables unfiltered. If you are here having ║
     * ║ just made them respect the range, read PlatformSummary's banner      ║
     * ║ before deleting this: `payouts.created_at` is the DATE THE MONEY WAS ║
     * ║ SENT, not the date of the sales it settles, so "payouts in this      ║
     * ║ period" is a product question, not a rewrite detail.                 ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_payouts_and_refunds_totals_ignore_the_date_range_on_purpose(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        [$student] = $this->makeStudentWithCode();

        $old = $this->recordRevenue($student, $course, 100.00);
        $old->forceFill(['created_at' => '2026-01-10 12:00:00'])->save();
        $this->recordRevenue($student, $course, 500.00)
            ->forceFill(['created_at' => '2026-06-10 12:00:00'])->save();

        // Both dated in JANUARY — outside the June window used below.
        $this->makePayout($instructor, 90.00)->forceFill(['created_at' => '2026-01-15 12:00:00'])->save();

        $this->loginAsAdmin();
        $this->postJson('/api/v1/revenue/refunds', ['transactionId' => (string) $old->getKey()])
            ->assertCreated();
        $refundId = $this->getJson('/api/v1/revenue/refunds')->json('data.refunds.0.id');
        $this->postJson('/api/v1/revenue/refunds', ['id' => $refundId, 'status' => 'approved'])->assertOk();

        $wide = $this->getJson('/api/v1/revenue/platform')->json('data.summary');
        $narrow = $this->getJson('/api/v1/revenue/platform?dateFrom=2026-06-01&dateTo=2026-06-30')
            ->json('data.summary');

        // THE FIGURE THAT MOVES.
        self::assertSame(500.0, (float) $wide['gross_revenue'], 'January sale is now refunded, so 500 only.');
        self::assertSame(500.0, (float) $narrow['gross_revenue']);
        self::assertSame(1, $narrow['transactions_count']);

        // THE TWO THAT DO NOT. Both were created in JANUARY; the window is JUNE.
        self::assertSame(90.0, (float) $wide['payouts_total']);
        self::assertSame(90.0, (float) $narrow['payouts_total'], '⚠ decision #3 — unfiltered on purpose.');
        self::assertSame(100.0, (float) $wide['refunds_total']);
        self::assertSame(100.0, (float) $narrow['refunds_total'], '⚠ decision #3 — unfiltered on purpose.');

        // And narrower still, to a window containing nothing at all.
        $empty = $this->getJson('/api/v1/revenue/platform?dateFrom=2030-01-01&dateTo=2030-01-02')
            ->json('data.summary');
        self::assertSame(0.0, (float) $empty['gross_revenue']);
        self::assertSame(0, $empty['transactions_count']);
        self::assertSame(90.0, (float) $empty['payouts_total'], 'Still unfiltered.');
        self::assertSame(100.0, (float) $empty['refunds_total'], 'Still unfiltered.');
    }

    /**
     * `platform_share` IS A REAL DERIVED NUMBER FOR THE FIRST TIME. It was
     * arithmetically meaningless while `teacher_earnings` was unpopulated;
     * Session A's RecordRevenueTransaction is what made the subtrahend real.
     */
    public function test_platform_share_is_gross_minus_teacher_earnings(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->makeProfitSetting($instructor, 60);
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        [$student] = $this->makeStudentWithCode();

        $this->recordRevenue($student, $course, 1000.00); // 600 teacher, 400 platform.

        $this->loginAsAdmin();
        $summary = $this->getJson('/api/v1/revenue/platform')->assertOk()->json('data.summary');

        self::assertSame(1000.0, (float) $summary['gross_revenue']);
        self::assertSame(600.0, (float) $summary['teacher_earnings_total']);
        self::assertSame(400.0, (float) $summary['platform_share']);
        self::assertSame(
            (float) $summary['gross_revenue'] - (float) $summary['teacher_earnings_total'],
            (float) $summary['platform_share'],
            'Derived, never stored — there is no platform_share column.'
        );
    }

    /**
     * THE SUMMARY COUNTS PAID MONEY ONLY; THE TABLE SHOWS REFUNDED ROWS TOO.
     * That asymmetry is backend/'s and is deliberate — an admin must be able
     * to SEE what was refunded, which is impossible if the row vanishes. It is
     * kept OUT of scopeFiltered() so it stays visible at each call site.
     */
    public function test_a_refunded_row_leaves_the_summary_but_stays_in_the_table(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        [$student] = $this->makeStudentWithCode();

        $this->recordRevenue($student, $course, 100.00);
        $this->recordRevenue($student, $course, 900.00)->forceFill(['status' => 'refunded'])->save();

        $this->loginAsAdmin();

        $summary = $this->getJson('/api/v1/revenue/platform')->json('data.summary');
        $rows = $this->getJson('/api/v1/revenue/transactions')->json('data.transactions');

        self::assertSame(100.0, (float) $summary['gross_revenue']);
        self::assertSame(1, $summary['transactions_count']);
        self::assertCount(2, $rows, 'The table must still show the refunded sale.');
    }

    /**
     * `dateTo` IS WIDENED TO 23:59:59. A bare date on the upper bound would
     * silently drop every transaction made that day after midnight — a whole
     * day of revenue missing from a report whose picker says it is included.
     */
    public function test_the_upper_date_bound_includes_the_whole_day(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        [$student] = $this->makeStudentWithCode();

        $this->recordRevenue($student, $course, 100.00)
            ->forceFill(['created_at' => '2026-03-15 23:59:58'])->save();

        $this->loginAsAdmin();

        self::assertSame(
            100.0,
            (float) $this->getJson('/api/v1/revenue/platform?dateTo=2026-03-15')->json('data.summary.gross_revenue')
        );
        self::assertSame(
            100.0,
            (float) $this->getJson('/api/v1/revenue/platform?dateFrom=2026-03-15')->json('data.summary.gross_revenue'),
            'And the lower bound starts at 00:00:00.'
        );
    }

    /**
     * An empty platform reports ZEROES, NEVER NULLS — a COALESCE regression
     * would show blank cards rather than "0 ج.م".
     *
     * ┌── ON `0` VS `0.0` IN THE JSON. ──────────────────────────────────────┐
     * │ These six values are floats in PHP and encode as the JSON number `0` │
     * │ — json_encode() drops the zero fraction unless                       │
     * │ JSON_PRESERVE_ZERO_FRACTION is set, which neither backend/ nor        │
     * │ ApiResponse::JSON_FLAGS sets. So the wire shape is UNCHANGED from     │
     * │ backend/, and the assertions cast rather than pinning a PHP type      │
     * │ that the encoder never preserved in the first place.                  │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_an_empty_platform_reports_zeroes(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $summary = $this->getJson('/api/v1/revenue/platform')->assertOk()->json('data.summary');

        self::assertSame([
            'gross_revenue',
            'teacher_earnings_total',
            'platform_share',
            'transactions_count',
            'payouts_total',
            'refunds_total',
        ], array_keys($summary), 'The six keys and their order are the contract.');

        foreach ($summary as $key => $value) {
            self::assertNotNull($value, "{$key} must be 0, never null.");
            self::assertSame(0.0, (float) $value, $key);
        }
    }
}
