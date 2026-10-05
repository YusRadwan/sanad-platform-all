<?php

declare(strict_types=1);

namespace App\Actions\Enrollment;

use App\Enums\SubscriptionStatus;
use App\Models\Course;
use Illuminate\Database\Eloquent\Builder;

/**
 * GET /api/v1/admin/courses/overview — freeze-table row 55.
 *
 * One row per live course: title, publication state, owning instructor,
 * enrolled-student count, and gross paid revenue.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE CARTESIAN FAN-OUT BUG. IT WAS REAL, IT WAS FOUND IN PRODUCTION, AND ║
 * ║ IT IS TRIVIAL TO REINTRODUCE HERE.                                       ║
 * ║                                                                          ║
 * ║ backend/SubscriptionRepository::coursesOverview() records the incident   ║
 * ║ in its own comment. The original query LEFT JOINed BOTH `subscriptions`  ║
 * ║ and `revenue_transactions` onto `courses` and aggregated in one pass.    ║
 * ║ A join of two independent one-to-many edges produces their CROSS         ║
 * ║ PRODUCT: a course with 3 subscriptions and 2 transactions yields 6 rows, ║
 * ║ each transaction appearing 3 times. So:                                  ║
 * ║                                                                          ║
 * ║     SUM(rt.gross_amount)      -> 3x the real revenue                     ║
 * ║     COUNT(DISTINCT s.id)      -> CORRECT                                 ║
 * ║                                                                          ║
 * ║ …and that asymmetry is why it survived review. The DISTINCT masked the   ║
 * ║ fan-out on the count, so the count looked right and only the money       ║
 * ║ column was wrong — which reads as "the revenue calculation is buggy"     ║
 * ║ rather than "the query shape is wrong". Measured live at the time: a     ║
 * ║ course with 3 active subscriptions and 200.00 of real revenue reported   ║
 * ║ 600.00.                                                                  ║
 * ║                                                                          ║
 * ║ `withCount()` AND `withSum()` COMPILE TO CORRELATED SUBQUERIES IN THE    ║
 * ║ SELECT LIST — never to joins. Each aggregate is therefore computed over  ║
 * ║ its own row set and is structurally incapable of being multiplied by a   ║
 * ║ sibling aggregate's cardinality. THIS IS THE ENTIRE REASON THEY ARE      ║
 * ║ USED HERE. Adding a `->join('revenue_transactions', …)` to this query    ║
 * ║ for any reason reintroduces a bug that has already cost this product     ║
 * ║ once.                                                                    ║
 * ║                                                                          ║
 * ║ Pinned by CoursesOverviewTest::                                          ║
 * ║ test_three_subscriptions_and_two_transactions_do_not_multiply — 3        ║
 * ║ subscriptions, 2 transactions of a known total, asserting the count is   ║
 * ║ exactly 3 (not 6) and the sum is exactly the two-transaction total (not  ║
 * ║ doubled or tripled).                                                     ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── `subscriptions_count` AND `enrolled_students` ARE THE SAME NUMBER      ┐
 * │   UNDER TWO NAMES, AND BOTH MUST SHIP.                                   │
 * │                                                                          │
 * │ Two different frontend consumers read this one endpoint and they         │
 * │ disagree about the key: Statistics.tsx ("أداء الكورسات الدراسية")        │
 * │ renders `c.enrolled_students`, ManageSubscriptions.tsx's course picker   │
 * │ reads `subscriptions_count`. backend/ emits both deliberately — its      │
 * │ comment says so — and consolidating them here would blank a column on    │
 * │ one of the two screens with nothing failing anywhere to say why. It is   │
 * │ duplication in the payload, not in the query: `withCount` is given two   │
 * │ aliases for one relation and MySQL computes the subquery twice, which    │
 * │ costs a second index scan over an already-indexed FK column.             │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * WHAT COUNTS AS AN ENROLLED STUDENT HERE IS `status = 'active'` ALONE, **not**
 * Subscription::scopeActive(). That is a deliberate departure and it is
 * backend/'s behaviour: the overview's subquery reads
 * `WHERE s.status = "active"` with no `expires_at` clause. The scope is the
 * canonical definition of ENTITLEMENT — who may open the content — and using
 * it here would make an admin's enrollment figure drop by itself as dates
 * pass, disagreeing with the per-student list on the neighbouring screen
 * (which shows stored status too). Since no scheduled job ever writes
 * 'expired' in this product (Stage 7 decision #5), the two are identical in
 * practice today; the distinction is recorded so a future expiry job does not
 * silently change this number. See docs/open-decisions.md (7.5).
 */
final class CoursesOverview
{
    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(): array
    {
        return Course::query()
            // Soft-deleted courses are excluded by Course's global scope —
            // backend/ had to remember `WHERE c.deleted_at IS NULL` by hand.
            ->with('teacher.user:id,full_name')
            ->withCount([
                'subscriptions as subscriptions_count' => $this->activeOnly(),
                'subscriptions as enrolled_students' => $this->activeOnly(),
            ])
            ->withSum(
                ['revenueTransactions as revenue' => fn (Builder $q): Builder => $q->paid()],
                'gross_amount'
            )
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Course $course): array => [
                'id' => (string) $course->getKey(),
                'title' => $course->title,
                'is_published' => $course->is_published,
                'teacher_id' => $course->teacher_id !== null ? (string) $course->teacher_id : null,
                'teacher_name' => $course->teacher?->user?->full_name,
                'subscriptions_count' => (int) $course->getAttribute('subscriptions_count'),
                'enrolled_students' => (int) $course->getAttribute('enrolled_students'),
                // COALESCE to 0: withSum yields NULL for a course with no
                // transactions, and backend/ emitted 0. A float, matching
                // backend/'s `(float)` presentation — this value is displayed,
                // never written back.
                'revenue' => (float) ($course->getAttribute('revenue') ?? 0),
            ])
            ->all();
    }

    /**
     * Written as a factory rather than a shared closure variable so the two
     * aliases cannot accidentally share a mutated Builder — `withCount` hands
     * each constraint its own query instance, and reusing one closure is
     * safe today but expresses "these are the same filter" rather than
     * "these each need this filter".
     */
    private function activeOnly(): callable
    {
        return fn (Builder $q): Builder => $q->where('status', SubscriptionStatus::Active->value);
    }
}
