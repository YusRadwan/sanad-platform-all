<?php

declare(strict_types=1);

namespace App\Actions\Enrollment;

use App\Actions\Revenue\RecordRevenueTransaction;
use App\Exceptions\NotFoundException;
use App\Models\Course;
use App\Models\EnrollmentCode;
use App\Models\RevenueTransaction;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CoursePricing;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * POST /api/v1/enrollment-codes/redeem — freeze-table row 60.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ NOTHING THE CALLER SENDS NAMES A COURSE OR A STUDENT.                    ║
 * ║                                                                          ║
 * ║ The course comes from the CODE'S OWN ROW; the student comes from the     ║
 * ║ authenticated session. A `course_id` in the request body has no code     ║
 * ║ path that could observe it — which is a stronger guarantee than          ║
 * ║ validating and rejecting one, because there is nothing to forget to      ║
 * ║ validate. Same construction backend/EnrollmentCodeService::redeem() used ║
 * ║ and the freeze table pins ("لا الدورة ولا الطالب يأتيان من الجسم").      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ WHY A GUARDED `UPDATE`, AND **NOT** `lockForUpdate()` — DELIBERATELY     ║
 * ║ DIFFERENT FROM STAGE 6'S PATTERN.                                        ║
 * ║                                                                          ║
 * ║ Stage 6 (SubmitExamAttempt) locks the PARENT `exams` row with            ║
 * ║ `lockForUpdate()` and then counts attempts. That is right THERE and      ║
 * ║ wrong HERE, and the difference is what the exclusivity check ranges      ║
 * ║ over:                                                                    ║
 * ║                                                                          ║
 * ║   Stage 6   "how many attempt rows exist?"  — the answer can legitimately║
 * ║             be ZERO ROWS. There is nothing to lock: you cannot take a    ║
 * ║             row lock on rows that do not exist yet, and a gap lock is    ║
 * ║             an isolation-level-dependent accident rather than a design.  ║
 * ║             So the lock must be taken on a STABLE row that always        ║
 * ║             exists — the exam — turning "count then insert" into a       ║
 * ║             serialised section.                                          ║
 * ║                                                                          ║
 * ║   HERE      "is this code still unused?" — a property of a row that      ║
 * ║             ALWAYS EXISTS: the code itself. A conditional                ║
 * ║             `UPDATE … WHERE is_used = 0` takes an exclusive lock on      ║
 * ║             exactly that row as part of its own execution and reports,   ║
 * ║             in its affected-row count, whether it was the statement      ║
 * ║             that flipped it. There is no window between the check and    ║
 * ║             the write because THEY ARE THE SAME STATEMENT.               ║
 * ║                                                                          ║
 * ║ Adding `SELECT … FOR UPDATE` in front of it would be neither safer nor   ║
 * ║ more explicit — just one more network round-trip and one more place for  ║
 * ║ a future reader to assume the UPDATE's own guard is redundant and drop   ║
 * ║ it. The two patterns are not competing styles; they answer structurally  ║
 * ║ different questions.                                                     ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── `DB::transaction($closure)` WITH NO RETRY COUNT. SAME RULE AS STAGE 6. ┐
 * │ Laravel's optional second argument re-runs the closure after a deadlock. │
 * │ On this closure that is unsafe: the claim may already have COMMITTED     │
 * │ some of its effects in a partial state on a retry path, and a second     │
 * │ pass would re-enter activateSubscription/recordRevenue and could         │
 * │ double-write the ledger. A deadlock here must surface as an error the    │
 * │ student can retry BY HAND — a 500 is recoverable, a duplicate charge is  │
 * │ not.                                                                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class RedeemEnrollmentCode
{
    public const CODE_NOT_FOUND = 'هذا الكود غير صحيح';

    public const ALREADY_USED = 'هذا الكود تم استخدامه من قبل';

    public const COURSE_GONE = 'الدورة المرتبطة بهذا الكود لم تعد متاحة';

    public const ALREADY_SUBSCRIBED = 'أنت مشترك بالفعل في هذه الدورة';

    public function __construct(
        private readonly ActivateSubscription $activate,
        private readonly RecordRevenueTransaction $record,
    ) {
    }

    /**
     * @return array{course_id: string, course_title: string|null, subscription: Subscription}
     */
    public function __invoke(string $code, User $student): array
    {
        [$row, $course] = $this->resolve($code, $student);

        /*
         * The price ACTUALLY PAID — see App\Support\CoursePricing for why the
         * discount column wins and why `??` is the only correct operator.
         * Read BEFORE the transaction opens, from the same course row the
         * checks above used, so the amount charged is the amount the student
         * was shown.
         */
        $price = CoursePricing::amountPaid($course);

        try {
            $result = DB::transaction(function () use ($row, $course, $student, $price): array {
                /*
                 * CLAIM FIRST. If another request already took this code, the
                 * UPDATE matches zero rows and NOTHING else in this closure
                 * runs — no subscription, no money.
                 *
                 * THE QUERY-BUILDER FORM IS MANDATORY. `$row->update([...])`
                 * on the loaded model would (a) drop the `is_used = 0` guard,
                 * because Eloquent addresses the row by primary key alone, and
                 * (b) return a BOOLEAN rather than an affected-row count — so
                 * the loser of a race would see `true` and go on to grant
                 * access on a code somebody else had already spent.
                 */
                $claimed = EnrollmentCode::query()
                    ->whereKey($row->getKey())
                    ->where('is_used', 0)
                    ->update([
                        'is_used' => 1,
                        'used_by_student_id' => $student->getKey(),
                        'used_at' => now(),
                    ]);

                if ($claimed !== 1) {
                    throw ValidationException::withMessages(['code' => [self::ALREADY_USED]]);
                }

                $activation = ($this->activate)($student->getKey(), $course->getKey());

                /*
                 * Money is recorded ONLY for a genuinely new PAID enrollment.
                 * `was_created` is the gate — see ActivateSubscription's
                 * banner for why a revival must not charge again — and
                 * `$price > 0` keeps free courses out of the ledger entirely
                 * rather than filling it with 0.00 rows that every report
                 * would then have to remember to exclude.
                 */
                if ($activation['was_created'] && $price > 0) {
                    ($this->record)([
                        'subscription_id' => $activation['row']->getKey(),
                        'student_id' => $student->getKey(),
                        'course_id' => $course->getKey(),
                        'teacher_id' => $course->teacher_id,
                        'gross_amount' => $price,
                        'payment_method' => RevenueTransaction::METHOD_ENROLLMENT_CODE,
                        'status' => RevenueTransaction::STATUS_PAID,
                    ]);
                }

                return $activation;
            });
        } catch (Throwable $e) {
            SecurityLog::error('Enrollment code redemption failed and was rolled back', [
                'event' => 'enrollment_code.redeem_failure',
                'code_id' => $row->getKey(),
                'course_id' => $course->getKey(),
                'student_id' => $student->getKey(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        SecurityLog::criticalOperation('Enrollment code redeemed', [
            'event' => 'enrollment_code.redeemed',
            'code_id' => $row->getKey(),
            'course_id' => $course->getKey(),
            'student_id' => $student->getKey(),
            'was_created' => $result['was_created'],
            'price' => $price,
        ]);

        return [
            'course_id' => (string) $course->getKey(),
            'course_title' => $course->title,
            'subscription' => $result['row'],
        ];
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ FOUR PRE-TRANSACTION CHECKS. THEY RUN **BEFORE** THE TRANSACTION     ║
     * ║ OPENS AND THEY DO NOT REPLACE THE GUARD INSIDE IT.                   ║
     * ║                                                                      ║
     * ║ The "already used" test appears twice on purpose, and the pair is    ║
     * ║ not redundant:                                                       ║
     * ║                                                                      ║
     * ║   HERE   a COURTESY check. It gives the overwhelmingly common case   ║
     * ║          (a student re-submitting a code they already redeemed) a    ║
     * ║          fast, friendly 422 without opening a write transaction.     ║
     * ║          It is racy, and that is fine — it is not load-bearing.      ║
     * ║                                                                      ║
     * ║   THE `WHERE is_used = 0` INSIDE __invoke's TRANSACTION              ║
     * ║          the CORRECTNESS guarantee under concurrency. It is the only ║
     * ║          one of the two that a race can be decided by.               ║
     * ║                                                                      ║
     * ║ Deleting the pre-check would cost only friendliness; deleting the    ║
     * ║ guard would let eight simultaneous redemptions all succeed. Anyone   ║
     * ║ tempted to remove "the duplicate" must remove the first one.         ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * `$code->course` RESOLVES THROUGH Course's SoftDeletes GLOBAL SCOPE, so a
     * soft-deleted course comes back NULL and gets its own 404 string. The FK
     * only cascades on a HARD delete, so the code row happily outlives the
     * course it points at — which is exactly the state this branch names.
     *
     * @return array{0: EnrollmentCode, 1: Course}
     */
    private function resolve(string $code, User $student): array
    {
        $row = EnrollmentCode::query()->where('code', $code)->first()
            ?? throw new NotFoundException(self::CODE_NOT_FOUND);

        if ($row->is_used) {
            throw ValidationException::withMessages(['code' => [self::ALREADY_USED]]);
        }

        $course = $row->course;

        if ($course === null) {
            throw new NotFoundException(self::COURSE_GONE);
        }

        /*
         * A genuine courtesy rule, not a safety one: without it a student who
         * already owns the course would BURN a perfectly good code on it and
         * get nothing (the revenue gate would decline to charge, the
         * subscription would already be active). ->active() is the single
         * canonical definition of "subscribed"; the predicate is never
         * re-typed here.
         */
        $alreadyIn = Subscription::query()
            ->where('student_id', $student->getKey())
            ->where('course_id', $course->getKey())
            ->active()
            ->exists();

        if ($alreadyIn) {
            throw ValidationException::withMessages(['code' => [self::ALREADY_SUBSCRIBED]]);
        }

        return [$row, $course];
    }
}
