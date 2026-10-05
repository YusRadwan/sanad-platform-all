<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\SubscriptionStatus;
use App\Models\Course;
use App\Models\Refund;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/admin/dashboard — freeze-table row 52. The nine tiles on
 * AdminHome.tsx.
 */
final class AdminDashboardStatsTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    /**
     * The exact keys, in DashboardStats::__invoke()'s exact order. Started as
     * "the nine tiles" (backend/'s original contract); `students_subscribed`,
     * `students_unsubscribed` and `students_by_governorate` were added by
     * open decision 10.16, and `monthly_revenue` (the "الإيرادات الشهرية"
     * card on AdminHome.tsx) was added after — this list must always match
     * the action's real key order, not the original nine.
     */
    private const KEYS = [
        'exams_count',
        'users_count',
        'teachers_count',
        'students_count',
        'courses_count',
        'published_courses_count',
        'active_subscriptions',
        'total_revenue',
        'monthly_revenue',
        'pending_refunds',
        'students_subscribed',
        'students_unsubscribed',
        'students_by_governorate',
    ];

    public function test_all_nine_counters_are_present_in_the_contract_order(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $stats = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.stats');

        self::assertSame(self::KEYS, array_keys($stats), 'All keys, in backend/`s order.');

        foreach ($stats as $key => $value) {
            self::assertNotNull($value, "{$key} must never be null.");
        }
    }

    /**
     * EVERY COUNTER, AGAINST A KNOWN FIXTURE. Each number is different from
     * every other so a mis-wired key cannot pass by coincidence.
     */
    public function test_every_counter_reports_the_right_number(): void
    {
        $this->disableRateLimiting();

        // 3 instructors, 2 students, 1 admin -> 6 users.
        [$a] = $this->makeTeacherWithProfile();
        [$b] = $this->makeTeacherWithProfile();
        [$c] = $this->makeTeacherWithProfile();
        [$s1] = $this->makeStudentWithCode();
        [$s2] = $this->makeStudentWithCode();

        // 4 courses, 2 of them published.
        $published = $this->makeCourseFor($a, ['is_published' => true, 'price' => 100]);
        $this->makeCourseFor($b, ['is_published' => true]);
        $this->makeCourseFor($c, ['is_published' => false]);
        $this->makeCourseFor($a, ['is_published' => false]);

        // 2 active subscriptions + 1 cancelled (which must NOT count).
        $this->makeSubscription($s1, $published);
        $this->makeSubscription($s2, $published, SubscriptionStatus::Cancelled);
        $this->makeSubscription($s2, $this->makeCourseFor($b, ['is_published' => true]));

        // Revenue: 300 paid + 500 refunded -> total_revenue is GROSS PAID only.
        $paid = $this->recordRevenue($s1, $published, 300.00);
        $this->recordRevenue($s2, $published, 500.00)->forceFill(['status' => 'refunded'])->save();

        // 1 pending refund + 1 rejected (which must NOT count).
        Refund::query()->create([
            'revenue_transaction_id' => $paid->getKey(),
            'amount' => 300.00,
            'reason' => '',
            'status' => 'pending',
        ]);
        Refund::query()->create([
            'revenue_transaction_id' => $paid->getKey(),
            'amount' => 300.00,
            'reason' => '',
            'status' => 'rejected',
        ]);

        $admin = $this->loginAsAdmin();
        $stats = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.stats');

        self::assertSame(0, $stats['exams_count']);
        self::assertSame(6, $stats['users_count'], '3 instructors + 2 students + 1 admin.');
        self::assertSame(3, $stats['teachers_count']);
        self::assertSame(2, $stats['students_count']);
        self::assertSame(5, $stats['courses_count'], '4 above plus the published one made inline.');
        self::assertSame(3, $stats['published_courses_count']);
        self::assertSame(2, $stats['active_subscriptions'], 'Cancelled is not active.');
        self::assertSame(300.0, (float) $stats['total_revenue'], 'Refunded gross does not count.');
        self::assertSame(300.0, (float) $stats['monthly_revenue'], 'recordRevenue() defaults created_at to now, so it falls inside this month.');
        self::assertSame(1, $stats['pending_refunds'], 'Rejected is not pending.');
        self::assertNotNull($admin);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ SESSION B DECISION #4, MEASURED. THE BEFORE/AFTER IS THE TEST.      ║
     * ║                                                                      ║
     * ║ backend/ counted `SELECT COUNT(*) FROM users`, `… FROM teachers` and ║
     * ║ `… FROM students` with NO deleted_at clause anywhere, so all three   ║
     * ║ tiles were frozen: deleting an account never moved them. An admin    ║
     * ║ who deletes a user and watches "المستخدمون" stay put has been told   ║
     * ║ the delete failed.                                                   ║
     * ║                                                                      ║
     * ║ AFTER: all three shrink. `users` via SoftDeletes' global scope;      ║
     * ║ `teachers`/`students` via whereHas('user'), because those two tables ║
     * ║ have NO deleted_at of their own — Stage 4's deletes write only       ║
     * ║ `users.deleted_at` and leave the profile row intact by design.       ║
     * ║                                                                      ║
     * ║ THE PROFILE ROWS ARE ASSERTED TO STILL EXIST. That is what makes     ║
     * ║ this a test of the CORRECTION rather than of a cascade: if the       ║
     * ║ `teachers` row had been deleted too, a naive count would also have   ║
     * ║ shrunk and this test would pass while proving nothing.               ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_soft_deleting_a_user_shrinks_exactly_the_three_corrected_counters(): void
    {
        $this->disableRateLimiting();
        [$liveTeacher] = $this->makeTeacherWithProfile();
        [$doomedTeacher] = $this->makeTeacherWithProfile();
        [$liveStudent] = $this->makeStudentWithCode();
        [$doomedStudent] = $this->makeStudentWithCode();
        $this->makeCourseFor($liveTeacher, ['is_published' => true]);

        $this->loginAsAdmin();
        $before = $this->getJson('/api/v1/admin/dashboard')->json('data.stats');

        self::assertSame(5, $before['users_count']); // 4 + the admin
        self::assertSame(2, $before['teachers_count']);
        self::assertSame(2, $before['students_count']);

        // Stage 4's delete: `users.deleted_at` ONLY.
        $doomedTeacher->delete();
        $doomedStudent->delete();

        $after = $this->getJson('/api/v1/admin/dashboard')->json('data.stats');

        // ── THE THREE THAT MOVED (backend/ would still report 5 / 2 / 2) ──
        self::assertSame(3, $after['users_count'], '⚠ #4 — backend/ reported 5.');
        self::assertSame(1, $after['teachers_count'], '⚠ #4 — backend/ reported 2.');
        self::assertSame(1, $after['students_count'], '⚠ #4 — backend/ reported 2.');

        // ── THE PROFILE ROWS SURVIVE, which is why the naive count was wrong.
        self::assertSame(2, Teacher::query()->withoutGlobalScopes()->count());
        self::assertSame(2, Student::query()->withoutGlobalScopes()->count());
        self::assertNotNull(User::withTrashed()->find($doomedTeacher->getKey()));

        // ── THE COUNTERS THAT DID NOT MOVE ──
        foreach ([
            'exams_count',
            'courses_count',
            'published_courses_count',
            'active_subscriptions',
            'total_revenue',
            'monthly_revenue',
            'pending_refunds',
        ] as $key) {
            self::assertSame($before[$key], $after[$key], "{$key} must be unaffected by a user delete.");
        }
        self::assertSame($liveStudent->getKey(), $liveStudent->fresh()?->getKey());
    }

    /**
     * `courses_count` AND `published_courses_count` ARE **UNCHANGED** FROM
     * backend/, and that is worth pinning rather than inferring: backend/
     * hand-wrote `WHERE deleted_at IS NULL` on both, and Course's SoftDeletes
     * global scope produces the identical predicate. A fourth "correction"
     * here would have been a regression against a query that was already right.
     */
    public function test_the_course_counters_match_backends_hand_written_filter(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->makeCourseFor($instructor, ['is_published' => true]);
        $doomedPublished = $this->makeCourseFor($instructor, ['is_published' => true]);
        $this->makeCourseFor($instructor, ['is_published' => false]);

        $this->loginAsAdmin();
        $before = $this->getJson('/api/v1/admin/dashboard')->json('data.stats');
        self::assertSame(3, $before['courses_count']);
        self::assertSame(2, $before['published_courses_count']);

        $doomedPublished->delete();

        $after = $this->getJson('/api/v1/admin/dashboard')->json('data.stats');
        self::assertSame(2, $after['courses_count']);
        self::assertSame(1, $after['published_courses_count']);

        // The row is still there — soft, not hard.
        self::assertSame(3, Course::withTrashed()->count());
    }

    /**
     * `active_subscriptions` COUNTS THE **STORED STATUS**, not
     * Subscription::scopeActive(). Same deliberate departure open-decisions
     * 7.5 records for courses/overview: using the entitlement scope would make
     * an admin's headline number drop by itself as dates pass, disagreeing
     * with the subscription LIST on the neighbouring screen.
     */
    public function test_an_expired_dated_but_active_status_subscription_still_counts(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        [$student] = $this->makeStudentWithCode();

        // status='active' but the date is long past — scopeActive() would
        // exclude it; this counter must NOT.
        $this->makeSubscription($student, $course, SubscriptionStatus::Active, now()->subYear());

        $this->loginAsAdmin();

        self::assertSame(
            1,
            $this->getJson('/api/v1/admin/dashboard')->json('data.stats.active_subscriptions')
        );
    }

    /** An empty platform reports zeroes on every numeric counter, never nulls. */
    public function test_an_empty_platform_reports_zeroes(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $stats = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.stats');

        // `students_by_governorate` is a list, not a counter — casting it to
        // float would silently pass (PHP casts an empty array to 0.0) for
        // the wrong reason, so it gets its own real assertion instead.
        self::assertSame([], $stats['students_by_governorate']);

        foreach (self::KEYS as $key) {
            if ($key === 'students_by_governorate') {
                continue;
            }
            // users_count is 1: the admin who is making the request exists.
            $expected = $key === 'users_count' ? 1.0 : 0.0;
            self::assertSame($expected, (float) $stats[$key], $key);
        }
    }

    /**
     * `monthly_revenue` IS SCOPED TO THE CURRENT CALENDAR MONTH, `total_revenue`
     * IS NOT. A paid transaction dated last month must still count toward the
     * all-time total but drop out of the monthly figure — proving the
     * `whereBetween(startOfMonth, endOfMonth)` clause actually filters,
     * rather than merely happening to equal `total_revenue` because every
     * fixture in the other tests is created "now".
     */
    public function test_monthly_revenue_excludes_a_transaction_from_last_month(): void
    {
        $this->disableRateLimiting();
        [$teacher] = $this->makeTeacherWithProfile();
        [$student] = $this->makeStudentWithCode();
        $course = $this->makeCourseFor($teacher, ['is_published' => true, 'price' => 100]);

        $thisMonth = $this->recordRevenue($student, $course, 300.00);
        $lastMonth = $this->recordRevenue($student, $course, 500.00);
        $lastMonth->forceFill(['created_at' => now()->subMonthNoOverflow()])->save();

        $this->loginAsAdmin();
        $stats = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.stats');

        self::assertSame(800.0, (float) $stats['total_revenue'], 'Both transactions count toward the all-time total.');
        self::assertSame(300.0, (float) $stats['monthly_revenue'], 'Only the current-month transaction counts here.');
    }

    /**
     * NO REQUEST INPUT IS READ AT ALL. There are no filters on this route, so
     * a query string must be completely inert — not validated away, simply
     * never consulted.
     */
    public function test_query_parameters_are_inert(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $this->makeCourseFor($instructor, ['is_published' => true]);
        $this->loginAsAdmin();

        $plain = $this->getJson('/api/v1/admin/dashboard')->json('data.stats');
        $noisy = $this->getJson('/api/v1/admin/dashboard?dateFrom=2020-01-01&teacher_id=x&published=0')
            ->assertOk()->json('data.stats');

        self::assertSame($plain, $noisy);
    }
}
