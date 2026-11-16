<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\SubscriptionStatus;
use App\Models\RevenueTransaction;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

final class ManualActivationTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    public function test_admin_can_grant_lifetime_access_without_creating_revenue(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 500]);
        [$student] = $this->makeStudentWithCode();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/admin/manual-activations', [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) $course->getKey(),
            'accessType' => 'lifetime',
        ])->assertOk()->assertJsonPath('message', 'تم تفعيل الكورس للطالب بنجاح دون تسجيل إيرادات');

        $subscription = Subscription::query()->sole();
        self::assertSame(SubscriptionStatus::Active, $subscription->status);
        self::assertNull($subscription->expires_at);
        self::assertSame(1, Subscription::query()->active()->count());
        self::assertSame(0, RevenueTransaction::query()->count());
    }

    public function test_admin_can_grant_access_until_the_selected_calendar_date(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 500]);
        [$student] = $this->makeStudentWithCode();
        $this->loginAsAdmin();

        $this->postJson('/api/v1/admin/manual-activations', [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) $course->getKey(),
            'accessType' => 'limited',
            'expiresAt' => now()->addDays(10)->toDateString(),
        ])->assertOk();

        $subscription = Subscription::query()->sole();
        self::assertSame(now()->addDays(10)->toDateString(), $subscription->expires_at->toDateString());
        self::assertSame('23:59:59', $subscription->expires_at->format('H:i:s'));
        self::assertSame(0, RevenueTransaction::query()->count());
    }

    public function test_manual_activation_revives_existing_subscription_without_duplicate_or_revenue(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor, ['price' => 500]);
        [$student] = $this->makeStudentWithCode();
        $existing = $this->makeSubscription($student, $course, SubscriptionStatus::Cancelled, now()->subDay());
        $this->loginAsAdmin();

        $this->postJson('/api/v1/admin/manual-activations', [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) $course->getKey(),
            'accessType' => 'lifetime',
        ])->assertOk();

        self::assertSame(1, Subscription::query()->count());
        self::assertSame((string) $existing->getKey(), (string) Subscription::query()->sole()->getKey());
        self::assertNull(Subscription::query()->sole()->expires_at);
        self::assertSame(0, RevenueTransaction::query()->count());
    }

    public function test_limited_access_requires_a_valid_future_calendar_date(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        [$student] = $this->makeStudentWithCode();
        $this->loginAsAdmin();

        foreach ([null, 'not-a-date', now()->subDay()->toDateString(), now()->toDateString()] as $date) {
            $this->postJson('/api/v1/admin/manual-activations', [
                'studentId' => (string) $student->getKey(),
                'courseId' => (string) $course->getKey(),
                'accessType' => 'limited',
                'expiresAt' => $date,
            ])->assertUnprocessable();
        }

        self::assertSame(0, Subscription::query()->count());
        self::assertSame(0, RevenueTransaction::query()->count());
    }

    public function test_instructor_cannot_use_the_admin_manual_activation_endpoint(): void
    {
        $this->disableRateLimiting();
        [$instructor] = $this->makeTeacherWithProfile();
        $course = $this->makeCourseFor($instructor);
        [$student] = $this->makeStudentWithCode();
        $this->loginAs($instructor);

        $this->postJson('/api/v1/admin/manual-activations', [
            'studentId' => (string) $student->getKey(),
            'courseId' => (string) $course->getKey(),
            'accessType' => 'lifetime',
        ])->assertForbidden();

        self::assertSame(0, Subscription::query()->count());
    }
}
