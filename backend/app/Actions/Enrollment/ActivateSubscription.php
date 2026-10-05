<?php

declare(strict_types=1);

namespace App\Actions\Enrollment;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * `createOrActivate` — the shared half of the two enrollment paths.
 *
 * Enrollment-code redemption and the admin's manual grant both need exactly
 * this: make (student, course) active, and report whether that was a BRAND NEW
 * enrollment or the revival of an old one.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ `was_created` IS A MONEY FLAG, NOT A CONVENIENCE.                        ║
 * ║                                                                          ║
 * ║ It is the sole gate on recording a revenue transaction. Reactivating a   ║
 * ║ previously CANCELLED subscription must NOT charge again — the student    ║
 * ║ already paid for this course once, and an admin flipping the enrollment  ║
 * ║ toggle off and on again while sorting out a support ticket would         ║
 * ║ otherwise mint a new sale on every flip. backend/                        ║
 * ║ SubscriptionRepository::createOrActivate() states this in its own        ║
 * ║ docblock ("callers use this to decide whether a revenue transaction      ║
 * ║ must be recorded — a re-activation must not charge the student twice")   ║
 * ║ and the reasoning is preserved here verbatim in intent.                  ║
 * ║                                                                          ║
 * ║ The corollary is easy to miss and worth stating: a cancelled student who ║
 * ║ is re-granted access gets it for FREE, forever, by design. There is no   ║
 * ║ "re-purchase" path in this product.                                      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── WHY `updateOrCreate` + `wasRecentlyCreated`, AND NOT A HAND-ROLLED     ┐
 * │   read-then-branch.                                                      │
 * │                                                                          │
 * │ backend/ INFERS its flag: it inserts with a fresh uuid, re-reads the row │
 * │ and compares `$row['id'] === $id` — true means the INSERT landed, false  │
 * │ means ON DUPLICATE KEY UPDATE hit an existing row. That is a correct but │
 * │ indirect reconstruction of something Eloquent already tracks, and it     │
 * │ costs an extra SELECT.                                                   │
 * │                                                                          │
 * │ `wasRecentlyCreated` is the first-party equivalent and it is set by the  │
 * │ same event that performed the insert, so it cannot disagree with what    │
 * │ happened. Do not reimplement the inference.                              │
 * │                                                                          │
 * │ IT IS ALSO RACE-SAFE, WHICH THE OBVIOUS `if (exists) … else insert` IS   │
 * │ NOT. In Laravel 12 `updateOrCreate` delegates to `firstOrCreate` ->      │
 * │ `createOrFirst`, which attempts the INSERT inside a SAVEPOINT and, on a  │
 * │ UniqueConstraintViolationException, re-reads the row the winner just     │
 * │ committed. Two concurrent grants for the same (student, course) both     │
 * │ therefore terminate: one with was_created=true, the other with           │
 * │ was_created=false — one subscription, one revenue row. The savepoint is  │
 * │ what keeps the loser's surrounding transaction usable; without it, a     │
 * │ duplicate-key error inside DB::transaction() would poison the whole      │
 * │ enclosing statement batch. Proven by                                     │
 * │ tests/Concurrency/enrollment_toggle_race.php.                            │
 * │                                                                          │
 * │ UNIQUE KEY uq_subscription_student_course (student_id, course_id) is     │
 * │ what makes all of that true, and it was verified live before this class  │
 * │ was written — including a real duplicate INSERT that the DATABASE        │
 * │ rejected, not merely a `SHOW CREATE TABLE` that listed the constraint.   │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `expires_at => null` IS WRITTEN EXPLICITLY, on create and on revive. It
 * means "no expiry", which Subscription::scopeActive() reads as permanent
 * access — not as "expired". Reviving a row that carried a stale past expiry
 * without clearing it would flip the status to active while scopeActive()
 * still refused the student, i.e. a grant that appears to have worked and did
 * nothing.
 */
final class ActivateSubscription
{
    /**
     * @return array{row: Subscription, was_created: bool}
     */
    public function __invoke(string $studentId, string $courseId): array
    {
        $this->serialiseOnTheCourse($courseId);

        $keys = ['student_id' => $studentId, 'course_id' => $courseId];
        $values = ['status' => SubscriptionStatus::Active, 'expires_at' => null];

        try {
            $subscription = Subscription::query()->updateOrCreate($keys, $values);
            $wasCreated = $subscription->wasRecentlyCreated;
        } catch (UniqueConstraintViolationException) {
            $subscription = $this->recoverFromLostRace($keys, $values);
            $wasCreated = false;
        }

        /*
         * `subscriptions.created_at` is DATETIME NOT NULL DEFAULT
         * CURRENT_TIMESTAMP and nothing in PHP writes it, so on a fresh INSERT
         * the in-memory model has NO value for it — the database filled the
         * column after the model was built. The redemption response ships this
         * row to the client, and `subscribed_at: null` on a subscription that
         * demonstrably exists is a wrong answer, not a missing one.
         *
         * backend/ solved the same problem by re-reading the row
         * (`findByStudentAndCourse` after the write); refresh() is that read.
         *
         * IT IS TAKEN **AFTER** `wasRecentlyCreated` IS CAPTURED. refresh()
         * does not reset the flag today, but the flag is a money gate and
         * ordering it defensively costs nothing.
         */
        $subscription->refresh();

        return [
            'row' => $subscription,
            'was_created' => $wasCreated,
        ];
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE STAGE 6 PATTERN, AND HERE IT IS THE RIGHT ONE — UNLIKE IN        ║
     * ║ RedeemEnrollmentCode, WHICH DELIBERATELY REFUSES IT.                 ║
     * ║                                                                      ║
     * ║ Read the two banners together; they are the same decision procedure  ║
     * ║ producing opposite answers, because the questions differ:            ║
     * ║                                                                      ║
     * ║   "is this code still unused?"     — a property of a row that ALWAYS ║
     * ║       (RedeemEnrollmentCode)         EXISTS, so a conditional UPDATE ║
     * ║                                      locks it as part of its own     ║
     * ║                                      execution. No separate lock.    ║
     * ║                                                                      ║
     * ║   "does an enrolment already exist?" — the answer can legitimately   ║
     * ║       (HERE)                          be ZERO ROWS. There is nothing ║
     * ║                                       to lock, so the lock must be   ║
     * ║                                       taken on a STABLE PARENT row.  ║
     * ║                                                                      ║
     * ║ Stage 6 locked `exams` to count attempts. This locks `courses` to    ║
     * ║ decide create-or-revive. Same shape, same reason.                    ║
     * ║                                                                      ║
     * ║ ┌── WHY THE OBVIOUS ALTERNATIVES WERE MEASURED AND REJECTED. ──────┐ ║
     * ║ │ `updateOrCreate` ALONE looks sufficient — `createOrFirst` catches │ ║
     * ║ │ the unique violation and re-reads. It works outside a transaction │ ║
     * ║ │ and FAILS INSIDE ONE: MySQL's default isolation is REPEATABLE     │ ║
     * ║ │ READ, so the loser's consistent snapshot predates the winner's    │ ║
     * ║ │ commit and its non-locking SELECT cannot see the row that just    │ ║
     * ║ │ rejected its INSERT. Laravel's `?? throw $e` then rethrows.       │ ║
     * ║ │ MEASURED: the first run of enrollment_toggle_race.php ended with  │ ║
     * ║ │ one grant and SEVEN UniqueConstraintViolationExceptions — the     │ ║
     * ║ │ money was right, seven admins got a 500.                          │ ║
     * ║ │                                                                   │ ║
     * ║ │ RECOVERING WITH `lockForUpdate()` ON `subscriptions` was the next  │ ║
     * ║ │ attempt and was WORSE: a locking read over a key range that seven │ ║
     * ║ │ failed inserts still hold insert-intention locks on produced      │ ║
     * ║ │ SIX DEADLOCKS and one ModelNotFound. Also measured.                │ ║
     * ║ │                                                                   │ ║
     * ║ │ Locking the parent FIRST avoids both: the losers block on the     │ ║
     * ║ │ course row before touching `subscriptions` at all, and their      │ ║
     * ║ │ consistent snapshot is therefore established AFTER the winner     │ ║
     * ║ │ commits — so the ordinary read inside `firstOrCreate` sees the    │ ║
     * ║ │ row and reports `wasRecentlyCreated = false`. One lock, one       │ ║
     * ║ │ acquisition order, no deadlock cycle possible.                    │ ║
     * ║ └───────────────────────────────────────────────────────────────────┘ ║
     * ║                                                                      ║
     * ║ THE COST IS REAL AND ACCEPTED: concurrent enrolments into the SAME   ║
     * ║ course serialise. Enrolment is a low-frequency write (a code         ║
     * ║ redemption or an admin click), and the alternative is charging a     ║
     * ║ student twice.                                                       ║
     * ║                                                                      ║
     * ║ THIS ACTION IS THEREFORE ONLY CORRECT INSIDE A TRANSACTION. A row    ║
     * ║ lock taken in autocommit mode is released by the next statement, so  ║
     * ║ it would protect nothing. Both callers open one; nothing else may    ║
     * ║ invoke this class without doing the same.                            ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * The query builder is used rather than the Course MODEL on purpose:
     * SoftDeletes' global scope would add `deleted_at IS NULL` and a trashed
     * course would silently take NO lock at all. Whether the course may be
     * enrolled into is the CALLER's question (both callers 404 first); this
     * one only needs the row to exist as a mutex.
     */
    private function serialiseOnTheCourse(string $courseId): void
    {
        DB::table('courses')->where('id', $courseId)->lockForUpdate()->first();
    }

    /**
     * BELT AND BRACES. With the parent lock held this branch is unreachable,
     * and it is kept anyway: `serialiseOnTheCourse()` is one line that a
     * future refactor could remove without any test failing loudly, whereas
     * losing BOTH protections would mean a duplicate charge. The recovery is
     * a plain non-locking re-read — by the time control reaches here the
     * winner has committed and this transaction's snapshot is not the problem
     * that the parent lock already solved.
     *
     * @param  array<string, string>  $keys
     * @param  array<string, mixed>  $values
     */
    private function recoverFromLostRace(array $keys, array $values): Subscription
    {
        /** @var Subscription $subscription */
        $subscription = Subscription::query()->where($keys)->firstOrFail();

        $subscription->fill($values)->save();

        return $subscription;
    }
}
