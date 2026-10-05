<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\SubscriptionStatus;
use App\Models\RevenueTransaction;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * PUT /api/v1/admin/enrollments/toggle — freeze-table row 54.
 *
 * The two directions are asymmetric (see App\Actions\Enrollment\
 * ToggleEnrollment), so they are tested as two behaviours rather than as one
 * parametrised "set status".
 */
final class ToggleEnrollmentTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    public function test_granting_creates_the_subscription_and_records_a_manual_admin_sale(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 180]);
        [$student] = $this->makeStudentWithCode();

        $this->loginAsAdmin();

        $this->putJson('/api/v1/admin/enrollments/toggle', [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) $course->getKey(),
            'isActive' => true,
        ])
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم تحديث حالة الاشتراك')
            ->assertJsonPath('data.enrollment.status', 'active')
            ->assertJsonPath('data.enrollment.student_id', (string) $student->getKey());

        $transaction = RevenueTransaction::query()->sole();
        // THE STRING MATTERS: it is what distinguishes this sale from a code
        // redemption everywhere downstream.
        self::assertSame(RevenueTransaction::METHOD_MANUAL_ADMIN, $transaction->payment_method);
        self::assertSame('180.00', (string) $transaction->gross_amount);
        self::assertSame('126.00', (string) $transaction->teacher_earnings); // 70% default
    }

    public function test_cancelling_flips_the_status_and_touches_no_money(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 180]);
        [$student] = $this->makeStudentWithCode();
        $this->makeSubscription($student, $course);

        $this->loginAsAdmin();

        $this->putJson('/api/v1/admin/enrollments/toggle', [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) $course->getKey(),
            'isActive' => false,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.enrollment.status', 'cancelled');

        self::assertSame(
            SubscriptionStatus::Cancelled,
            Subscription::query()->sole()->status
        );
        self::assertSame(0, RevenueTransaction::query()->count());
    }

    /** Cancelling something that was never granted is a 404, never a no-op 200. */
    public function test_cancelling_a_nonexistent_enrolment_is_404_with_the_contract_string(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        [$student] = $this->makeStudentWithCode();

        $this->loginAsAdmin();

        $this->putJson('/api/v1/admin/enrollments/toggle', [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) $course->getKey(),
            'isActive' => false,
        ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'لا يوجد اشتراك لهذا الطالب في هذه الدورة');

        // The 404 branch must not create the row it just failed to find.
        self::assertSame(0, Subscription::query()->count());
    }

    public function test_granting_into_an_unknown_course_is_404(): void
    {
        $this->disableRateLimiting();
        [$student] = $this->makeStudentWithCode();

        $this->loginAsAdmin();

        $this->putJson('/api/v1/admin/enrollments/toggle', [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) Str::uuid7(),
            'isActive' => true,
        ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'الدورة غير موجودة');
    }

    public function test_granting_into_a_soft_deleted_course_is_404(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        $course->delete();
        [$student] = $this->makeStudentWithCode();

        $this->loginAsAdmin();

        $this->putJson('/api/v1/admin/enrollments/toggle', [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) $course->getKey(),
            'isActive' => true,
        ])->assertStatus(404);

        self::assertSame(0, Subscription::query()->count());
    }

    /**
     * THE OFF-AND-ON-AGAIN CASE. An admin toggling a subscription off and back
     * on while sorting out a support ticket must not mint a second sale.
     */
    public function test_toggling_off_and_on_again_records_exactly_one_sale(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 90]);
        [$student] = $this->makeStudentWithCode();

        $this->loginAsAdmin();

        $body = fn (bool $active): array => [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) $course->getKey(),
            'isActive' => $active,
        ];

        $this->putJson('/api/v1/admin/enrollments/toggle', $body(true))->assertStatus(200);
        $this->putJson('/api/v1/admin/enrollments/toggle', $body(false))->assertStatus(200);
        $this->putJson('/api/v1/admin/enrollments/toggle', $body(true))->assertStatus(200);

        self::assertSame(1, Subscription::query()->count());
        self::assertSame(SubscriptionStatus::Active, Subscription::query()->sole()->status);
        self::assertSame(1, RevenueTransaction::query()->count());
    }

    /**
     * A REVIVAL CLEARS A STALE `expires_at`. Without that, the status would
     * say 'active' while Subscription::scopeActive() still refused the
     * student — a grant that appears to have worked and did nothing.
     */
    public function test_a_revival_clears_a_stale_expiry(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 0]);
        [$student] = $this->makeStudentWithCode();
        $this->makeSubscription(
            $student,
            $course,
            SubscriptionStatus::Cancelled,
            now()->subYear()
        );

        $this->loginAsAdmin();

        $this->putJson('/api/v1/admin/enrollments/toggle', [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) $course->getKey(),
            'isActive' => true,
        ])->assertStatus(200);

        $subscription = Subscription::query()->sole();
        self::assertNull($subscription->expires_at);
        self::assertSame(1, Subscription::query()->active()->count());
    }

    /** Free course, manual grant: subscription yes, ledger row no. */
    public function test_granting_a_free_course_records_no_revenue(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 0]);
        [$student] = $this->makeStudentWithCode();

        $this->loginAsAdmin();

        $this->putJson('/api/v1/admin/enrollments/toggle', [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) $course->getKey(),
            'isActive' => true,
        ])->assertStatus(200);

        self::assertSame(1, Subscription::query()->count());
        self::assertSame(0, RevenueTransaction::query()->count());
    }

    /**
     * ┌── THE 500-TO-422 CORRECTION. ───────────────────────────────────────┐
     * │ backend/ read `$data['studentId']` with no null-coalescing, so a    │
     * │ missing field was an "Undefined array key" warning and a 500. Three │
     * │ fields, three 422s now. No request that previously SUCCEEDED        │
     * │ behaves differently.                                                │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_missing_field_is_422_rather_than_the_legacy_500(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        [$student] = $this->makeStudentWithCode();

        $this->loginAsAdmin();

        $full = [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) $course->getKey(),
            'isActive' => true,
        ];

        foreach (['studentId', 'courseId', 'isActive'] as $missing) {
            $body = $full;
            unset($body[$missing]);

            $this->putJson('/api/v1/admin/enrollments/toggle', $body)
                ->assertStatus(422)
                ->assertJsonStructure(['data' => ['errors' => [$missing]]]);
        }
    }

    /**
     * THE STRING "false" IS A 422, NOT A SILENT GRANT.
     *
     * `(bool) "false"` is TRUE in PHP, which is what backend/ evaluated. A
     * client that form-encodes its booleans would therefore have GRANTED
     * access — and recorded a sale — every time it meant to revoke it. The
     * `boolean` rule refuses the value instead.
     */
    public function test_the_string_false_is_refused_rather_than_read_as_true(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 500]);
        [$student] = $this->makeStudentWithCode();

        $this->loginAsAdmin();

        $this->putJson('/api/v1/admin/enrollments/toggle', [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) $course->getKey(),
            'isActive' => 'false',
        ])->assertStatus(422);

        self::assertSame(0, Subscription::query()->count());
        self::assertSame(0, RevenueTransaction::query()->count());
    }

    /** The `0`/`1` spellings a query-string or form client sends DO work. */
    public function test_the_integer_and_string_boolean_spellings_are_accepted(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 0]);
        [$student] = $this->makeStudentWithCode();

        $this->loginAsAdmin();

        $this->putJson('/api/v1/admin/enrollments/toggle', [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) $course->getKey(),
            'isActive' => 1,
        ])->assertStatus(200);

        $this->putJson('/api/v1/admin/enrollments/toggle', [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) $course->getKey(),
            'isActive' => '0',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.enrollment.status', 'cancelled');
    }
}
