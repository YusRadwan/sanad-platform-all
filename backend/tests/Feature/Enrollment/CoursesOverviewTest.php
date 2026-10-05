<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\SubscriptionStatus;
use App\Models\RevenueTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/admin/courses/overview — freeze-table row 55.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE FIRST TEST IS THE ONE backend/ WOULD HAVE CAUGHT ITS FAN-OUT BUG    ║
 * ║ WITH, AND IT IS SHAPED FOR EXACTLY THAT.                                 ║
 * ║                                                                          ║
 * ║ 3 subscriptions and 2 transactions is the minimum fixture in which every ║
 * ║ wrong answer is a DIFFERENT number from the right one:                   ║
 * ║                                                                          ║
 * ║   correct                 count 3   revenue 200.00                       ║
 * ║   joined (cross product)  count 6   revenue 600.00                       ║
 * ║   joined + COUNT DISTINCT count 3   revenue 600.00  ← what shipped       ║
 * ║                                                                          ║
 * ║ The third row is why the original bug survived review: the count was     ║
 * ║ right, so the money looked like an arithmetic error rather than a query- ║
 * ║ shape error. Asserting BOTH numbers exactly — not "greater than zero",   ║
 * ║ not "roughly" — is what makes this a regression test instead of a smoke  ║
 * ║ test.                                                                    ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class CoursesOverviewTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    public function test_three_subscriptions_and_two_transactions_do_not_multiply(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile(user: ['full_name' => 'أ. سند']);
        $course = $this->makeCourseFor($instructor, [
            'title' => 'كورس السباق',
            'price' => 100,
            'is_published' => true,
        ]);

        $students = [];

        for ($i = 0; $i < 3; $i++) {
            [$student] = $this->makeStudentWithCode();
            $students[] = $student;
            $this->makeSubscription($student, $course);
        }

        // EXACTLY TWO transactions, totalling a number that is not a multiple
        // of itself by 3 in any confusing way: 120 + 80 = 200.
        $this->recordRevenue($students[0], $course, 120.00);
        $this->recordRevenue($students[1], $course, 80.00);

        $this->loginAsAdmin();

        $row = $this->getJson('/api/v1/admin/courses/overview')->json('data.overview.0');

        self::assertSame(3, $row['subscriptions_count'], 'A fan-out would report 6.');
        self::assertSame(3, $row['enrolled_students']);
        self::assertSame(200.0, (float) $row['revenue'], 'A fan-out would report 600.00.');
        self::assertSame('أ. سند', $row['teacher_name']);
        self::assertSame('كورس السباق', $row['title']);
        self::assertTrue($row['is_published']);
        self::assertSame((string) $instructor->getKey(), $row['teacher_id']);
    }

    /**
     * THE TWO KEYS ARE THE SAME NUMBER AND BOTH MUST SHIP. Two different
     * frontend screens read different spellings; consolidating them would
     * blank a column on one of them with nothing failing to say why.
     */
    public function test_both_count_keys_are_present_and_equal(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        [$student] = $this->makeStudentWithCode();
        $this->makeSubscription($student, $course);

        $this->loginAsAdmin();

        $row = $this->getJson('/api/v1/admin/courses/overview')->json('data.overview.0');

        self::assertArrayHasKey('subscriptions_count', $row);
        self::assertArrayHasKey('enrolled_students', $row);
        self::assertSame($row['subscriptions_count'], $row['enrolled_students']);
        self::assertSame(1, $row['subscriptions_count']);
    }

    /** Cancelled enrolments are not enrolments. */
    public function test_only_active_subscriptions_are_counted(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);

        foreach ([SubscriptionStatus::Active, SubscriptionStatus::Cancelled, SubscriptionStatus::Expired] as $status) {
            [$student] = $this->makeStudentWithCode();
            $this->makeSubscription($student, $course, $status);
        }

        $this->loginAsAdmin();

        $row = $this->getJson('/api/v1/admin/courses/overview')->json('data.overview.0');

        self::assertSame(1, $row['subscriptions_count']);
    }

    /**
     * ONLY `status = 'paid'` COUNTS AS MONEY — RevenueTransaction::scopePaid()
     * is the canonical predicate and it is the one this endpoint uses.
     */
    public function test_a_non_paid_transaction_is_excluded_from_the_revenue_sum(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        [$student] = $this->makeStudentWithCode();

        $this->recordRevenue($student, $course, 100.00);
        $refunded = $this->recordRevenue($student, $course, 100.00);
        $refunded->forceFill(['status' => 'refunded'])->save();

        $this->loginAsAdmin();

        $row = $this->getJson('/api/v1/admin/courses/overview')->json('data.overview.0');

        self::assertSame(100.0, (float) $row['revenue']);
        self::assertSame(2, RevenueTransaction::query()->count());
    }

    /** A course with nothing attached reports zeroes, never nulls. */
    public function test_a_course_with_no_enrolments_reports_zeroes_not_nulls(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->makeCourseFor($instructor);

        $this->loginAsAdmin();

        $row = $this->getJson('/api/v1/admin/courses/overview')->json('data.overview.0');

        self::assertSame(0, $row['subscriptions_count']);
        self::assertSame(0, $row['enrolled_students']);
        self::assertSame(0.0, (float) $row['revenue']);
        self::assertNotNull($row['revenue']);
    }

    /**
     * ONE COURSE'S NUMBERS NEVER LEAK INTO ANOTHER'S. The correlated
     * subqueries are per-course by construction; this proves it over a set
     * rather than over the single row every other test here looks at.
     */
    public function test_each_courses_aggregates_are_its_own(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $busy = $this->makeCourseFor($instructor, ['title' => 'مزدحم', 'price' => 50]);
        $quiet = $this->makeCourseFor($instructor, ['title' => 'هادئ', 'price' => 50]);

        for ($i = 0; $i < 4; $i++) {
            [$student] = $this->makeStudentWithCode();
            $this->makeSubscription($student, $busy);
            $this->recordRevenue($student, $busy, 50.00);
        }

        [$lonely] = $this->makeStudentWithCode();
        $this->makeSubscription($lonely, $quiet);
        $this->recordRevenue($lonely, $quiet, 50.00);

        $this->loginAsAdmin();

        $rows = collect($this->getJson('/api/v1/admin/courses/overview')->json('data.overview'))
            ->keyBy('title');

        self::assertSame(4, $rows['مزدحم']['subscriptions_count']);
        self::assertSame(200.0, (float) $rows['مزدحم']['revenue']);
        self::assertSame(1, $rows['هادئ']['subscriptions_count']);
        self::assertSame(50.0, (float) $rows['هادئ']['revenue']);
    }

    public function test_soft_deleted_courses_are_absent(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->makeCourseFor($instructor, ['title' => 'حيّ']);
        $gone = $this->makeCourseFor($instructor, ['title' => 'محذوف']);
        $gone->delete();

        $this->loginAsAdmin();

        $titles = array_column(
            $this->getJson('/api/v1/admin/courses/overview')->json('data.overview'),
            'title'
        );

        self::assertSame(['حيّ'], $titles);
    }

    /**
     * DRAFTS ARE INCLUDED. This is an admin's operational inventory, not a
     * catalogue: decision 3.6 hides unpublished courses from OTHERS, and an
     * admin is never "others".
     */
    public function test_unpublished_courses_are_included(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->makeCourseFor($instructor, ['title' => 'مسودة', 'is_published' => false]);

        $this->loginAsAdmin();

        $row = $this->getJson('/api/v1/admin/courses/overview')->json('data.overview.0');

        self::assertSame('مسودة', $row['title']);
        self::assertFalse($row['is_published']);
    }
}
