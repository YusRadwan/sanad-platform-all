<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\RefundStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Course;
use App\Models\Exam;
use App\Models\Refund;
use App\Models\RevenueTransaction;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/admin/dashboard — freeze-table row 52. The nine tiles on
 * AdminHome.tsx.
 *
 * Port of backend/AdminRepository::dashboardStats(). The KEY NAMES AND THEIR
 * ORDER are the contract and are reproduced exactly, `exams_count` first
 * included — AdminHome.tsx reads them by name, but the order is preserved
 * because a diff of the two payloads should be empty, not merely equivalent.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THREE OF THE NINE COUNTERS NOW ANSWER DIFFERENTLY, AND THE CHANGE IS     ║
 * ║ ACCEPTED ON PURPOSE (Session B decision #4). DO NOT SUPPRESS IT.         ║
 * ║                                                                          ║
 * ║  counter          backend/                        backend-v2             ║
 * ║  ───────────────  ──────────────────────────────  ───────────────────────║
 * ║  users_count      SELECT COUNT(*) FROM users      SoftDeletes global     ║
 * ║                   — counts SOFT-DELETED users     scope excludes them    ║
 * ║  teachers_count   COUNT(*) FROM teachers          whereHas('user')       ║
 * ║  students_count   COUNT(*) FROM students          whereHas('user')       ║
 * ║                                                                          ║
 * ║ WHY teachers/students NEED whereHas AND users DOES NOT: `users` carries  ║
 * ║ `deleted_at` and User uses SoftDeletes, so its global scope filters      ║
 * ║ automatically. `teachers` and `students` have NO deleted_at of their own ║
 * ║ — Stage 4's three delete paths write ONLY `users.deleted_at` and leave   ║
 * ║ the profile row intact by design (Teacher::user()'s banner) — so a naive ║
 * ║ Teacher::count() would keep counting the profiles of deleted accounts    ║
 * ║ and the three tiles would disagree with each other. whereHas('user')     ║
 * ║ pushes the check into a subquery over User, where the global scope       ║
 * ║ applies for free and there is no hand-written `deleted_at IS NULL` to    ║
 * ║ forget. This is the pattern Stage 4 established as                       ║
 * ║ Teacher::scopeWithLiveUser(); it is REUSED, not reinvented.              ║
 * ║                                                                          ║
 * ║ Direction of the change: these three tiles get SMALLER after a delete,   ║
 * ║ where before they never moved. That is what the tiles are for — an admin ║
 * ║ who deletes an account and watches "المستخدمون" stay put has been told   ║
 * ║ the delete failed. Documented with before/after semantics in             ║
 * ║ docs/open-decisions.md (7.12) and pinned by                              ║
 * ║ AdminDashboardStatsTest::                                                ║
 * ║ test_soft_deleting_a_user_shrinks_exactly_the_three_corrected_counters.  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * `courses_count` AND `published_courses_count` ARE **UNCHANGED**, and that is
 * worth stating rather than leaving to inference: backend/ hand-wrote
 * `WHERE deleted_at IS NULL` on both, and Course's SoftDeletes global scope
 * produces the identical predicate. Verified, not assumed — a fourth
 * "correction" here would have been a real regression against a query that was
 * already right.
 *
 * `active_subscriptions` COUNTS THE STORED STATUS, NOT Subscription::
 * scopeActive(). Deliberate, and the same departure open-decisions 7.5 records
 * for courses/overview: backend/ reads `WHERE status = 'active'` with no
 * `expires_at` clause, and using the entitlement scope here would make an
 * admin's headline number drop by itself as dates pass while the subscription
 * LIST on the neighbouring screen — which shows stored status — did not.
 *
 * `total_revenue` IS GROSS, NOT NET, and it is `scopePaid()`-filtered. It is
 * the same figure PlatformSummary calls `gross_revenue` with no date filter.
 *
 * NO CACHING. `Cache::remember` was considered and rejected for this session:
 * nine indexed aggregates on an admin-only route is not a measured problem,
 * and a cache would need explicit invalidation from every write path in the
 * product (or a TTL that makes the "delete shrank the counter" behaviour above
 * intermittently untrue, which is exactly the property just fixed). Noted as a
 * possible future optimisation in docs/open-decisions.md (7.13).
 */
final class DashboardStats
{
    /**
     * @return array{
     *     exams_count: int,
     *     users_count: int,
     *     teachers_count: int,
     *     students_count: int,
     *     courses_count: int,
     *     published_courses_count: int,
     *     active_subscriptions: int,
     *     total_revenue: float,
     *     monthly_revenue: float,
     *     pending_refunds: int,
     *     students_subscribed: int,
     *     students_unsubscribed: int,
     *     students_by_governorate: list<array{governorate: string, count: int}>
     * }
     */
    public function __invoke(): array
    {
        // Shared across the three NEW fields below (open decision 10.16) so
        // "subscribed" and "unsubscribed" always sum to exactly this number —
        // computed once rather than risking two counts drifting apart.
        $studentsCount = Student::query()->whereHas('user')->count();

        $studentsSubscribed = Student::query()
            ->whereHas('user')
            ->whereHas('subscriptions', fn ($q) => $q->where('status', SubscriptionStatus::Active->value))
            ->count();

        return [
            'exams_count' => Exam::query()->count(),
            // SoftDeletes' global scope does the deleted_at filtering. ⚠ #4.
            'users_count' => User::query()->count(),
            // ⚠ #4 — no deleted_at of their own; the check lives on `users`.
            'teachers_count' => Teacher::query()->withLiveUser()->count(),
            'students_count' => $studentsCount,
            'courses_count' => Course::query()->count(),
            'published_courses_count' => Course::query()->where('is_published', true)->count(),
            'active_subscriptions' => Subscription::query()
                ->where('status', SubscriptionStatus::Active->value)
                ->count(),
            'total_revenue' => (float) RevenueTransaction::query()->paid()->sum('gross_amount'),
            // NEW — the "الإيرادات الشهرية" card on AdminHome.tsx. Scoped to
            // the CURRENT calendar month by `created_at`, so it recalculates
            // on its own every request once a new month starts; no cron, no
            // cache, no stored "current period" marker to roll over.
            'monthly_revenue' => (float) RevenueTransaction::query()->paid()
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('gross_amount'),
            'pending_refunds' => Refund::query()
                ->where('status', RefundStatus::Pending->value)
                ->count(),
            // NEW (open decision 10.16) — the admin "subscription comparison"
            // donut on Statistics.tsx. "Subscribed" means at least one ACTIVE
            // subscription (the same stored-status convention active_subscriptions
            // above already uses, not Subscription::scopeActive()'s entitlement
            // check — consistent with this whole action's documented stance).
            'students_subscribed' => $studentsSubscribed,
            'students_unsubscribed' => $studentsCount - $studentsSubscribed,
            // NEW (open decision 10.16) — the "students per governorate"
            // bar-list on AdminHome.tsx. Every student with a live account,
            // grouped by their own free-text `governorate` column (no FK —
            // see the `governorates` table removal, decision 10.10), counted
            // regardless of subscription status: "من إجمالي الطلاب الذين
            // أنشأوا حساباً" (every signed-up student, not just paying ones).
            // Pattern lifted from TeacherStatistics::governorateStats(), which
            // is the same query scoped to one teacher's own students instead.
            'students_by_governorate' => Student::query()
                ->whereHas('user')
                ->groupBy('governorate')
                ->orderByDesc('count')
                ->select(['governorate', DB::raw('COUNT(*) as count')])
                ->get()
                ->map(fn ($row) => ['governorate' => (string) $row->governorate, 'count' => (int) $row->count])
                ->all(),
        ];
    }
}
