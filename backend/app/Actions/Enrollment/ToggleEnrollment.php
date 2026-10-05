<?php

declare(strict_types=1);

namespace App\Actions\Enrollment;

use App\Actions\Revenue\RecordRevenueTransaction;
use App\Enums\SubscriptionStatus;
use App\Exceptions\NotFoundException;
use App\Models\Course;
use App\Models\RevenueTransaction;
use App\Models\Subscription;
use App\Support\CoursePricing;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * PUT /api/v1/admin/enrollments/toggle — freeze-table row 54.
 *
 * The admin's manual override: grant a student access to a course, or revoke
 * it, without a code and without payment.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE TWO DIRECTIONS ARE **ASYMMETRIC**, AND THE ASYMMETRY IS THE POINT.  ║
 * ║                                                                          ║
 * ║   CANCEL  requires an existing row (404 if absent) and writes exactly    ║
 * ║           ONE column on ONE table. NO TRANSACTION — see below.           ║
 * ║   GRANT   requires an existing COURSE (404 if absent), may CREATE the    ║
 * ║           subscription, and may write a second table. Transactional.     ║
 * ║                                                                          ║
 * ║ Collapsing them into one symmetric "set status" would be tidier and      ║
 * ║ wrong in both directions: cancelling would silently create a cancelled   ║
 * ║ row for a student who was never enrolled (the 404 the admin UI relies on ║
 * ║ to say "there is nothing to cancel" would disappear), and granting would ║
 * ║ lose the revenue write.                                                  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── WHY CANCEL IS **NOT** WRAPPED IN `DB::transaction()`. ─────────────────┐
 * │ It is a single UPDATE against a single table. A transaction around one   │
 * │ statement adds a BEGIN/COMMIT round-trip and buys nothing — the          │
 * │ statement is already atomic. backend/AdminDashboardService::             │
 * │ toggleEnrollment() makes exactly this distinction (its transaction wraps │
 * │ the grant branch only), and it is preserved rather than "tidied" into    │
 * │ blanket symmetry. Stage 5 decision #3 says every MULTI-STEP write is     │
 * │ transactional; this is not a multi-step write.                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * THE GRANT BRANCH IS THE SAME SHAPE AS CODE REDEMPTION — same
 * ActivateSubscription, same `was_created && price > 0` revenue gate, same
 * pricing rule — differing in exactly one value: `payment_method`. That string
 * is `manual_admin` here and `enrollment_code` there, it flows into
 * GET /admin/subscriptions as `paymentMethod` and into Session B's revenue
 * filtering, and it is taken from a constant on RevenueTransaction rather than
 * typed at either call site.
 *
 * NOTE THAT A MANUAL GRANT **DOES** RECORD REVENUE. It looks odd — no money
 * changed hands through the platform — but it is backend/'s deliberate
 * behaviour and the reason is that off-platform payments (cash, bank
 * transfer) are settled by an admin flipping this toggle. Not recording them
 * would leave the instructor unpaid for every such sale. Changing this is a
 * product decision, not a rewrite decision.
 */
final class ToggleEnrollment
{
    public const NO_SUBSCRIPTION = 'لا يوجد اشتراك لهذا الطالب في هذه الدورة';

    public const COURSE_NOT_FOUND = 'الدورة غير موجودة';

    public function __construct(
        private readonly ActivateSubscription $activate,
        private readonly RecordRevenueTransaction $record,
    ) {
    }

    public function __invoke(string $studentId, string $courseId, bool $isActive): Subscription
    {
        return $isActive
            ? $this->grant($studentId, $courseId)
            : $this->cancel($studentId, $courseId);
    }

    /**
     * DEACTIVATION ONLY EVER TOUCHES AN EXISTING ROW; there is nothing to
     * create. A missing row is a 404 with backend/'s literal string, not a
     * silent success — the admin asked to revoke access that does not exist,
     * and answering 200 would let them believe they had revoked something.
     *
     * The row is looked up and then written through the MODEL rather than by a
     * blind `where(...)->update(...)`, because the caller needs the resulting
     * row back and because the 404 has to be decided before any write.
     */
    private function cancel(string $studentId, string $courseId): Subscription
    {
        $subscription = Subscription::query()
            ->where('student_id', $studentId)
            ->where('course_id', $courseId)
            ->first()
            ?? throw new NotFoundException(self::NO_SUBSCRIPTION);

        $subscription->status = SubscriptionStatus::Cancelled;
        $subscription->save();

        SecurityLog::criticalOperation('Subscription cancelled', [
            'event' => 'subscription.cancelled',
            'subscription_id' => $subscription->getKey(),
            'student_id' => $studentId,
            'course_id' => $courseId,
        ]);

        return $subscription;
    }

    /**
     * THE COURSE IS RESOLVED THROUGH THE SOFT-DELETE GLOBAL SCOPE, so a
     * trashed course is a 404 here just as it is everywhere else — an admin
     * cannot enroll a student into a course that has been deleted, and does
     * not get a foreign-key 500 in place of the answer.
     */
    private function grant(string $studentId, string $courseId): Subscription
    {
        $course = Course::query()->whereKey($courseId)->first()
            ?? throw new NotFoundException(self::COURSE_NOT_FOUND);

        $price = CoursePricing::amountPaid($course);

        try {
            /*
             * MULTI-TABLE WRITE, THEREFORE TRANSACTIONAL — and with NO retry
             * count, for the same reason as redemption: a deadlock retry after
             * a partially applied grant could write the ledger twice. A
             * granted enrollment whose revenue row failed would silently lose
             * the instructor's money; a revenue row without a subscription
             * would charge for access nobody received.
             */
            $result = DB::transaction(function () use ($studentId, $course, $price): array {
                $activation = ($this->activate)($studentId, $course->getKey());

                if ($activation['was_created'] && $price > 0) {
                    ($this->record)([
                        'subscription_id' => $activation['row']->getKey(),
                        'student_id' => $studentId,
                        'course_id' => $course->getKey(),
                        'teacher_id' => $course->teacher_id,
                        'gross_amount' => $price,
                        'payment_method' => RevenueTransaction::METHOD_MANUAL_ADMIN,
                        'status' => RevenueTransaction::STATUS_PAID,
                    ]);
                }

                return $activation;
            });
        } catch (Throwable $e) {
            SecurityLog::error('Enrollment grant failed and was rolled back', [
                'event' => 'subscription.activate_failure',
                'student_id' => $studentId,
                'course_id' => $courseId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        SecurityLog::criticalOperation('Subscription activated', [
            'event' => 'subscription.activated',
            'subscription_id' => $result['row']->getKey(),
            'student_id' => $studentId,
            'course_id' => $courseId,
            'was_created' => $result['was_created'],
            'price' => $price,
        ]);

        return $result['row'];
    }
}
