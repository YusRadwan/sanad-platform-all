<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\SubscriptionStatus;
use App\Models\EnrollmentCode;
use App\Models\RevenueTransaction;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * POST /api/v1/enrollment-codes/redeem — freeze-table row 60.
 *
 * ┌── WHAT THIS FILE COVERS AND WHAT IT DELIBERATELY DOES NOT. ──────────────┐
 * │ Everything here is SEQUENTIAL. The single-use guarantee under genuine    │
 * │ concurrency cannot be tested from PHPUnit at all — the harness is        │
 * │ single-threaded, so eight "simultaneous" redemptions in a test method    │
 * │ are simply eight consecutive ones and a completely unguarded             │
 * │ implementation passes. That proof lives in                               │
 * │ tests/Concurrency/enrollment_code_race.php, which spawns eight real OS   │
 * │ processes AND runs a deliberately naive control that must FAIL.          │
 * │                                                                          │
 * │ What IS provable here is everything either side of the race: the four    │
 * │ pre-transaction refusals, the exact side effects of one success, and     │
 * │ the fact that each refusal leaves the code SPENDABLE.                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class EnrollmentCodeRedemptionTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    public function test_a_valid_code_enrolls_the_student_and_records_the_sale(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 200, 'title' => 'كورس الفيزياء']);
        $code = $this->makeEnrollmentCode($course);
        [$student] = $this->makeStudentWithCode();

        $this->loginAs($student);

        $response = $this->postJson('/api/v1/enrollment-codes/redeem', ['code' => $code->code]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'تم تفعيل اشتراكك في الدورة بنجاح')
            ->assertJsonPath('data.course_id', (string) $course->getKey())
            ->assertJsonPath('data.course_title', 'كورس الفيزياء')
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.student_id', (string) $student->getKey());

        // `subscribed_at` was NULL until ActivateSubscription learned to
        // refresh() — the column is a DB default nothing in PHP writes.
        self::assertNotNull($response->json('data.subscription.created_at'));

        $subscription = Subscription::query()
            ->where('student_id', $student->getKey())
            ->where('course_id', $course->getKey())
            ->sole();

        self::assertSame(SubscriptionStatus::Active, $subscription->status);
        self::assertNull($subscription->expires_at);

        $code->refresh();
        self::assertTrue($code->is_used);
        self::assertSame((string) $student->getKey(), (string) $code->used_by_student_id);
        self::assertNotNull($code->used_at);

        $transaction = RevenueTransaction::query()->sole();
        self::assertSame((string) $subscription->getKey(), (string) $transaction->subscription_id);
        self::assertSame('200.00', (string) $transaction->gross_amount);
        self::assertSame(RevenueTransaction::METHOD_ENROLLMENT_CODE, $transaction->payment_method);
        self::assertSame('paid', $transaction->status);
    }

    public function test_a_used_code_is_422_and_grants_nothing(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        [$first] = $this->makeStudentWithCode();
        $code = $this->makeEnrollmentCode($course, [
            'is_used' => true,
            'used_by_student_id' => $first->getKey(),
            'used_at' => now(),
        ]);
        [$second] = $this->makeStudentWithCode();

        $this->loginAs($second);

        $this->postJson('/api/v1/enrollment-codes/redeem', ['code' => $code->code])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.code.0', 'هذا الكود تم استخدامه من قبل');

        self::assertSame(0, Subscription::query()->count());
        self::assertSame(0, RevenueTransaction::query()->count());
        // The first student's claim is untouched.
        self::assertSame((string) $first->getKey(), (string) $code->fresh()->used_by_student_id);
    }

    public function test_an_unknown_code_is_404(): void
    {
        $this->disableRateLimiting();
        [$student] = $this->makeStudentWithCode();
        $this->loginAs($student);

        $this->postJson('/api/v1/enrollment-codes/redeem', ['code' => '99999999'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'هذا الكود غير صحيح');
    }

    /**
     * SHAPE FIRST. A malformed code is 422 and must NOT be swallowed by the
     * 404 — the two answers stay distinguishable, exactly as for the admin
     * lookup route.
     */
    public function test_a_malformed_code_is_422_before_any_lookup(): void
    {
        $this->disableRateLimiting();
        [$student] = $this->makeStudentWithCode();
        $this->loginAs($student);

        foreach (['banana', '1234567', '123456789', '', ' 1234567'] as $bad) {
            $this->postJson('/api/v1/enrollment-codes/redeem', ['code' => $bad])
                ->assertStatus(422)
                ->assertJsonPath('data.errors.code.0', 'الكود يجب أن يتكون من 8 أرقام');
        }
    }

    public function test_a_code_for_a_soft_deleted_course_is_404_with_its_own_message(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        $code = $this->makeEnrollmentCode($course);
        $course->delete();

        [$student] = $this->makeStudentWithCode();
        $this->loginAs($student);

        $this->postJson('/api/v1/enrollment-codes/redeem', ['code' => $code->code])
            ->assertStatus(404)
            ->assertJsonPath('message', 'الدورة المرتبطة بهذا الكود لم تعد متاحة');

        // The code survives the failed redemption — it is not burned.
        self::assertFalse($code->fresh()->is_used);
    }

    /**
     * THE COURTESY CHECK THAT SAVES A CODE. A student who is already enrolled
     * gets 422 and — the assertion that matters — the code is STILL UNUSED, so
     * they can spend it on the course they actually meant.
     */
    public function test_an_already_subscribed_student_is_422_and_the_code_stays_unused(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        $code = $this->makeEnrollmentCode($course);
        [$student] = $this->makeStudentWithCode();
        $this->makeSubscription($student, $course);

        $this->loginAs($student);

        $this->postJson('/api/v1/enrollment-codes/redeem', ['code' => $code->code])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.code.0', 'أنت مشترك بالفعل في هذه الدورة');

        self::assertFalse($code->fresh()->is_used);
        self::assertSame(0, RevenueTransaction::query()->count());
        self::assertSame(1, Subscription::query()->count());
    }

    /**
     * A CANCELLED subscription is NOT an active one, so the courtesy check
     * must let the redemption through — and the revival must then NOT charge
     * again, because `was_created` is false. Both halves in one test because
     * they are one behaviour seen from two sides.
     */
    public function test_a_cancelled_subscription_can_be_revived_by_a_code_without_charging_again(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 300]);
        [$student] = $this->makeStudentWithCode();
        $this->makeSubscription($student, $course, SubscriptionStatus::Cancelled);
        $code = $this->makeEnrollmentCode($course);

        $this->loginAs($student);

        $this->postJson('/api/v1/enrollment-codes/redeem', ['code' => $code->code])
            ->assertStatus(200)
            ->assertJsonPath('data.subscription.status', 'active');

        self::assertSame(1, Subscription::query()->count());
        // NO second sale. The student already paid for this course once.
        self::assertSame(0, RevenueTransaction::query()->count());
        self::assertTrue($code->fresh()->is_used);
    }

    /** A free course produces NO ledger row at all — not a 0.00 one. */
    public function test_a_free_course_records_no_revenue_row(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 0]);
        $code = $this->makeEnrollmentCode($course);
        [$student] = $this->makeStudentWithCode();

        $this->loginAs($student);

        $this->postJson('/api/v1/enrollment-codes/redeem', ['code' => $code->code])
            ->assertStatus(200);

        self::assertSame(1, Subscription::query()->count());
        self::assertSame(0, RevenueTransaction::query()->count());
    }

    /**
     * A `course_id` IN THE BODY HAS NO READER. The redemption lands on the
     * code's OWN course, not on the one the caller named — which is the whole
     * security property of this endpoint.
     */
    public function test_a_supplied_course_id_in_the_body_is_ignored_entirely(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $bound = $this->makeCourseFor($instructor, ['price' => 50, 'title' => 'الكورس المرتبط']);
        $other = $this->makeCourseFor($instructor, ['price' => 5000, 'title' => 'كورس آخر']);
        $code = $this->makeEnrollmentCode($bound);
        [$student] = $this->makeStudentWithCode();

        $this->loginAs($student);

        $this->postJson('/api/v1/enrollment-codes/redeem', [
            'code' => $code->code,
            'course_id' => (string) $other->getKey(),
            'courseId' => (string) $other->getKey(),
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.course_id', (string) $bound->getKey());

        self::assertSame(
            (string) $bound->getKey(),
            (string) Subscription::query()->sole()->course_id
        );
        self::assertSame('50.00', (string) RevenueTransaction::query()->sole()->gross_amount);
    }

    /**
     * THE STUDENT IS THE SESSION, NEVER THE BODY. A `student_id` naming
     * someone else enrolls the CALLER, not the named victim.
     */
    public function test_a_supplied_student_id_cannot_enroll_somebody_else(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 10]);
        $code = $this->makeEnrollmentCode($course);
        [$caller] = $this->makeStudentWithCode();
        [$victim] = $this->makeStudentWithCode();

        $this->loginAs($caller);

        $this->postJson('/api/v1/enrollment-codes/redeem', [
            'code' => $code->code,
            'student_id' => (string) $victim->getKey(),
            'studentId' => (string) $victim->getKey(),
        ])->assertStatus(200);

        self::assertSame(
            (string) $caller->getKey(),
            (string) Subscription::query()->sole()->student_id
        );
        self::assertSame(0, Subscription::query()->where('student_id', $victim->getKey())->count());
    }

    /** The discount is what was paid — the catalogue price is not. */
    public function test_the_discounted_price_is_what_reaches_the_ledger(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 400, 'discount_price' => 250]);
        $code = $this->makeEnrollmentCode($course);
        [$student] = $this->makeStudentWithCode();

        $this->loginAs($student);

        $this->postJson('/api/v1/enrollment-codes/redeem', ['code' => $code->code])
            ->assertStatus(200);

        self::assertSame('250.00', (string) RevenueTransaction::query()->sole()->gross_amount);
    }

    /**
     * A course discounted to FREE records nothing — `discount_price = 0` is a
     * real discount, not an absent one. This is the case a `?:` in
     * App\Support\CoursePricing would get wrong by charging 400.
     */
    public function test_a_course_discounted_to_zero_records_no_revenue(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 400, 'discount_price' => 0]);
        $code = $this->makeEnrollmentCode($course);
        [$student] = $this->makeStudentWithCode();

        $this->loginAs($student);

        $this->postJson('/api/v1/enrollment-codes/redeem', ['code' => $code->code])
            ->assertStatus(200);

        self::assertSame(1, Subscription::query()->count());
        self::assertSame(0, RevenueTransaction::query()->count());
    }

    /** Nothing is left half-applied when the code cannot be claimed. */
    public function test_a_refused_redemption_writes_absolutely_nothing(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 100]);
        $code = $this->makeEnrollmentCode($course, ['is_used' => true, 'used_at' => now()]);
        [$student] = $this->makeStudentWithCode();

        $this->loginAs($student);

        $before = [
            Subscription::query()->count(),
            RevenueTransaction::query()->count(),
            EnrollmentCode::query()->where('is_used', true)->count(),
        ];

        $this->postJson('/api/v1/enrollment-codes/redeem', ['code' => $code->code])
            ->assertStatus(422);

        self::assertSame($before, [
            Subscription::query()->count(),
            RevenueTransaction::query()->count(),
            EnrollmentCode::query()->where('is_used', true)->count(),
        ]);
    }
}
